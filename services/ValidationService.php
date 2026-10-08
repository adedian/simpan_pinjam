<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Gate;
use App\Core\Request;
use App\Helpers\Money;

/**
 * Validasi transaksi (Setujui / Tolak). Satu-satunya jalan transaksi menjadi DISETUJUI di aplikasi, dan hanya
 * DISETUJUI yang masuk saldo. Semuanya terjadi dalam satu transaksi database bersama riwayat status dan audit.
 *
 * Pemisahan tugas:
 *  - pembuat tidak boleh memvalidasi transaksinya sendiri;
 *  - tidak boleh memvalidasi transaksi atas nama sendiri, atau transaksi regu yang ia pimpin;
 *  - transaksi milik Head, regu yang dipimpin Head, atau dibuat Head HANYA boleh divalidasi PEMERIKSA (Q3).
 *
 * Penyetujuan memeriksa ulang keadaan terkini (tidak percaya pada apa yang benar saat diajukan):
 *  - pencairan dan pengeluaran kas: kas tersedia cukup, diperiksa SECARA ATOMIK (kunci global kas) sehingga dua
 *    persetujuan serentak tidak bisa sama-sama memakai kas yang sama (Q6);
 *  - anggota masih aktif; jadwal pinjaman utuh; pembagian angsuran sah dan tidak melebihi tagihan.
 *  - transaksi pembalik (Phase 10): aturannya di ReversalService::assertApprovable.
 * Penolakan wajib beralasan. DISETUJUI dan DITOLAK final.
 */
