<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Gate;
use App\Core\Request;

/**
 * Bagian yang SAMA untuk semua transaksi yang dicatat Ketua Regu (simpanan, pinjaman): pemeriksaan anggota,
 * bulan, dan tanggal; penguncian + pemeriksaan pembuat dan versi; perpindahan status dengan riwayat dan audit;
 * pembatalan. Aturan khas tiap jenis ada di SavingService dan LoanService.
 *
 * Semua metode (kecuali cancel) WAJIB dipanggil di dalam transaksi database milik pemanggil.
 */
final class TransactionFlow
{
    /**
     * Anggota harus aktif dan berada di regu pencatat; bulan harus di periode aktif dan sudah berjalan;
     * tanggal tidak di masa depan dan tidak sebelum awal bulan siklus.
     *
     * @param array<string,mixed> $data member_id, period_month_id, trx_date
     * @param array{id:int,roles:array<int,string>,team_id?:?int} $actor
     * @return array{team_id:int,member_id:int,member_no:string,member_name:string,month:string,month_id:int,period_id:int}
     */
    public static function assertRecordable(\PDO $pdo, array $actor, array $data): array
    {
        if (!Gate::allows($actor, 'transaction.create')) {
            throw RuleViolation::field('_form', 'Akun Anda tidak berhak mencatat transaksi.');
        }
        $teamId = $actor['team_id'] ?? null;
        if ($teamId === null) {
            throw RuleViolation::field('_form', 'Akun Anda belum menjadi ketua regu aktif, jadi belum bisa mencatat transaksi.');
        }

        // Kunci baris anggota: dua pencatatan serentak untuk anggota yang sama diproses berurutan,
        // sehingga pemeriksaan duplikat dan batas pinjaman tidak bisa terlewati.
        $stmt = $pdo->prepare('SELECT id, member_no, name, status, active_from FROM members WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
        $stmt->execute([(int) $data['member_id']]);
        $member = $stmt->fetch();
        if ($member === false) {
            throw RuleViolation::field('member_id', 'Anggota tidak ditemukan.');
        }
        if (MemberService::currentTeamId($pdo, (int) $member['id']) !== (int) $teamId) {
            // Pesan sama untuk "anggota regu lain" dan "tidak ada": tidak membocorkan anggota regu lain.
            throw RuleViolation::field('member_id', 'Anggota ini bukan anggota regu Anda.');
        }
        if ($member['status'] !== 'AKTIF') {
            throw RuleViolation::field('member_id', 'Anggota nonaktif tidak bisa menerima transaksi baru.');
        }

        $stmt = $pdo->prepare("SELECT pm.id, pm.period_id, pm.month_date
                               FROM period_months pm JOIN periods p ON p.id = pm.period_id
                               WHERE pm.id = ? AND p.status = 'AKTIF'");
        $stmt->execute([(int) $data['period_month_id']]);
        $month = $stmt->fetch();
        if ($month === false) {
            throw RuleViolation::field('period_month_id', 'Bulan tidak ditemukan atau periodenya sudah ditutup.');
        }
        if ((string) $month['month_date'] > date('Y-m-01')) {
            throw RuleViolation::field('period_month_id', 'Belum bisa mencatat transaksi untuk bulan yang belum berjalan.');
        }
        if ((string) $month['month_date'] < (string) $member['active_from']) {
            throw RuleViolation::field('period_month_id', 'Anggota ini baru aktif sejak ' . month_label((string) $member['active_from']) . '; tidak bisa dicatat untuk bulan sebelumnya.');
        }

        $date = (string) $data['trx_date'];
        if ($date > date('Y-m-d')) {
            throw RuleViolation::field('trx_date', 'Tanggal tidak boleh di masa depan.');
        }
        if ($date < (string) $month['month_date']) {
            throw RuleViolation::field('trx_date', 'Tanggal tidak boleh sebelum awal bulan ' . month_label((string) $month['month_date']) . '.');
        }

        return [
            'team_id' => (int) $teamId, 'member_id' => (int) $member['id'], 'member_no' => (string) $member['member_no'], 'member_name' => (string) $member['name'],
            'month' => (string) $month['month_date'], 'month_id' => (int) $month['id'], 'period_id' => (int) $month['period_id'],
        ];
    }

    /**
     * Ambil dan kunci transaksi; pastikan pemanggil adalah pembuatnya dan versi data masih terbaru.
     * @param array{id:int,roles:array<int,string>} $actor
     * @return array<string,mixed>
     */
    public static function lockOwned(\PDO $pdo, array $actor, int $id, string $version, string $type): array
    {
        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ? AND type = ? AND deleted_at IS NULL FOR UPDATE');
        $stmt->execute([$id, $type]);
        $trx = $stmt->fetch();
        if ($trx === false) {
            throw RuleViolation::field('_form', 'Transaksi tidak ditemukan.');
        }
        if (!Gate::allows($actor, 'transaction.create') || (int) $trx['created_by'] !== (int) $actor['id']) {
            throw RuleViolation::field('_form', 'Hanya pembuat transaksi yang boleh mengubah, mengajukan, atau membatalkannya.');
        }
        if ((string) $trx['updated_at'] !== $version) {
            throw RuleViolation::field('_form', 'Transaksi ini baru saja berubah. Muat ulang halaman lalu ulangi.');
        }
        return $trx;
    }

    /**
     * Pindahkan status + catat riwayat validasi + audit. Trigger database menolak lompatan status yang tidak sah.
     * @param array{id:int|string,doc_no:string,status:string} $trx
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     */
    public static function move(\PDO $pdo, Request $request, array $actor, array $trx, string $to, ?string $note, string $auditAction): void
    {
        $id = (int) $trx['id'];
        $pdo->prepare('UPDATE transactions SET status = ?, status_changed_at = NOW() WHERE id = ? AND status = ?')->execute([$to, $id, $trx['status']]);
        $pdo->prepare('INSERT INTO transaction_validations (transaction_id, from_status, to_status, actor_user_id, note) VALUES (?,?,?,?,?)')
            ->execute([$id, $trx['status'], $to, $actor['id'], $note]);
        AuditLog::record($request, $actor, $auditAction, 'transaction', $id, (string) $trx['doc_no'],
            ['status' => $trx['status']], ['status' => $to] + ($note === null ? [] : ['note' => $note]), $pdo);
    }

    /**
     * Batalkan draft atau transaksi yang menunggu validasi, dengan alasan. Yang sudah disetujui tidak bisa
     * dibatalkan di sini (koreksinya lewat transaksi pembalik).
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     */
    public static function cancel(Request $request, array $actor, int $id, string $reason, string $version, string $type, string $auditAction): void
    {
        $reason = clean_text($reason);
        if ($reason === '') {
            throw RuleViolation::field('note', 'Alasan pembatalan wajib diisi.');
        }
        if (mb_strlen($reason) > 200) {
            throw RuleViolation::field('note', 'Alasan pembatalan maksimal 200 karakter.');
        }
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $reason, $version, $type, $auditAction): void {
            $trx = self::lockOwned($pdo, $actor, $id, $version, $type);
            if (!in_array($trx['status'], ['DRAFT', 'MENUNGGU_VALIDASI'], true)) {
                throw RuleViolation::field('_form', 'Transaksi ini sudah ' . self::statusPhrase((string) $trx['status']) . ', tidak bisa dibatalkan.');
            }
            self::move($pdo, $request, $actor, $trx, 'DIBATALKAN', $reason, $auditAction);
        });
    }

    public static function statusPhrase(string $status): string
    {
        return match ($status) {
            'MENUNGGU_VALIDASI' => 'diajukan ke validasi',
            'DISETUJUI'         => 'disetujui',
            'DITOLAK'           => 'ditolak',
            'DIBATALKAN'        => 'dibatalkan',
            default             => 'berstatus ' . strtolower($status),
        };
    }
}
