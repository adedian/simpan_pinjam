<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Core\Validator;
use App\Helpers\Money;

/**
 * Pencairan pinjaman yang diajukan Ketua Regu.
 *
 * Bunga flat dibukukan di muka: bunga = pokok x tarif x tenor / 100, dibulatkan ke rupiah terdekat
 * (sama dengan rumus pemeriksa integritas di database). Angsuran = (pokok + bunga) / tenor, dibulatkan ke bawah;
 * angsuran TERAKHIR menyerap sisa pembulatan sehingga jumlah jadwal selalu persis pokok + bunga.
 * Angsuran pertama jatuh tempo di bulan siklus SETELAH bulan pencairan (seperti di Excel).
 * Tarif disalin ke pinjaman saat pengajuan; perubahan pengaturan tidak berlaku surut pada yang sudah ada.
 *
 * Alur status sama dengan simpanan (lihat TransactionFlow). Pencairan disetujui di Phase 9; di sana kas
 * diperiksa lagi secara atomik. Pemeriksaan kas di sini hanya penolakan dini saat diajukan.
 */
final class LoanService
{
    /** Batas kewajaran pokok sebelum plafon pengaturan (0 = tanpa batas plafon). */
    public const MAX_PRINCIPAL = 1000000000;

    /**
     * Hitung bunga dan jadwal. Murni bilangan bulat (tanpa float) agar tidak ada selisih satu rupiah.
     * @param int $rateHundredths tarif per bulan dalam per seratus persen (2,00% = 200)
     * @return array{interest:int,total:int,installments:array<int,int>}
     */
    public static function quote(int $principal, int $tenor, int $rateHundredths): array
    {
        if ($principal <= 0 || $tenor < 1 || $rateHundredths < 0) {
            throw new \InvalidArgumentException('Pokok, tenor, atau tarif tidak valid.');
        }
        // pokok x (rate/100 %) x tenor / 100, dibulatkan setengah ke atas: (2n + d) / 2d dengan d = 10000
        $interest = intdiv(2 * $principal * $rateHundredths * $tenor + 10000, 20000);
        $total    = $principal + $interest;
        $base     = intdiv($total, $tenor);
        $list     = array_fill(0, $tenor, $base);
        $list[$tenor - 1] = $total - $base * ($tenor - 1);
        return ['interest' => $interest, 'total' => $total, 'installments' => $list];
    }

    /** Tarif pengaturan saat ini dalam per seratus persen (mis. "2.00" -> 200). */
    public static function currentRateHundredths(): int
    {
        return (int) round(((float) SettingsService::get('interest_rate_pct_month')) * 100);
    }

    /**
     * @param array<string,mixed> $in
     * @return array{0:array<string,string>,1:array<string,mixed>} [galat, data bersih]
     */
    public static function parse(array $in): array
    {
        $description = clean_text($in['description'] ?? '');
        $raw         = trim((string) ($in['principal'] ?? ''));
        $tenorRaw    = trim((string) ($in['tenor'] ?? ''));
        $data = [
            'member_id'         => (int) ($in['member_id'] ?? 0),
            'period_month_id'   => (int) ($in['period_month_id'] ?? 0),
            'principal'         => Money::parse($raw) ?? 0,
            'tenor'             => preg_match('/^\d{1,2}$/', $tenorRaw) === 1 ? (int) $tenorRaw : 0,
            'trx_date'          => trim((string) ($in['trx_date'] ?? '')),
            'description'       => $description === '' ? null : $description,
            'confirm_duplicate' => !empty($in['confirm_duplicate']),
        ];

        $errors = Validator::validate(['description' => $description], ['description' => 'max:255'], ['description' => 'Keterangan']);
        if ($data['member_id'] <= 0) {
            $errors['member_id'] = 'Anggota wajib dipilih.';
        }
        if ($data['period_month_id'] <= 0) {
            $errors['period_month_id'] = 'Bulan pencairan wajib dipilih.';
        }
        if ($raw === '') {
            $errors['principal'] = 'Jumlah pinjaman wajib diisi.';
        } elseif (Money::parse($raw) === null) {
            $errors['principal'] = 'Jumlah pinjaman: isi dengan angka bulat rupiah tanpa desimal, mis. 5.000.000.';
        } elseif ($data['principal'] <= 0) {
            $errors['principal'] = 'Jumlah pinjaman harus lebih dari nol.';
        } elseif ($data['principal'] > self::MAX_PRINCIPAL) {
            $errors['principal'] = 'Jumlah pinjaman terlalu besar. Periksa kembali jumlah nolnya.';
        }
        if ($tenorRaw === '') {
            $errors['tenor'] = 'Tenor wajib diisi.';
        } elseif ($data['tenor'] < 1) {
            $errors['tenor'] = 'Tenor: isi dengan jumlah bulan (angka bulat).';
        }
        $date = \DateTime::createFromFormat('!Y-m-d', $data['trx_date']);
        if ($date === false || $date->format('Y-m-d') !== $data['trx_date']) {
            $errors['trx_date'] = 'Tanggal pencairan tidak valid.';
        }
        return [$errors, $data];
    }