final class ValidationService
{
    /**
     * Potongan SQL (alias transaksi `t`): transaksi terkait Head = dibuat oleh Head, atas nama anggota yang
     * tertaut ke akun Head, atau milik regu yang dipimpin anggota yang tertaut ke akun Head.
     */
    public const HEAD_RELATED_SQL = "EXISTS (
        SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id AND r.code = 'HEAD'
        WHERE ur.user_id = t.created_by
           OR ur.user_id IN (SELECT u.id FROM users u WHERE u.deleted_at IS NULL AND u.member_id IS NOT NULL
                              AND (u.member_id = t.member_id OR u.member_id = (SELECT tl.leader_member_id FROM team_leaders tl WHERE tl.id = t.team_id))))";

    /**
     * Apakah pengguna ini boleh memvalidasi transaksi ini? (Tidak mengubah apa pun.)
     *
     * @param array{id?:int,roles?:array<int,string>,member_id?:?int} $user
     * @param array<string,mixed> $trx baris transaksi: created_by, member_id, team_id (+ leader_member_id opsional)
     * @return array{eligible:bool,reason:?string,head_related:bool}
     */
    public static function assess(array $user, array $trx, bool $headRelated, ?int $teamLeaderMemberId): array
    {
        $deny = static fn (string $why): array => ['eligible' => false, 'reason' => $why, 'head_related' => $headRelated];

        if (!Gate::allows($user, 'transaction.validate')) {
            return $deny('Akun Anda tidak berhak memvalidasi transaksi.');
        }
        if ($trx['created_by'] !== null && (int) $trx['created_by'] === (int) ($user['id'] ?? 0)) {
            return $deny('Anda pembuat transaksi ini. Validasi harus dilakukan orang lain.');
        }
        $myMember = $user['member_id'] ?? null;
        if ($myMember !== null && $trx['member_id'] !== null && (int) $trx['member_id'] === (int) $myMember) {
            return $deny('Transaksi ini atas nama Anda sendiri. Validasi harus dilakukan orang lain.');
        }
        if ($myMember !== null && $teamLeaderMemberId !== null && (int) $myMember === $teamLeaderMemberId) {
            return $deny('Transaksi ini milik regu yang Anda pimpin. Validasi harus dilakukan orang lain.');
        }
        if ($headRelated && !in_array('PEMERIKSA', (array) ($user['roles'] ?? []), true)) {
            return $deny('Transaksi milik Head, regu Head, atau dibuat Head hanya boleh divalidasi Pemeriksa.');
        }
        return ['eligible' => true, 'reason' => null, 'head_related' => $headRelated];
    }

    /**
     * Penilaian untuk tampilan (membaca dari database).
     * @param array{id?:int,roles?:array<int,string>,member_id?:?int} $user
     * @param array<string,mixed> $trx baris transaksi dengan kolom id, created_by, member_id, team_id
     * @return array{eligible:bool,reason:?string,head_related:bool}
     */
    public static function assessFor(array $user, array $trx): array
    {
        $row = Database::select('SELECT ' . self::HEAD_RELATED_SQL . ' AS head_related, (SELECT tl.leader_member_id FROM team_leaders tl WHERE tl.id = t.team_id) AS leader_member_id FROM transactions t WHERE t.id = ?', [(int) $trx['id']])[0] ?? null;
        if ($row === null) {
            return ['eligible' => false, 'reason' => 'Transaksi tidak ditemukan.', 'head_related' => false];
        }
        return self::assess($user, $trx, (bool) $row['head_related'], $row['leader_member_id'] === null ? null : (int) $row['leader_member_id']);
    }

    /** Ada minimal satu akun Pemeriksa aktif? (Tanpa itu transaksi terkait Head tidak bisa divalidasi.) */
    public static function hasActiveChecker(): bool
    {
        return Database::select("SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.code = 'PEMERIKSA'
                                  WHERE u.is_active = 1 AND u.deleted_at IS NULL LIMIT 1") !== [];
    }

    /** Kas tersedia saat ini (hanya transaksi DISETUJUI). */
    public static function cash(): int
    {
        return (int) Database::select('SELECT kas_tersedia FROM v_global_summary')[0]['kas_tersedia'];
    }

    // ------------------------------------------------------------------ perintah

    /**
     * Setujui transaksi. Catatan opsional.
     * @param array{id:int,username:string,roles:array<int,string>,member_id?:?int} $actor
     */
    public static function approve(Request $request, array $actor, int $id, string $version, ?string $note): void
    {
        $note = self::cleanNote($note, false);
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $version, $note): void {
            // Kunci global KAS diambil PERTAMA, sebelum pembacaan biasa apa pun: dalam REPEATABLE READ, potret data
            // dibuat pada pembacaan biasa pertama, jadi kunci yang diambil belakangan akan membaca kas yang basi.
            self::lockCash($pdo);
            $trx = self::lockPending($pdo, $actor, $id, $version);
            self::assertApprovable($pdo, $trx);
            TransactionFlow::move($pdo, $request, $actor, $trx, 'DISETUJUI', $note, 'TRX_APPROVED');
        });
    }

    /**
     * Tolak transaksi. Alasan wajib. Transaksi yang ditolak final; pembuat harus membuat transaksi baru.
     * @param array{id:int,username:string,roles:array<int,string>,member_id?:?int} $actor
     */
    public static function reject(Request $request, array $actor, int $id, string $version, ?string $note): void
    {
        $note = self::cleanNote($note, true);
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $version, $note): void {
            $trx = self::lockPending($pdo, $actor, $id, $version);
            TransactionFlow::move($pdo, $request, $actor, $trx, 'DITOLAK', $note, 'TRX_REJECTED');
        });
    }

    // ------------------------------------------------------------------ internal

    /** Satu baris pengaturan dipakai sebagai kunci global kas; dilepas saat transaksi database selesai. */
    private static function lockCash(\PDO $pdo): void
    {
        $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'allow_negative_cash' FOR UPDATE")->fetchAll();
    }

    private static function cleanNote(?string $note, bool $required): ?string
    {
        $note = clean_text($note ?? '');
        if ($required && $note === '') {
            throw RuleViolation::field('note', 'Alasan penolakan wajib diisi.');
        }
        if (mb_strlen($note) > 200) {
            throw RuleViolation::field('note', 'Catatan maksimal 200 karakter.');
        }
        return $note === '' ? null : $note;
    }

    /**
     * Kunci transaksi, pastikan masih MENUNGGU_VALIDASI dan versinya yang dilihat validator, lalu periksa
     * kewenangan (otoritatif; tampilan hanya cermin dari ini).
     * @param array{id:int,roles:array<int,string>,member_id?:?int} $actor
     * @return array<string,mixed>
     */
    private static function lockPending(\PDO $pdo, array $actor, int $id, string $version): array
    {
        $stmt = $pdo->prepare('SELECT t.* FROM transactions t WHERE t.id = ? AND t.deleted_at IS NULL FOR UPDATE');
        $stmt->execute([$id]);
        $trx = $stmt->fetch();
        if ($trx === false) {
            throw RuleViolation::field('_form', 'Transaksi tidak ditemukan.');
        }
        if ($trx['status'] !== 'MENUNGGU_VALIDASI') {
            throw RuleViolation::field('_form', 'Transaksi ini sudah ' . TransactionFlow::statusPhrase((string) $trx['status']) . ', tidak ada yang perlu divalidasi.');
        }
        if ((string) $trx['updated_at'] !== $version) {
            throw RuleViolation::field('_form', 'Transaksi ini baru saja berubah. Muat ulang halaman, periksa lagi, lalu putuskan.');
        }

        $stmt = $pdo->prepare('SELECT ' . self::HEAD_RELATED_SQL . ' AS head_related, (SELECT tl.leader_member_id FROM team_leaders tl WHERE tl.id = t.team_id) AS leader_member_id FROM transactions t WHERE t.id = ?');
        $stmt->execute([$id]);
        $meta = $stmt->fetch();
        $verdict = self::assess($actor, $trx, (bool) $meta['head_related'], $meta['leader_member_id'] === null ? null : (int) $meta['leader_member_id']);
        if (!$verdict['eligible']) {
            throw RuleViolation::field('_form', (string) $verdict['reason']);
        }
        return $trx;
    }

    /**
     * Pemeriksaan keadaan terkini sebelum DISETUJUI.
     * @param array<string,mixed> $trx
     */
    private static function assertApprovable(\PDO $pdo, array $trx): void
    {
        $type   = (string) $trx['type'];
        $amount = (int) $trx['amount'];

        if ($trx['reverses_id'] !== null) {
            // Pembalik: aturannya sendiri (anggota nonaktif tetap boleh dikoreksi; kas dan saldo dijaga di dalamnya).
            ReversalService::assertApprovable($pdo, $trx);
            return;
        }

        if (in_array($type, ['SIMPANAN', 'PENCAIRAN_PINJAMAN', 'PENARIKAN'], true)) {
            $stmt = $pdo->prepare('SELECT status FROM members WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([(int) $trx['member_id']]);
            if ($stmt->fetchColumn() !== 'AKTIF') {
                throw RuleViolation::field('_form', 'Anggota ini sudah tidak aktif, jadi transaksinya tidak bisa disetujui. Tolak transaksi ini.');
            }
        }

        if ($type === 'PENCAIRAN_PINJAMAN') {
            self::assertLoanIntact($pdo, (int) $trx['id'], $amount);
        }
        if ($type === 'ANGSURAN') {
            self::assertAllocationsSound($pdo, (int) $trx['id'], $amount);
        }

        if (in_array($type, ['PENCAIRAN_PINJAMAN', 'PENARIKAN', 'BIAYA'], true)) {
            self::assertCashCovers($pdo, $amount, $type);
        }
        if ($type === 'PENARIKAN') {
            if (!(bool) SettingsService::get('withdrawals_enabled')) {
                throw RuleViolation::field('_form', 'Penarikan tabungan sedang dinonaktifkan di Pengaturan.');
            }
            $stmt = $pdo->prepare('SELECT savings_balance FROM v_member_savings WHERE member_id = ?');
            $stmt->execute([(int) $trx['member_id']]);
            if ((int) $stmt->fetchColumn() < $amount) {
                throw RuleViolation::field('_form', 'Saldo tabungan anggota tidak cukup untuk penarikan ini.');
            }
        }
    }

    /**
     * Pengeluaran kas harus ≤ kas tersedia (kecuali pengaturan mengizinkan). Dipanggil setelah lockCash(): setiap
     * persetujuan bergiliran, dan pembacaan kas di sini melihat persetujuan sebelumnya.
     */
    private static function assertCashCovers(\PDO $pdo, int $amount, string $type): void
    {
        if ((bool) SettingsService::get('allow_negative_cash')) {
            return;
        }
        $cash = (int) $pdo->query('SELECT kas_tersedia FROM v_global_summary')->fetchColumn();
        if ($amount > $cash) {
            $what = $type === 'PENCAIRAN_PINJAMAN' ? 'Pencairan' : 'Pengeluaran';
            throw RuleViolation::field('_form', "Kas tersedia tidak cukup: {$what} " . Money::format($amount) . ' melebihi kas ' . Money::format($cash) . '. Tunggu kas bertambah, atau tolak transaksi ini.');
        }
    }

    private static function assertLoanIntact(\PDO $pdo, int $trxId, int $amount): void
    {
        $stmt = $pdo->prepare('SELECT l.id, l.principal, l.total_interest, (SELECT COALESCE(SUM(li.amount_due), 0) FROM loan_installments li WHERE li.loan_id = l.id) AS scheduled
                               FROM loans l WHERE l.transaction_id = ?');
        $stmt->execute([$trxId]);
        $loan = $stmt->fetch();
        if ($loan === false || (int) $loan['principal'] !== $amount || (int) $loan['scheduled'] !== (int) $loan['principal'] + (int) $loan['total_interest']) {
            throw RuleViolation::field('_form', 'Data pinjaman atau jadwal cicilan tidak konsisten, jadi tidak bisa disetujui. Tolak lalu minta dicatat ulang.');
        }
    }

    private static function assertAllocationsSound(\PDO $pdo, int $trxId, int $amount): void
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM installment_payments WHERE transaction_id = ?');
        $stmt->execute([$trxId]);
        if ((int) $stmt->fetchColumn() !== $amount) {
            throw RuleViolation::field('_form', 'Pembagian pembayaran ke cicilan tidak sama dengan jumlah dibayar, jadi tidak bisa disetujui. Tolak lalu minta dicatat ulang.');
        }
        // tiap cicilan: yang sudah disetujui + pembagian ini tidak boleh melebihi tagihan, dan pinjamannya harus masih efektif
        $stmt = $pdo->prepare("SELECT li.id, li.amount_due, ip.amount AS alloc,
                                      COALESCE((SELECT SUM(x.amount) FROM installment_payments x JOIN transactions xt ON xt.id = x.transaction_id AND xt.status = 'DISETUJUI' AND xt.deleted_at IS NULL
                                                WHERE x.installment_id = li.id), 0) AS paid,
                                      (SELECT COUNT(*) FROM v_loan_balances b WHERE b.loan_id = li.loan_id) AS effective
                               FROM installment_payments ip JOIN loan_installments li ON li.id = ip.installment_id
                               WHERE ip.transaction_id = ?");
        $stmt->execute([$trxId]);
        foreach ($stmt->fetchAll() as $r) {
            if ((int) $r['effective'] !== 1) {
                throw RuleViolation::field('_form', 'Pinjaman yang dibayar tidak lagi berlaku, jadi pembayaran tidak bisa disetujui. Tolak transaksi ini.');
            }
            if ((int) $r['paid'] + (int) $r['alloc'] > (int) $r['amount_due']) {
                throw RuleViolation::field('_form', 'Pembayaran ini akan melebihi tagihan sebuah cicilan (kelebihan bayar), jadi tidak bisa disetujui. Tolak lalu minta dicatat ulang.');
            }
        }
    }
}
