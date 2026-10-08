<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Gate;
use App\Core\Request;
use App\Helpers\Money;

/**
 * Transaksi pembalik: satu-satunya cara mengoreksi transaksi yang SUDAH DISETUJUI. Transaksi asal tidak pernah
 * diubah atau dihapus; pembalik adalah transaksi baru (jenis, anggota, dan nominal sama) bertanda `reverses_id`
 * yang menghapus pengaruh transaksi asal dari saldo setelah DISETUJUI lewat alur validasi yang sama (Phase 9).
 *
 * Aturan:
 *  - Hanya Ketua Regu yang mengajukan (alasan wajib), untuk transaksi regunya. Pembalik langsung menunggu validasi;
 *    pembuat tidak boleh memvalidasinya sendiri dan transaksi terkait Head hanya divalidasi Pemeriksa.
 *  - Hanya transaksi DISETUJUI yang bukan pembalik, dan hanya SATU pembalik hidup per transaksi (pembalik yang
 *    ditolak/dibatalkan boleh diganti). Pembalik selalu penuh: koreksi sebagian = balik lalu catat ulang.
 *  - Bulan pembalik = bulan transaksi asal (rekap bulanan langsung bersih), tanggalnya hari pengajuan. Periode
 *    yang sudah ditutup tidak bisa dikoreksi.
 *  - Pembalik tidak boleh membuat keadaan tidak sah: simpanan tak bisa dibalik bila saldo tabungan anggota jadi negatif;
 *    simpanan dan angsuran tak bisa dibalik bila kas kurang (kecuali "izinkan melebihi kas"); pinjaman tak bisa dibalik
 *    selama masih ada pembayaran bersih atau pembayaran yang menunggu (balik angsurannya dulu). Diperiksa saat diajukan
 *    (penolakan dini) DAN saat disetujui (yang menentukan, di bawah kunci kas).
 *  - Angsuran dibalik dengan alokasi NEGATIF persis kebalikan alokasi asal, sehingga cicilan terbuka lagi.
 */
final class ReversalService
{
    public const MAX_REASON = 200;

    /** Status pembalik yang masih "hidup" (menutup kemungkinan pembalik kedua). */
    private const LIVE = ['DRAFT', 'MENUNGGU_VALIDASI', 'DISETUJUI'];

    /** Jenis yang MENAMBAH kas bila disetujui; pembaliknya mengurangi kas. */
    private const CASH_IN = ['SIMPANAN', 'ANGSURAN'];

    /**
     * Pengaruh pembalik terhadap saldo bila disetujui (positif = naik).
     * @return array{cash_delta:int,savings_delta:int}
     */
    public static function impact(string $type, int $amount): array
    {
        return [
            'cash_delta'    => in_array($type, self::CASH_IN, true) ? -$amount : $amount,
            'savings_delta' => match ($type) {
                'SIMPANAN'  => -$amount,
                'PENARIKAN' => $amount,
                default     => 0,
            },
        ];
    }

    // ------------------------------------------------------------------ tampilan

    /**
     * Semua pembalik untuk satu transaksi (percobaan yang ditolak/dibatalkan ikut tampil sebagai jejak).
     * Pemanggil WAJIB sudah memastikan transaksi dalam cakupan.
     * @return array<int,array<string,mixed>>
     */
    public static function attempts(int $transactionId): array
    {
        return Database::select(
            'SELECT t.id, t.doc_no, t.status, t.description, t.created_at, cu.name AS creator_name
             FROM transactions t LEFT JOIN users cu ON cu.id = t.created_by
             WHERE t.reverses_id = ? AND t.deleted_at IS NULL ORDER BY t.id',
            [$transactionId]
        );
    }

    /**
     * Apa yang ditawarkan di halaman detail transaksi untuk pengguna ini.
     * `can` = formulir koreksi boleh tampil; `blocked` = alasan koreksi belum bisa diajukan (null bila tak relevan).
     *
     * @param array<string,mixed>|null $user
     * @param array<string,mixed> $trx baris dari Transaction::findScoped
     * @return array{attempts:array<int,array<string,mixed>>,live:?array<string,mixed>,can:bool,blocked:?string}
     */
    public static function offer(?array $user, array $trx): array
    {
        $attempts = $trx['reverses_id'] === null ? self::attempts((int) $trx['id']) : [];
        $live = null;
        foreach ($attempts as $a) {
            if (in_array($a['status'], self::LIVE, true)) {
                $live = $a;
            }
        }
        $out = ['attempts' => $attempts, 'live' => $live, 'can' => false, 'blocked' => null];

        if ($user === null || $trx['reverses_id'] !== null || $trx['status'] !== 'DISETUJUI' || $live !== null
            || !Gate::allows($user, 'transaction.create') || ($user['team_id'] ?? null) === null) {
            return $out;
        }
        $pdo = Database::pdo();
        if (!self::inTeam($pdo, (int) $user['team_id'], $trx)) {
            return $out;
        }
        try {
            self::assertPeriodOpen($pdo, (int) $trx['period_month_id']);
            self::assertFeasible($pdo, $trx);
            $out['can'] = true;
        } catch (RuleViolation $e) {
            $out['blocked'] = implode(' ', $e->errors);
        }
        return $out;
    }

