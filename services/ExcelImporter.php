<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Impor data historis Excel (Maret-September 2026) ke database.
 *
 * Dua tahap yang sengaja dipisah:
 *   1. plan()    murni di memori, TANPA database. Menyusun transaksi, jadwal cicilan, dan alokasi
 *                angsuran (cicilan tertua dulu), lalu memverifikasinya terhadap angka kontrol
 *                yang dihitung Excel sendiri. Inilah yang dijalankan saat dry-run.
 *   2. commit()  menulis semuanya dalam SATU transaksi database, memverifikasi ulang lewat view
 *                saldo, dan membatalkan seluruhnya bila ada selisih sekecil apa pun.
 *
 * Tidak ada data yang diubah atau dikarang: setiap transaksi berasal dari satu sel Excel.
 */
final class ExcelImporter
{
    private const NOTE = 'Impor Excel: data historis, belum melalui validator';

    /** @param array<string,mixed> $data */
    public function __construct(private array $data)
    {
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException("File ekstrak tidak ditemukan: {$file}. Jalankan extract_excel.py dulu.");
        }
        $data = json_decode((string) file_get_contents($file), true);
        foreach (['months', 'teams', 'members', 'monthly', 'expenses', 'checks', 'period', 'rate_pct_month'] as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                throw new \RuntimeException("File ekstrak tidak lengkap: kunci '{$key}' hilang.");
            }
        }
        return new self($data);
    }

    // ===================================================================================
    // Tahap 1: rencana di memori
    // ===================================================================================

    /** @return array<string,mixed> */
    public function plan(): array
    {
        $d        = $this->data;
        $rate     = (int) $d['rate_pct_month'];
        $monthKeys = array_column($d['months'], 'key');
        $monthIdx = array_flip($monthKeys);
        $meeting  = [];
        foreach ($d['months'] as $m) {
            $meeting[$m['key']] = $m['location'] !== null ? $m['meeting_date'] : null; // tanggal tanpa lokasi = placeholder
        }
        $dateOf = static fn (string $mk): string => $meeting[$mk] ?? $mk . '-01';

        $trxs      = [];
        $schedule  = [];  // no anggota => daftar cicilan (urutan = urutan pencairan lalu seq)
        $loans     = [];
        $unalloc   = [];
        $warnings  = [];
        $lastActive = null;

        foreach ($monthKeys as $mi => $mk) {
            $rows = $d['monthly'][$mk] ?? [];
            if ($rows === []) {
                continue;
            }
            $lastActive = $mk;
            usort($rows, static fn (array $a, array $b): int => $a['no'] <=> $b['no']);

            // 1) pencairan pinjaman
            foreach ($rows as $row) {
                if (empty($row['pinjam'])) {
                    continue;
                }
                $no        = (int) $row['no'];
                $principal = (int) $row['pinjam'];
                $tenor     = (int) ($row['tempo'] ?? 0);
                if ($tenor < 1 || $tenor > 12) {
                    throw new \RuntimeException("Anggota #{$no} bulan {$mk}: tenor tidak valid ({$row['tempo']}).");
                }
                if (($principal * $rate * $tenor) % 100 !== 0) {
                    $warnings[] = "Anggota #{$no} {$mk}: bunga tidak bulat untuk pokok {$principal} tenor {$tenor}.";
                }
                $interest = intdiv($principal * $rate * $tenor, 100);
                $total    = $principal + $interest;
                $base     = intdiv($total, $tenor);
                $installments = [];
                for ($seq = 1; $seq <= $tenor; $seq++) {
                    $dueIdx = $mi + $seq;
                    if (!isset($monthKeys[$dueIdx])) {
                        throw new \RuntimeException("Pinjaman anggota #{$no} {$mk} tenor {$tenor} melewati akhir periode.");
                    }
                    $installments[] = [
                        'seq' => $seq, 'due_month' => $monthKeys[$dueIdx], 'due_idx' => $dueIdx,
                        'amount_due' => $seq === $tenor ? $total - $base * ($tenor - 1) : $base,
                    ];
                }
                $loanKey = "L{$no}-{$mk}";
                $loan = ['key' => $loanKey, 'member_no' => $no, 'principal' => $principal, 'tenor' => $tenor,
                         'interest' => $interest, 'disbursed_on' => $dateOf($mk), 'installments' => $installments];
                $loans[$loanKey] = $loan;
                foreach ($installments as $i) {
                    $schedule[$no][] = ['loan_key' => $loanKey, 'seq' => $i['seq'], 'due_idx' => $i['due_idx'],
                                        'amount_due' => $i['amount_due'], 'paid' => 0, 'disb_idx' => $mi];
                }
                $trxs[] = ['type' => 'PENCAIRAN_PINJAMAN', 'member_no' => $no, 'month' => $mk, 'date' => $dateOf($mk),
                           'amount' => $principal, 'import_ref' => "XLS:{$mk}:A{$no}:PJM",
                           'description' => "Pencairan pinjaman tenor {$tenor} bulan (impor Excel)", 'loan' => $loan];
            }

            // 2) simpanan dan penarikan
            foreach ($rows as $row) {
                $no = (int) $row['no'];
                if (!empty($row['tabungan'])) {
                    $trxs[] = ['type' => 'SIMPANAN', 'member_no' => $no, 'month' => $mk, 'date' => $dateOf($mk),
                               'amount' => (int) $row['tabungan'], 'import_ref' => "XLS:{$mk}:A{$no}:TAB",
                               'description' => 'Setoran tabungan (impor Excel)', 'kind' => 'CAMPURAN'];
                }
                if (!empty($row['tarik'])) {
                    $trxs[] = ['type' => 'PENARIKAN', 'member_no' => $no, 'month' => $mk, 'date' => $dateOf($mk),
                               'amount' => (int) $row['tarik'], 'import_ref' => "XLS:{$mk}:A{$no}:TRK",
                               'description' => 'Penarikan tabungan (impor Excel)'];
                }
            }

            // 3) angsuran: dialokasikan ke cicilan tertua dulu, hanya dari pinjaman yang dicairkan SEBELUM bulan ini
            foreach ($rows as $row) {
                if (empty($row['bayar'])) {
                    continue;
                }
                $no   = (int) $row['no'];
                $left = (int) $row['bayar'];
                $allocations = [];
                // Penting: iterasi referensi langsung ke $schedule[$no] (bukan salinan), agar 'paid' tercatat.
                foreach (array_keys($schedule[$no] ?? []) as $k) {
                    if ($left <= 0) {
                        break;
                    }
                    $inst = &$schedule[$no][$k];
                    $room = $inst['amount_due'] - $inst['paid'];
                    if ($inst['disb_idx'] < $mi && $room > 0) {
                        $take = min($room, $left);
                        $inst['paid'] += $take;
                        $left         -= $take;
                        $allocations[] = ['loan_key' => $inst['loan_key'], 'seq' => $inst['seq'], 'amount' => $take];
                    }
                    unset($inst);
                }
                if ($left > 0) {
                    $unalloc[] = ['member_no' => $no, 'month' => $mk, 'amount' => $left];
                }
                $trxs[] = ['type' => 'ANGSURAN', 'member_no' => $no, 'month' => $mk, 'date' => $dateOf($mk),
                           'amount' => (int) $row['bayar'], 'import_ref' => "XLS:{$mk}:A{$no}:ANG",
                           'description' => 'Pembayaran angsuran (impor Excel)', 'allocations' => $allocations];
            }

            // 4) biaya operasional
            foreach ($d['expenses'] as $n => $exp) {
                if ($exp['month'] !== $mk) {
                    continue;
                }
                $trxs[] = ['type' => 'BIAYA', 'member_no' => null, 'month' => $mk, 'date' => $dateOf($mk),
                           'amount' => (int) $exp['amount'], 'import_ref' => "XLS:{$mk}:BYA:{$n}",
                           'description' => $exp['description'], 'category' => $exp['description']];
            }
        }

        $plan = ['trxs' => $trxs, 'loans' => $loans, 'schedule' => $schedule, 'unallocated' => $unalloc,
                 'warnings' => $warnings, 'last_active' => $lastActive];
        $plan['checks']  = $this->verify($plan);
        $plan['report']  = $this->report($plan, $monthKeys);
        return $plan;
    }

    /**
     * Bandingkan hasil rencana dengan angka kontrol Excel.
     * @param array<string,mixed> $plan
     * @return array<int,array{name:string,expected:int|string,actual:int|string,ok:bool}>
     */
    private function verify(array $plan): array
    {
        $c       = $this->data['checks'];
        $out     = [];
        $add     = static function (string $name, int|string $expected, int|string $actual) use (&$out): void {
            $out[] = ['name' => $name, 'expected' => $expected, 'actual' => $actual, 'ok' => $expected === $actual];
        };

        // per bulan
        $sum = [];
        foreach ($plan['trxs'] as $t) {
            $k = match ($t['type']) {
                'SIMPANAN' => 'tabungan', 'PENCAIRAN_PINJAMAN' => 'pinjam', 'ANGSURAN' => 'bayar', default => null,
            };
            if ($k !== null) {
                $sum[$t['month']][$k] = ($sum[$t['month']][$k] ?? 0) + $t['amount'];
            }
        }
        foreach ($c['month_totals'] as $mk => $exp) {
            if ($exp['tabungan'] + $exp['pinjam'] + $exp['bayar'] === 0) {
                continue;
            }
            foreach (['tabungan', 'pinjam', 'bayar'] as $k) {
                $add("Total {$k} {$mk}", (int) $exp[$k], (int) ($sum[$mk][$k] ?? 0));
            }
        }

        // saldo kas akhir tiap bulan
        $cash = 0;
        foreach ($this->data['months'] as $m) {
            $mk = $m['key'];
            if (($plan['last_active'] ?? '') === '' || $mk > $plan['last_active']) {
                break;
            }
            foreach ($plan['trxs'] as $t) {
                if ($t['month'] !== $mk) {
                    continue;
                }
                $cash += match ($t['type']) {
                    'SIMPANAN', 'ANGSURAN' => $t['amount'],
                    default => -$t['amount'],
                };
            }
            if (($c['cash_closing'][$mk] ?? null) !== null) {
                $add("Saldo kas akhir {$mk}", (int) $c['cash_closing'][$mk], $cash);
            }
        }

        // per anggota
        $savings = [];
        foreach ($plan['trxs'] as $t) {
            if ($t['type'] === 'SIMPANAN') {
                $savings[$t['member_no']] = ($savings[$t['member_no']] ?? 0) + $t['amount'];
            } elseif ($t['type'] === 'PENARIKAN') {
                $savings[$t['member_no']] = ($savings[$t['member_no']] ?? 0) - $t['amount'];
            }
        }
        $outstanding = $this->outstandingByMember($plan);
        $badSavings = $badOutstanding = 0;
        foreach ($this->data['members'] as $m) {
            $no = (string) $m['no'];
            $badSavings     += ((int) $c['member_savings'][$no] === ($savings[$m['no']] ?? 0)) ? 0 : 1;
            $badOutstanding += ((int) $c['member_outstanding'][$no] === ($outstanding[$m['no']] ?? 0)) ? 0 : 1;
        }
        $n = count($this->data['members']);
        $add("Saldo tabungan per anggota cocok ({$n} anggota): selisih", 0, $badSavings);
        $add("Sisa pinjaman per anggota cocok ({$n} anggota): selisih", 0, $badOutstanding);

        // total
        $pinjaman = array_sum(array_map(static fn (array $l): int => $l['principal'], $plan['loans']));
        $bunga    = array_sum(array_map(static fn (array $l): int => $l['interest'], $plan['loans']));
        $biaya    = array_sum(array_map(static fn (array $t): int => $t['type'] === 'BIAYA' ? $t['amount'] : 0, $plan['trxs']));
        $add('Total pinjaman dicairkan', (int) $c['total_pinjaman'], $pinjaman);
        $add('Total bunga dibukukan', (int) $c['total_bunga'], $bunga);
        $add('Total biaya operasional', (int) $c['total_biaya'], $biaya);

        // invarian & alokasi
        $tabungan = array_sum($savings);
        $piutang  = array_sum($outstanding);
        $add('Angsuran yang tidak bisa dialokasikan ke cicilan', 0, count($plan['unallocated']));
        $add('Invarian: kas + piutang - (tabungan + bunga - biaya)', 0, $cash + $piutang - ($tabungan + $bunga - $biaya));
        return $out;
    }

    /** @return array<int,int> no anggota => sisa pinjaman (pokok+bunga) */
    private function outstandingByMember(array $plan): array
    {
        $out = [];
        foreach ($plan['schedule'] as $no => $list) {
            foreach ($list as $i) {
                $out[$no] = ($out[$no] ?? 0) + $i['amount_due'] - $i['paid'];
            }
        }
        return $out;
    }

    /**
     * Temuan untuk ditinjau manusia (bukan kesalahan impor): tunggakan, anggota tanpa pembayaran, pengecualian.
     * @param array<string,mixed> $plan
     * @param array<int,string> $monthKeys
     * @return array<string,mixed>
     */
    private function report(array $plan, array $monthKeys): array
    {
        $cut     = array_search($plan['last_active'], $monthKeys, true);
        $names   = array_column($this->data['members'], 'name', 'no');
        $overdue = [];
        foreach ($plan['schedule'] as $no => $list) {
            foreach ($list as $i) {
                if ($i['due_idx'] <= $cut && $i['amount_due'] - $i['paid'] > 0) {
                    $overdue[$no]['amount'] = ($overdue[$no]['amount'] ?? 0) + $i['amount_due'] - $i['paid'];
                    $overdue[$no]['count']  = ($overdue[$no]['count'] ?? 0) + 1;
                }
            }
        }
        uasort($overdue, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        $paid = [];
        foreach ($plan['trxs'] as $t) {
            if ($t['type'] === 'ANGSURAN') {
                $paid[$t['member_no']] = true;
            }
        }
        $neverPaid = [];
        foreach ($overdue as $no => $o) {
            if (empty($paid[$no])) {
                $neverPaid[] = $names[$no];
            }
        }

        return [
            'overdue'      => array_map(static fn (int $no, array $o): array => ['no' => $no, 'name' => $names[$no]] + $o, array_keys($overdue), $overdue),
            'never_paid'   => $neverPaid,
            'exempt'       => array_values(array_map(static fn (array $m): string => $m['name'], array_filter($this->data['members'], static fn (array $m): bool => $m['reserve_exempt']))),
            'idle_members' => array_values(array_map(static fn (array $m): string => $m['name'], array_filter($this->data['members'], static function (array $m) use ($plan): bool {
                foreach ($plan['trxs'] as $t) {
                    if ($t['member_no'] === $m['no']) {
                        return false;
                    }
                }
                return true;
            }))),
        ];
    }

    /** @param array<string,mixed> $plan */
    public function isSafeToCommit(array $plan): bool
    {
        foreach ($plan['checks'] as $c) {
            if (!$c['ok']) {
                return false;
            }
        }
        return true;
    }

    // ===================================================================================
    // Tahap 2: tulis ke database
    // ===================================================================================

    /**
     * @param array<string,mixed> $plan hasil plan() yang lolos isSafeToCommit()
     * @return array<string,int> jumlah baris yang ditulis
     */
    public function commit(\PDO $pdo, array $plan): array
    {
        if (!$this->isSafeToCommit($plan)) {
            throw new \RuntimeException('Rencana impor belum lolos verifikasi; commit dibatalkan.');
        }
        $d = $this->data;

        $pdo->beginTransaction();
        try {
            foreach (['members', 'transactions', 'periods'] as $table) {
                if ((int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() > 0) {
                    throw new \RuntimeException("Tabel {$table} tidak kosong. Impor hanya untuk database baru.");
                }
            }
            $rate = (string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'interest_rate_pct_month'")->fetchColumn();
            if ((float) $rate !== (float) $d['rate_pct_month']) {
                throw new \RuntimeException("Tarif bunga di settings ({$rate}) berbeda dari data impor ({$d['rate_pct_month']}).");
            }

            // periode + 12 bulan
            $pdo->prepare('INSERT INTO periods (name, start_date, end_date) VALUES (?,?,?)')
                ->execute([$d['period']['name'], $d['period']['start'], $d['period']['end']]);
            $periodId = (int) $pdo->lastInsertId();
            $monthId  = [];
            $insMonth = $pdo->prepare('INSERT INTO period_months (period_id, month_date, meeting_date, location) VALUES (?,?,?,?)');
            foreach ($d['months'] as $m) {
                $hasMeeting = $m['location'] !== null;
                $insMonth->execute([$periodId, $m['key'] . '-01', $hasMeeting ? $m['meeting_date'] : null, $m['location']]);
                $monthId[$m['key']] = (int) $pdo->lastInsertId();
            }

            // anggota
            $memberId = [];
            $insMember = $pdo->prepare('INSERT INTO members (member_no, name, address_block, active_from, reserve_exempt, notes) VALUES (?,?,?,?,?,?)');
            foreach ($d['members'] as $m) {
                $note = $m['reserve_exempt'] ? 'Dikecualikan dari cadangan 5% (diimpor dari Excel: nilai potongan di-hardcode 0). Perlu konfirmasi.' : null;
                $insMember->execute([sprintf('AGT-%03d', $m['no']), $m['name'], $m['address'], $m['active_from'], (int) $m['reserve_exempt'], $note]);
                $memberId[$m['no']] = (int) $pdo->lastInsertId();
            }

            // regu + keanggotaan
            $teamId = [];
            foreach ($d['teams'] as $t) {
                if ($t['leader_no'] === null) {
                    throw new \RuntimeException("Ketua regu '{$t['name']}' tidak ditemukan di daftar anggota.");
                }
                $pdo->prepare('INSERT INTO team_leaders (name, leader_member_id) VALUES (?,?)')
                    ->execute(['Regu ' . $t['name'], $memberId[$t['leader_no']]]);
                $teamId[$t['name']] = (int) $pdo->lastInsertId();
            }
            $teamOfMember = [];
            $insAssign = $pdo->prepare('INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (?,?,?)');
            foreach ($d['members'] as $m) {
                $teamOfMember[$m['no']] = $teamId[$m['team']];
                $insAssign->execute([$memberId[$m['no']], $teamId[$m['team']], $m['active_from']]);
            }

            // transaksi
            $insTrx   = $pdo->prepare("INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount, status, description, source, import_ref)
                                       VALUES (?,?,?,?,?,?,?,?, 'DRAFT', ?, 'IMPOR_EXCEL', ?)");
            $insSave  = $pdo->prepare('INSERT INTO savings (transaction_id, kind) VALUES (?, ?)');
            $insExp   = $pdo->prepare("INSERT INTO expenses (transaction_id, category, fund_source) VALUES (?, ?, 'SHU')");
            $insLoan  = $pdo->prepare('INSERT INTO loans (transaction_id, member_id, principal, tenor_months, rate_pct_month, total_interest, disbursed_on) VALUES (?,?,?,?,?,?,?)');
            $insInst  = $pdo->prepare('INSERT INTO loan_installments (loan_id, seq, due_month_id, amount_due) VALUES (?,?,?,?)');
            $insAlloc = $pdo->prepare('INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES (?,?,?)');
            $setState = $pdo->prepare('UPDATE transactions SET status = ?, status_changed_at = NOW() WHERE id = ?');
            $insValid = $pdo->prepare('INSERT INTO transaction_validations (transaction_id, from_status, to_status, actor_user_id, note) VALUES (?,?,?,NULL,?)');

            $installmentId = [];
            $counts = ['transaksi' => 0, 'pinjaman' => 0, 'cicilan' => 0, 'alokasi' => 0];

            foreach ($plan['trxs'] as $t) {
                $no  = $t['member_no'];
                $num = NumberSequence::forTransaction($pdo, $t['type'], (int) substr($t['date'], 0, 4));
                $insTrx->execute([
                    $num['trx_no'], $num['doc_no'], $t['type'], $no === null ? null : $memberId[$no],
                    $no === null ? null : $teamOfMember[$no], $monthId[$t['month']], $t['date'], $t['amount'],
                    $t['description'], $t['import_ref'],
                ]);
                $id = (int) $pdo->lastInsertId();

                switch ($t['type']) {
                    case 'SIMPANAN':
                        $insSave->execute([$id, $t['kind']]);
                        break;
                    case 'BIAYA':
                        $insExp->execute([$id, $t['category']]);
                        break;
                    case 'PENCAIRAN_PINJAMAN':
                        $l = $t['loan'];
                        $insLoan->execute([$id, $memberId[$no], $l['principal'], $l['tenor'], number_format((float) $d['rate_pct_month'], 2, '.', ''), $l['interest'], $l['disbursed_on']]);
                        $loanDbId = (int) $pdo->lastInsertId();
                        foreach ($l['installments'] as $i) {
                            $insInst->execute([$loanDbId, $i['seq'], $monthId[$i['due_month']], $i['amount_due']]);
                            $installmentId[$l['key'] . '|' . $i['seq']] = (int) $pdo->lastInsertId();
                            $counts['cicilan']++;
                        }
                        $counts['pinjaman']++;
                        break;
                    case 'ANGSURAN':
                        foreach ($t['allocations'] as $a) {
                            $insAlloc->execute([$id, $installmentId[$a['loan_key'] . '|' . $a['seq']], $a['amount']]);
                            $counts['alokasi']++;
                        }
                        break;
                }

                // DRAFT -> MENUNGGU_VALIDASI -> DISETUJUI, lewat jalur yang sama dengan transaksi aplikasi
                $setState->execute(['MENUNGGU_VALIDASI', $id]);
                $insValid->execute([$id, 'DRAFT', 'MENUNGGU_VALIDASI', self::NOTE]);
                $setState->execute(['DISETUJUI', $id]);
                $insValid->execute([$id, 'MENUNGGU_VALIDASI', 'DISETUJUI', self::NOTE]);
                $counts['transaksi']++;
            }

            $this->verifyDatabase($pdo, $memberId, (string) $plan['last_active']);

            $pdo->prepare('INSERT INTO audit_logs (action, entity_type, after_data) VALUES (?,?,?)')->execute([
                'IMPOR_EXCEL', 'import',
                json_encode(['sumber' => $d['source'] ?? null, 'jumlah' => $counts, 'anggota' => count($memberId)], JSON_UNESCAPED_UNICODE),
            ]);
            $pdo->commit();
            return $counts + ['anggota' => count($memberId), 'regu' => count($teamId), 'bulan' => count($monthId)];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Verifikasi hasil di DATABASE (bukan di memori) terhadap angka kontrol Excel, lewat view saldo
     * yang sama dengan yang akan dipakai aplikasi. Melempar exception bila ada selisih.
     * @param array<int,int> $memberId no => id
     */
    private function verifyDatabase(\PDO $pdo, array $memberId, string $lastActive): void
    {
        $c = $this->data['checks'];
        $errors = [];

        $savings = $pdo->query('SELECT member_id, savings_balance FROM v_member_savings')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $out     = $pdo->query('SELECT member_id, SUM(outstanding) FROM v_loan_balances GROUP BY member_id')->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach ($memberId as $no => $id) {
            if ((int) ($savings[$id] ?? 0) !== (int) $c['member_savings'][(string) $no]) {
                $errors[] = "Saldo tabungan anggota #{$no}: DB " . ($savings[$id] ?? 0) . ' vs Excel ' . $c['member_savings'][(string) $no];
            }
            if ((int) ($out[$id] ?? 0) !== (int) $c['member_outstanding'][(string) $no]) {
                $errors[] = "Sisa pinjaman anggota #{$no}: DB " . ($out[$id] ?? 0) . ' vs Excel ' . $c['member_outstanding'][(string) $no];
            }
        }

        $g = $pdo->query('SELECT * FROM v_global_summary')->fetch();
        if ((int) $g['kas_tersedia'] !== (int) $c['cash_closing'][$lastActive]) {
            $errors[] = "Kas tersedia: DB {$g['kas_tersedia']} vs Excel {$c['cash_closing'][$lastActive]}";
        }
        if ((int) $g['bunga_dibukukan'] !== (int) $c['total_bunga']) {
            $errors[] = "Bunga dibukukan: DB {$g['bunga_dibukukan']} vs Excel {$c['total_bunga']}";
        }
        if ((int) $g['biaya'] !== (int) $c['total_biaya']) {
            $errors[] = "Biaya: DB {$g['biaya']} vs Excel {$c['total_biaya']}";
        }
        if ((int) $g['selisih'] !== 0) {
            $errors[] = "Invarian saldo tidak nol: selisih {$g['selisih']}";
        }
        $issues = $pdo->query('SELECT issue, reference, detail FROM v_integrity_issues LIMIT 5')->fetchAll();
        foreach ($issues as $i) {
            $errors[] = "Integritas: {$i['issue']} {$i['reference']} ({$i['detail']})";
        }

        if ($errors !== []) {
            throw new \RuntimeException("Verifikasi database gagal, impor dibatalkan:\n  - " . implode("\n  - ", $errors));
        }
    }
}
