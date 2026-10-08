<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Core\Validator;

/**
 * Aturan data anggota. Semua perubahan terjadi dalam satu transaksi database, bersama catatan audit
 * (sebelum/sesudah), dan dibatalkan seluruhnya bila ada pelanggaran aturan.
 */
final class MemberService
{
    public const STATUSES = ['AKTIF', 'NONAKTIF'];

    /**
     * Baca dan validasi isian formulir.
     * @param array<string,mixed> $in
     * @return array{0:array<string,string>,1:array<string,mixed>} [galat, data bersih]
     */
    public static function parse(array $in): array
    {
        $address = clean_text($in['address_block'] ?? '');
        $notes   = clean_text($in['notes'] ?? '');
        $data = [
            'name'           => clean_text($in['name'] ?? ''),
            'address_block'  => $address === '' ? null : $address,
            'active_from'    => trim((string) ($in['active_from'] ?? '')),
            'status'         => (string) ($in['status'] ?? 'AKTIF'),
            'is_manager'     => empty($in['is_manager']) ? 0 : 1,
            'reserve_exempt' => empty($in['reserve_exempt']) ? 0 : 1,
            'notes'          => $notes === '' ? null : $notes,
            'team_id'        => (int) ($in['team_id'] ?? 0),
        ];
        $errors = Validator::validate(
            ['name' => $data['name'], 'address_block' => $address, 'active_from' => $data['active_from'], 'status' => $data['status'], 'notes' => $notes],
            [
                'name'          => 'required|max:100',
                'address_block' => 'max:40',
                'active_from'   => 'required|regex:/^\d{4}-(0[1-9]|1[0-2])$/',
                'status'        => 'required|in:' . implode(',', self::STATUSES),
                'notes'         => 'max:255',
            ],
            ['name' => 'Nama', 'address_block' => 'Blok / alamat', 'active_from' => 'Aktif sejak', 'status' => 'Status', 'notes' => 'Catatan'],
        );
        if ($data['team_id'] <= 0) {
            $errors['team_id'] = 'Regu wajib dipilih.';
        }
        if (!isset($errors['active_from'])) {
            $data['active_from'] .= '-01';
        }
        return [$errors, $data];
    }

    /** @param array<string,mixed> $data @param array{id:int,username:string,roles:array<int,string>} $actor */
    public static function create(Request $request, array $actor, array $data): int
    {
        return (int) Database::transaction(static function (\PDO $pdo) use ($request, $actor, $data): int {
            self::assertTeamActive($pdo, (int) $data['team_id']);
            self::assertNotDuplicate($pdo, $data, null);

            $memberNo = NumberSequence::nextMemberNo($pdo);
            $pdo->prepare('INSERT INTO members (member_no, name, address_block, active_from, status, is_manager, reserve_exempt, notes) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$memberNo, $data['name'], $data['address_block'], $data['active_from'], $data['status'], $data['is_manager'], $data['reserve_exempt'], $data['notes']]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (?,?,?)')
                ->execute([$id, $data['team_id'], $data['active_from']]);

            AuditLog::record($request, $actor, 'MEMBER_CREATED', 'member', $id, $memberNo, null, $data + ['member_no' => $memberNo], $pdo);
            return $id;
        });
    }

    /**
     * @param array<string,mixed> $data
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     * @param string $version nilai updated_at saat formulir dibuka (deteksi edit bersamaan)
     */
    public static function update(Request $request, array $actor, int $id, array $data, string $version): void
    {
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $data, $version): void {
            $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw RuleViolation::field('_form', 'Anggota tidak ditemukan.');
            }
            if ((string) $row['updated_at'] !== $version) {
                throw RuleViolation::field('_form', 'Data ini baru saja diubah oleh pengguna lain. Muat ulang halaman lalu ulangi perubahan Anda.');
            }

            $currentTeam = self::currentTeamId($pdo, $id);
            $teamChanged = $currentTeam !== (int) $data['team_id'];
            if ($teamChanged) {
                self::assertTeamActive($pdo, (int) $data['team_id']);
                if (self::leadsActiveTeam($pdo, $id)) {
                    throw RuleViolation::field('team_id', 'Ketua regu tidak bisa dipindahkan. Ganti ketua regunya dulu di Data Ketua Regu.');
                }
            }
            if ($row['status'] === 'AKTIF' && $data['status'] === 'NONAKTIF') {
                if (self::leadsActiveTeam($pdo, $id)) {
                    throw RuleViolation::field('status', 'Ketua regu tidak bisa dinonaktifkan. Ganti ketua regunya dulu.');
                }
                $out = self::outstandingLoan($pdo, $id);
                if ($out > 0) {
                    throw RuleViolation::field('status', 'Masih punya pinjaman berjalan (sisa ' . money($out) . '). Selesaikan dulu sebelum menonaktifkan.');
                }
            }
            self::assertNotDuplicate($pdo, $data, $id);