    // ------------------------------------------------------------------ perintah

    /**
     * Ajukan pembalik untuk transaksi DISETUJUI. Langsung berstatus MENUNGGU_VALIDASI.
     * @param array{id:int,username:string,roles:array<int,string>,team_id?:?int} $actor
     * @return int id transaksi pembalik
     */
    public static function request(Request $request, array $actor, int $originalId, string $reason): int
    {
        $reason = clean_text($reason);
        if ($reason === '') {
            throw RuleViolation::field('note', 'Alasan koreksi wajib diisi.');
        }
        if (mb_strlen($reason) > self::MAX_REASON) {
            throw RuleViolation::field('note', 'Alasan koreksi maksimal ' . self::MAX_REASON . ' karakter.');
        }

        return (int) Database::transaction(static function (\PDO $pdo) use ($request, $actor, $originalId, $reason): int {
            if (!Gate::allows($actor, 'transaction.create')) {
                throw RuleViolation::field('_form', 'Akun Anda tidak berhak mengajukan koreksi.');
            }
            $teamId = $actor['team_id'] ?? null;
            if ($teamId === null) {
                throw RuleViolation::field('_form', 'Akun Anda belum menjadi ketua regu aktif, jadi belum bisa mengajukan koreksi.');
            }

            // Kunci transaksi asal: dua koreksi serentak untuk transaksi yang sama diproses berurutan.
            $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute([$originalId]);
            $orig = $stmt->fetch();
            if ($orig === false || !self::inTeam($pdo, (int) $teamId, $orig)) {
                // Pesan sama untuk "tidak ada" dan "bukan regu Anda": tidak membocorkan transaksi regu lain.
                throw RuleViolation::field('_form', 'Transaksi tidak ditemukan di regu Anda.');
            }
            if ($orig['reverses_id'] !== null) {
                throw RuleViolation::field('_form', 'Transaksi pembalik tidak bisa dibalik lagi. Catat transaksi baru bila perlu.');
            }
            if ($orig['status'] !== 'DISETUJUI') {
                throw RuleViolation::field('_form', 'Hanya transaksi yang sudah disetujui yang dikoreksi dengan pembalik. Transaksi ini ' . TransactionFlow::statusPhrase((string) $orig['status'])
                    . ($orig['status'] === 'MENUNGGU_VALIDASI' ? '; batalkan atau tunggu keputusan validator.' : '.'));
            }
            $stmt = $pdo->prepare("SELECT doc_no, status FROM transactions WHERE reverses_id = ? AND status IN ('DRAFT','MENUNGGU_VALIDASI','DISETUJUI') AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$originalId]);
            $live = $stmt->fetch();
            if ($live !== false) {
                throw RuleViolation::field('_form', "Transaksi ini sudah punya pembalik {$live['doc_no']} (" . strtolower(TransactionFlow::statusPhrase((string) $live['status'])) . ').');
            }
            self::assertPeriodOpen($pdo, (int) $orig['period_month_id']);
            self::assertFeasible($pdo, $orig);

            $num = NumberSequence::forTransaction($pdo, (string) $orig['type'], (int) date('Y'));
            $pdo->prepare("INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount, status, reverses_id, description, source, created_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'DRAFT', ?, ?, 'APLIKASI', ?)")
                ->execute([$num['trx_no'], $num['doc_no'], $orig['type'], $orig['member_id'], $teamId, $orig['period_month_id'], date('Y-m-d'), $orig['amount'], $originalId, $reason, $actor['id']]);
            $id = (int) $pdo->lastInsertId();
            self::copyDetails($pdo, (string) $orig['type'], $originalId, $id);

            AuditLog::record($request, $actor, 'REVERSAL_CREATED', 'transaction', $id, $num['doc_no'], null, [
                'reverses_id' => $originalId, 'reverses_doc_no' => (string) $orig['doc_no'], 'type' => (string) $orig['type'],
                'member_id' => $orig['member_id'] === null ? null : (int) $orig['member_id'], 'amount' => (int) $orig['amount'], 'reason' => $reason,
            ], $pdo);
            TransactionFlow::move($pdo, $request, $actor, ['id' => $id, 'doc_no' => $num['doc_no'], 'status' => 'DRAFT'], 'MENUNGGU_VALIDASI', 'Koreksi ' . $orig['doc_no'] . ': ' . $reason, 'REVERSAL_REQUESTED');
            return $id;
        });
    }

    // ------------------------------------------------------------------ pemeriksaan saat disetujui

    /**
     * Dipanggil ValidationService saat pembalik akan DISETUJUI (di bawah kunci kas). Memeriksa ulang transaksi asal
     * dan keadaan terkini; melempar RuleViolation bila pembalik tidak lagi sah.
     * @param array<string,mixed> $rev baris transaksi pembalik
     */
    public static function assertApprovable(\PDO $pdo, array $rev): void
    {
        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
        $stmt->execute([(int) $rev['reverses_id']]);
        $orig = $stmt->fetch();
        if ($orig === false || $orig['status'] !== 'DISETUJUI' || $orig['reverses_id'] !== null) {
            throw RuleViolation::field('_form', 'Transaksi yang dibalik tidak lagi berstatus disetujui, jadi pembalik tidak bisa disetujui. Tolak transaksi ini.');
        }
        if ($orig['type'] !== $rev['type'] || (int) $orig['amount'] !== (int) $rev['amount'] || ($orig['member_id'] === null ? null : (int) $orig['member_id']) !== ($rev['member_id'] === null ? null : (int) $rev['member_id'])) {
            throw RuleViolation::field('_form', 'Isi pembalik tidak sama dengan transaksi yang dibalik (jenis, anggota, atau nominal), jadi tidak bisa disetujui. Tolak lalu ajukan ulang.');
        }
        $stmt = $pdo->prepare("SELECT doc_no FROM transactions WHERE reverses_id = ? AND status = 'DISETUJUI' AND id <> ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([(int) $orig['id'], (int) $rev['id']]);
        if (($other = $stmt->fetchColumn()) !== false) {
            throw RuleViolation::field('_form', "Transaksi asal sudah dibalik oleh {$other}, jadi pembalik ini tidak bisa disetujui. Tolak transaksi ini.");
        }
        self::assertPeriodOpen($pdo, (int) $orig['period_month_id']);

        if ($rev['type'] === 'ANGSURAN') {
            // Alokasi pembalik harus persis kebalikan alokasi asal (kalau tidak, cicilan tidak terbuka dengan benar).
            $stmt = $pdo->prepare('SELECT installment_id, amount FROM installment_payments WHERE transaction_id = ? ORDER BY installment_id');
            $stmt->execute([(int) $orig['id']]);
            $want = array_map(static fn (array $r): array => [(int) $r['installment_id'], -(int) $r['amount']], $stmt->fetchAll());
            $stmt->execute([(int) $rev['id']]);
            $have = array_map(static fn (array $r): array => [(int) $r['installment_id'], (int) $r['amount']], $stmt->fetchAll());
            if ($want === [] || $want !== $have) {
                throw RuleViolation::field('_form', 'Pembagian pembalik tidak sama dengan kebalikan pembagian pembayaran asal, jadi tidak bisa disetujui. Tolak lalu ajukan ulang.');
            }
        }
        self::assertFeasible($pdo, $orig);
    }

    // ------------------------------------------------------------------ aturan keadaan

    /**
     * Apakah membalik transaksi asal ini membuat keadaan sah? Membaca keadaan terkini; dipakai untuk penolakan dini
     * saat diajukan dan sebagai penentu saat disetujui (di bawah kunci kas).
     * @param array<string,mixed> $orig baris transaksi asal: id, type, member_id, amount
     */
    public static function assertFeasible(\PDO $pdo, array $orig): void
    {
        $amount = (int) $orig['amount'];
        switch ((string) $orig['type']) {
            case 'SIMPANAN':
                $stmt = $pdo->prepare('SELECT savings_balance FROM v_member_savings WHERE member_id = ?');
                $stmt->execute([(int) $orig['member_id']]);
                $balance = (int) $stmt->fetchColumn();
                if ($balance < $amount) {
                    throw RuleViolation::field('_form', 'Saldo tabungan anggota sekarang ' . Money::format($balance) . ', lebih kecil dari simpanan yang akan dibalik (' . Money::format($amount) . '). Membalik akan membuat saldo negatif.');
                }
                self::assertCashCovers($pdo, $amount);
                break;

            case 'ANGSURAN':
                self::assertCashCovers($pdo, $amount);
                break;

            case 'PENCAIRAN_PINJAMAN':
                $stmt = $pdo->prepare('SELECT loan_id, paid FROM v_loan_balances WHERE transaction_id = ?');
                $stmt->execute([(int) $orig['id']]);
                $loan = $stmt->fetch();
                if ($loan === false) {
                    throw RuleViolation::field('_form', 'Pinjaman ini sudah tidak berlaku, jadi tidak bisa dibalik.');
                }
                if ((int) $loan['paid'] !== 0) {
                    throw RuleViolation::field('_form', 'Pinjaman ini sudah dibayar ' . Money::format((int) $loan['paid']) . '. Balik dulu pembayaran angsurannya, baru pencairannya.');
                }
                $stmt = $pdo->prepare("SELECT COUNT(DISTINCT pt.id) FROM installment_payments ip
                                       JOIN loan_installments li ON li.id = ip.installment_id
                                       JOIN transactions pt ON pt.id = ip.transaction_id AND pt.status = 'MENUNGGU_VALIDASI' AND pt.deleted_at IS NULL
                                       WHERE li.loan_id = ?");
                $stmt->execute([(int) $loan['loan_id']]);
                if ((int) $stmt->fetchColumn() > 0) {
                    throw RuleViolation::field('_form', 'Ada pembayaran untuk pinjaman ini yang masih menunggu validasi. Tunggu keputusannya atau batalkan dulu, baru pinjamannya dibalik.');
                }
                break;

            default:
                // PENARIKAN dan BIAYA: membalik hanya menambah kas (dan tabungan), tidak ada yang perlu dijaga.
                break;
        }
    }

    /** Kas yang akan keluar karena pembalik harus ada, kecuali pengaturan mengizinkan kas negatif. */
    private static function assertCashCovers(\PDO $pdo, int $amount): void
    {
        if ((bool) SettingsService::get('allow_negative_cash')) {
            return;
        }
        $cash = (int) $pdo->query('SELECT kas_tersedia FROM v_global_summary')->fetchColumn();
        if ($amount > $cash) {
            throw RuleViolation::field('_form', 'Kas tersedia ' . Money::format($cash) . ' tidak cukup untuk menarik kembali ' . Money::format($amount) . ' (uangnya sudah dipinjamkan). Tunggu kas bertambah.');
        }
    }

    private static function assertPeriodOpen(\PDO $pdo, int $periodMonthId): void
    {
        $stmt = $pdo->prepare('SELECT p.status FROM period_months pm JOIN periods p ON p.id = pm.period_id WHERE pm.id = ?');
        $stmt->execute([$periodMonthId]);
        if ($stmt->fetchColumn() !== 'AKTIF') {
            throw RuleViolation::field('_form', 'Periode transaksi ini sudah ditutup, jadi tidak bisa dikoreksi.');
        }
    }

    /**
     * Transaksi milik regu pengaju: dicatat atas nama regunya, atau anggotanya sekarang di regu itu.
     * @param array<string,mixed> $trx
     */
    private static function inTeam(\PDO $pdo, int $teamId, array $trx): bool
    {
        if ($trx['team_id'] !== null && (int) $trx['team_id'] === $teamId) {
            return true;
        }
        return $trx['member_id'] !== null && MemberService::currentTeamId($pdo, (int) $trx['member_id']) === $teamId;
    }

    /** Salin rincian jenis ke pembalik (alokasi angsuran dibalik tandanya). Pembalik masih DRAFT saat ini. */
    private static function copyDetails(\PDO $pdo, string $type, int $fromId, int $toId): void
    {
        switch ($type) {
            case 'SIMPANAN':
                $pdo->prepare('INSERT INTO savings (transaction_id, kind) SELECT ?, kind FROM savings WHERE transaction_id = ?')->execute([$toId, $fromId]);
                break;
            case 'BIAYA':
                $pdo->prepare('INSERT INTO expenses (transaction_id, category, fund_source) SELECT ?, category, fund_source FROM expenses WHERE transaction_id = ?')->execute([$toId, $fromId]);
                break;
            case 'ANGSURAN':
                $pdo->prepare('INSERT INTO installment_payments (transaction_id, installment_id, amount) SELECT ?, installment_id, -amount FROM installment_payments WHERE transaction_id = ?')->execute([$toId, $fromId]);
                break;
            default:
                break;   // PENCAIRAN_PINJAMAN: tidak ada rincian; pinjaman tidak lagi efektif begitu pembalik disetujui
        }
    }
}