    // ------------------------------------------------------------------ perintah

    /**
     * Catat pinjaman baru sebagai DRAFT (jadwal sudah dihitung); bila $submit, langsung diajukan.
     * @param array<string,mixed> $data hasil parse()
     * @param array{id:int,username:string,roles:array<int,string>,team_id?:?int} $actor
     * @return int id transaksi
     */
    public static function create(Request $request, array $actor, array $data, bool $submit): int
    {
        return (int) Database::transaction(static function (\PDO $pdo) use ($request, $actor, $data, $submit): int {
            $plan = self::plan($pdo, $actor, $data, null, false);
            $num  = NumberSequence::forTransaction($pdo, 'PENCAIRAN_PINJAMAN', (int) substr((string) $data['trx_date'], 0, 4));

            $pdo->prepare("INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount, status, description, source, created_by)
                           VALUES (?, ?, 'PENCAIRAN_PINJAMAN', ?, ?, ?, ?, ?, 'DRAFT', ?, 'APLIKASI', ?)")
                ->execute([$num['trx_no'], $num['doc_no'], $data['member_id'], $plan['ctx']['team_id'], $data['period_month_id'], $data['trx_date'], $data['principal'], $data['description'], $actor['id']]);
            $id = (int) $pdo->lastInsertId();
            self::writeLoan($pdo, $id, $data, $plan, true);

            AuditLog::record($request, $actor, 'LOAN_CREATED', 'transaction', $id, $num['doc_no'], null, self::snapshot($data, $plan), $pdo);
            if ($submit) {
                self::submitLocked($pdo, $request, $actor, ['id' => $id, 'doc_no' => $num['doc_no'], 'status' => 'DRAFT'], $data, $plan);
            }
            return $id;
        });
    }

    /**
     * Ubah pinjaman yang masih DRAFT (hanya pembuatnya): bunga dan jadwal dihitung ulang dengan tarif saat ini.
     * @param array<string,mixed> $data hasil parse()
     * @param array{id:int,username:string,roles:array<int,string>,team_id?:?int} $actor
     * @param string $version nilai updated_at saat formulir dibuka (deteksi edit bersamaan)
     */
    public static function update(Request $request, array $actor, int $id, array $data, string $version, bool $submit): void
    {
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $data, $version, $submit): void {
            $trx = TransactionFlow::lockOwned($pdo, $actor, $id, $version, 'PENCAIRAN_PINJAMAN');
            if ($trx['status'] !== 'DRAFT') {
                throw RuleViolation::field('_form', 'Hanya draft yang bisa diubah. Transaksi ini sudah ' . TransactionFlow::statusPhrase((string) $trx['status']) . '.');
            }
            $plan   = self::plan($pdo, $actor, $data, $id, false);
            $old    = self::loadLoan($pdo, $id);
            $before = self::snapshot(self::rowData($trx, $old), ['ctx' => ['team_id' => (int) $trx['team_id']], 'rate_h' => (int) round((float) $old['rate_pct_month'] * 100), 'quote' => ['interest' => (int) $old['total_interest']]]);
            $after  = self::snapshot($data, $plan);
            $changed = array_keys(array_filter($after, static fn ($v, $k): bool => (string) ($before[$k] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $pdo->prepare('UPDATE transactions SET member_id = ?, team_id = ?, period_month_id = ?, trx_date = ?, amount = ?, description = ? WHERE id = ?')
                    ->execute([$data['member_id'], $plan['ctx']['team_id'], $data['period_month_id'], $data['trx_date'], $data['principal'], $data['description'], $id]);
                self::writeLoan($pdo, $id, $data, $plan, false);
                AuditLog::record($request, $actor, 'LOAN_UPDATED', 'transaction', $id, (string) $trx['doc_no'],
                    array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)), $pdo);
            }
            if ($submit) {
                self::submitLocked($pdo, $request, $actor, $trx, $data, $plan);
            }
        });
    }

    /**
     * Ajukan draft ke validasi. Semua aturan diperiksa ulang dengan keadaan terkini (pengaturan, regu, kas).
     * Bila tarif pengaturan berubah sejak draft dibuat, pengajuan ditolak: pembuat harus membuka Ubah dan menyimpan
     * ulang agar jadwal dihitung dengan tarif baru dan ia melihat angkanya.
     * @param array{id:int,username:string,roles:array<int,string>,team_id?:?int} $actor
     */
    public static function submit(Request $request, array $actor, int $id, string $version): void
    {
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $version): void {
            $trx = TransactionFlow::lockOwned($pdo, $actor, $id, $version, 'PENCAIRAN_PINJAMAN');
            if ($trx['status'] !== 'DRAFT') {
                throw RuleViolation::field('_form', 'Transaksi ini sudah ' . TransactionFlow::statusPhrase((string) $trx['status']) . ', tidak bisa diajukan lagi.');
            }
            $loan = self::loadLoan($pdo, $id);
            $data = self::rowData($trx, $loan) + ['confirm_duplicate' => true];
            $plan = self::plan($pdo, $actor, $data, $id, true);
            self::submitLocked($pdo, $request, $actor, $trx, $data, $plan, (int) round((float) $loan['rate_pct_month'] * 100));
        });
    }

    /**
     * Batalkan draft atau pinjaman yang menunggu validasi, dengan alasan.
     * @param array{id:int,username:string,roles:array<int,string>,team_id?:?int} $actor
     */
    public static function cancel(Request $request, array $actor, int $id, string $reason, string $version): void
    {
        TransactionFlow::cancel($request, $actor, $id, $reason, $version, 'PENCAIRAN_PINJAMAN', 'LOAN_CANCELLED');
    }

    // ------------------------------------------------------------------ aturan

    /**
     * Semua aturan bisnis; menghasilkan rencana (bunga, jadwal, bulan jatuh tempo) yang siap disimpan.
     * @param array<string,mixed> $data
     * @param array{id:int,roles:array<int,string>,team_id?:?int} $actor
     * @return array{ctx:array<string,mixed>,rate_h:int,quote:array{interest:int,total:int,installments:array<int,int>},due_ids:array<int,int>}
     */
    private static function plan(\PDO $pdo, array $actor, array $data, ?int $exceptId, bool $forSubmit): array
    {
        $ctx = TransactionFlow::assertRecordable($pdo, $actor, $data);

        $tenor = (int) $data['tenor'];
        $min   = (int) SettingsService::get('loan_tenor_min');
        $max   = (int) SettingsService::get('loan_tenor_max');
        if ($tenor < $min || $tenor > $max) {
            throw RuleViolation::field('tenor', $min === $max ? "Tenor harus {$min} bulan." : "Tenor harus antara {$min} dan {$max} bulan.");
        }
        $ceiling = (int) SettingsService::get('loan_max_amount');
        if ($ceiling > 0 && (int) $data['principal'] > $ceiling) {
            throw RuleViolation::field('principal', 'Melebihi plafon pinjaman ' . Money::format($ceiling) . ' per pencairan.');
        }

        // angsuran pertama jatuh tempo di bulan siklus setelah bulan pencairan; semuanya harus muat di periode
        $stmt = $pdo->prepare('SELECT id FROM period_months WHERE period_id = ? AND month_date > ? ORDER BY month_date LIMIT ' . $tenor);
        $stmt->execute([$ctx['period_id'], $ctx['month']]);
        $dueIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        if (count($dueIds) < $tenor) {
            throw RuleViolation::field('tenor', 'Tenor ' . $tenor . ' bulan melewati akhir periode: setelah ' . month_label($ctx['month']) . ' hanya tersisa ' . count($dueIds) . ' bulan.');
        }

        $rateH = self::currentRateHundredths();
        $quote = self::quote((int) $data['principal'], $tenor, $rateH);

        if (empty($data['confirm_duplicate'])) {
            $stmt = $pdo->prepare("SELECT t.doc_no FROM transactions t JOIN loans ln ON ln.transaction_id = t.id
                                   WHERE t.member_id = ? AND t.period_month_id = ? AND t.amount = ? AND ln.tenor_months = ? AND t.reverses_id IS NULL
                                     AND t.deleted_at IS NULL AND t.status IN ('DRAFT','MENUNGGU_VALIDASI','DISETUJUI') AND t.id <> ? LIMIT 1");
            $stmt->execute([$ctx['member_id'], (int) $data['period_month_id'], (int) $data['principal'], $tenor, $exceptId ?? 0]);
            $dup = $stmt->fetchColumn();
            if ($dup !== false) {
                throw RuleViolation::field('duplicate', "Pinjaman yang sama persis (anggota, bulan, jumlah, tenor) sudah ada: {$dup}. Centang konfirmasi bila ini memang pinjaman terpisah.");
            }
        }

        if ($forSubmit) {
            self::assertSubmittable($pdo, $ctx, $data, $exceptId);
        }
        return ['ctx' => $ctx, 'rate_h' => $rateH, 'quote' => $quote, 'due_ids' => $dueIds];
    }

    /**
     * Aturan yang hanya berlaku saat diajukan: batas pinjaman aktif per anggota dan kas tersedia.
     * @param array<string,mixed> $ctx @param array<string,mixed> $data
     */
    private static function assertSubmittable(\PDO $pdo, array $ctx, array $data, ?int $exceptId): void
    {
        $limit = (int) SettingsService::get('loan_max_active_per_member');
        if ($limit > 0) {
            $stmt = $pdo->prepare("SELECT
                    (SELECT COUNT(*) FROM v_loan_balances WHERE member_id = ? AND loan_status = 'AKTIF')
                  + (SELECT COUNT(*) FROM transactions WHERE member_id = ? AND type = 'PENCAIRAN_PINJAMAN' AND status = 'MENUNGGU_VALIDASI' AND deleted_at IS NULL AND id <> ?)");
            $stmt->execute([$ctx['member_id'], $ctx['member_id'], $exceptId ?? 0]);
            if ((int) $stmt->fetchColumn() >= $limit) {
                throw RuleViolation::field('_form', "Anggota ini sudah mencapai batas {$limit} pinjaman aktif (termasuk yang menunggu validasi).");
            }
        }

        if (!(bool) SettingsService::get('allow_negative_cash')) {
            $cash = (int) $pdo->query('SELECT kas_tersedia FROM v_global_summary')->fetchColumn();
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN' AND status = 'MENUNGGU_VALIDASI' AND reverses_id IS NULL AND deleted_at IS NULL AND id <> ?");
            $stmt->execute([$exceptId ?? 0]);
            $reserved  = (int) $stmt->fetchColumn();
            $available = $cash - $reserved;
            if ((int) $data['principal'] > $available) {
                throw RuleViolation::field('principal', 'Kas tersedia tidak cukup: ' . Money::format($cash) . ($reserved > 0 ? ', sudah dicadangkan untuk pinjaman lain yang menunggu validasi ' . Money::format($reserved) : '')
                    . '. Pinjaman hanya boleh dicairkan sebesar kas yang ada.');
            }
        }
    }

    // ------------------------------------------------------------------ internal

    /**
     * Pindahkan ke MENUNGGU_VALIDASI. Untuk draft yang sudah ada ($draftRateH terisi) tarifnya harus masih sama.
     * @param array<string,mixed> $trx @param array<string,mixed> $data
     * @param array{ctx:array<string,mixed>,rate_h:int,quote:array<string,mixed>,due_ids:array<int,int>} $plan
     */
    private static function submitLocked(\PDO $pdo, Request $request, array $actor, array $trx, array $data, array $plan, ?int $draftRateH = null): void
    {
        if ($draftRateH !== null && $draftRateH !== $plan['rate_h']) {
            throw RuleViolation::field('_form', sprintf('Tarif bunga berubah dari %s%% menjadi %s%% sejak draft ini dibuat. Buka Ubah lalu simpan ulang agar jadwal dihitung dengan tarif baru.',
                number_format($draftRateH / 100, 2, ',', '.'), number_format($plan['rate_h'] / 100, 2, ',', '.')));
        }
        // pengajuan langsung dari formulir (create/update) belum menjalankan aturan khusus-pengajuan
        if ($draftRateH === null) {
            self::assertSubmittable($pdo, $plan['ctx'], $data, (int) $trx['id']);
        }
        TransactionFlow::move($pdo, $request, $actor, $trx, 'MENUNGGU_VALIDASI', null, 'LOAN_SUBMITTED');
    }

    /**
     * Tulis baris pinjaman dan jadwal cicilannya (menimpa yang lama saat draft diubah).
     * @param array<string,mixed> $data @param array{ctx:array<string,mixed>,rate_h:int,quote:array{interest:int,total:int,installments:array<int,int>},due_ids:array<int,int>} $plan
     */
    private static function writeLoan(\PDO $pdo, int $trxId, array $data, array $plan, bool $isNew): void
    {
        $rate = number_format($plan['rate_h'] / 100, 2, '.', '');
        if ($isNew) {
            $pdo->prepare('INSERT INTO loans (transaction_id, member_id, principal, tenor_months, rate_pct_month, total_interest, disbursed_on) VALUES (?,?,?,?,?,?,?)')
                ->execute([$trxId, $data['member_id'], $data['principal'], $data['tenor'], $rate, $plan['quote']['interest'], $data['trx_date']]);
            $loanId = (int) $pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare('SELECT id FROM loans WHERE transaction_id = ?');
            $stmt->execute([$trxId]);
            $loanId = (int) $stmt->fetchColumn();
            $pdo->prepare('UPDATE loans SET member_id = ?, principal = ?, tenor_months = ?, rate_pct_month = ?, total_interest = ?, disbursed_on = ? WHERE id = ?')
                ->execute([$data['member_id'], $data['principal'], $data['tenor'], $rate, $plan['quote']['interest'], $data['trx_date'], $loanId]);
            $pdo->prepare('DELETE FROM loan_installments WHERE loan_id = ?')->execute([$loanId]);
        }
        $ins = $pdo->prepare('INSERT INTO loan_installments (loan_id, seq, due_month_id, amount_due) VALUES (?,?,?,?)');
        foreach ($plan['quote']['installments'] as $i => $amount) {
            $ins->execute([$loanId, $i + 1, $plan['due_ids'][$i], $amount]);
        }
    }

    /** @return array<string,mixed> */
    private static function loadLoan(\PDO $pdo, int $trxId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM loans WHERE transaction_id = ?');
        $stmt->execute([$trxId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw RuleViolation::field('_form', 'Data pinjaman tidak ditemukan.');
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $trx @param array<string,mixed> $loan
     * @return array<string,mixed>
     */
    private static function rowData(array $trx, array $loan): array
    {
        return [
            'member_id' => (int) $trx['member_id'], 'period_month_id' => (int) $trx['period_month_id'], 'principal' => (int) $trx['amount'],
            'tenor' => (int) $loan['tenor_months'], 'trx_date' => (string) $trx['trx_date'], 'description' => $trx['description'],
        ];
    }

    /**
     * Isi pinjaman untuk audit (satu bentuk untuk sebelum/sesudah agar mudah dibandingkan).
     * @param array<string,mixed> $data @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private static function snapshot(array $data, array $plan): array
    {
        return [
            'member_id' => (int) $data['member_id'], 'team_id' => (int) $plan['ctx']['team_id'], 'period_month_id' => (int) $data['period_month_id'],
            'principal' => (int) $data['principal'], 'tenor' => (int) $data['tenor'], 'rate_hundredths' => (int) $plan['rate_h'],
            'interest' => (int) $plan['quote']['interest'], 'trx_date' => (string) $data['trx_date'], 'description' => $data['description'] ?? null,
        ];
    }
}