            $before = [];
            $after  = [];
            foreach (['name', 'address_block', 'active_from', 'status', 'is_manager', 'reserve_exempt', 'notes'] as $field) {
                if ((string) ($row[$field] ?? '') !== (string) ($data[$field] ?? '')) {
                    $before[$field] = $row[$field];
                    $after[$field]  = $data[$field];
                }
            }
            if ($after !== []) {
                $pdo->prepare('UPDATE members SET name=?, address_block=?, active_from=?, status=?, is_manager=?, reserve_exempt=?, notes=? WHERE id=?')
                    ->execute([$data['name'], $data['address_block'], $data['active_from'], $data['status'], $data['is_manager'], $data['reserve_exempt'], $data['notes'], $id]);
            }
            if ($after !== []) {
                AuditLog::record($request, $actor, 'MEMBER_UPDATED', 'member', $id, (string) $row['member_no'], $before, $after, $pdo);
            }
            if ($teamChanged) {
                self::assignTeam($pdo, $id, (int) $data['team_id'], date('Y-m-d'));
                AuditLog::record($request, $actor, 'MEMBER_TEAM_CHANGED', 'member', $id, (string) $row['member_no'],
                    ['team_id' => $currentTeam], ['team_id' => (int) $data['team_id'], 'effective' => date('Y-m-d')], $pdo);
            }
        });
    }

    /**
     * Pindahkan anggota ke regu lain mulai tanggal $effective, dengan riwayat utuh.
     * Pindah di hari yang sama dengan penugasan sebelumnya dianggap koreksi (baris yang sama diubah).
     */
    public static function assignTeam(\PDO $pdo, int $memberId, int $teamId, string $effective): void
    {
        $stmt = $pdo->prepare('SELECT id, team_id, valid_from FROM member_team_assignments WHERE member_id = ? AND valid_to IS NULL FOR UPDATE');
        $stmt->execute([$memberId]);
        $cur = $stmt->fetch();

        if ($cur !== false) {
            if ((int) $cur['team_id'] === $teamId) {
                return;
            }
            if ((string) $cur['valid_from'] >= $effective) {
                $pdo->prepare('UPDATE member_team_assignments SET team_id = ? WHERE id = ?')->execute([$teamId, $cur['id']]);
                return;
            }
            $pdo->prepare('UPDATE member_team_assignments SET valid_to = DATE_SUB(?, INTERVAL 1 DAY) WHERE id = ?')->execute([$effective, $cur['id']]);
        }
        $pdo->prepare('INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (?,?,?)')->execute([$memberId, $teamId, $effective]);
    }

    public static function currentTeamId(\PDO $pdo, int $memberId): ?int
    {
        $stmt = $pdo->prepare('SELECT team_id FROM member_team_assignments WHERE member_id = ? AND valid_to IS NULL');
        $stmt->execute([$memberId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    private static function leadsActiveTeam(\PDO $pdo, int $memberId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM team_leaders WHERE leader_member_id = ? AND is_active = 1');
        $stmt->execute([$memberId]);
        return $stmt->fetchColumn() !== false;
    }

    private static function outstandingLoan(\PDO $pdo, int $memberId): int
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(outstanding), 0) FROM v_loan_balances WHERE member_id = ?');
        $stmt->execute([$memberId]);
        return (int) $stmt->fetchColumn();
    }

    private static function assertTeamActive(\PDO $pdo, int $teamId): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM team_leaders WHERE id = ? AND is_active = 1');
        $stmt->execute([$teamId]);
        if ($stmt->fetchColumn() === false) {
            throw RuleViolation::field('team_id', 'Regu tidak ditemukan atau tidak aktif.');
        }
    }

    /** @param array<string,mixed> $data */
    private static function assertNotDuplicate(\PDO $pdo, array $data, ?int $exceptId): void
    {
        $stmt = $pdo->prepare("SELECT member_no FROM members WHERE name = ? AND COALESCE(address_block, '') = ? AND deleted_at IS NULL AND id <> ? LIMIT 1");
        $stmt->execute([$data['name'], (string) ($data['address_block'] ?? ''), $exceptId ?? 0]);
        $dup = $stmt->fetchColumn();
        if ($dup !== false) {
            throw RuleViolation::field('name', "Anggota dengan nama dan blok yang sama sudah ada ({$dup}).");
        }
    }
}
