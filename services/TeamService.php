<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Core\Validator;

/** Aturan data regu (Data Ketua Regu). */
final class TeamService
{
    /**
     * @param array<string,mixed> $in
     * @return array{0:array<string,string>,1:array<string,mixed>}
     */
    public static function parse(array $in): array
    {
        $data = [
            'name'             => clean_text($in['name'] ?? ''),
            'leader_member_id' => (int) ($in['leader_member_id'] ?? 0),
            'is_active'        => array_key_exists('is_active', $in) ? (empty($in['is_active']) ? 0 : 1) : 1,
        ];
        $errors = Validator::validate(['name' => $data['name']], ['name' => 'required|max:100'], ['name' => 'Nama regu']);
        if ($data['leader_member_id'] <= 0) {
            $errors['leader_member_id'] = 'Ketua regu wajib dipilih.';
        }
        return [$errors, $data];
    }

    /** @param array<string,mixed> $data @param array{id:int,username:string,roles:array<int,string>} $actor */
    public static function create(Request $request, array $actor, array $data): int
    {
        return (int) Database::transaction(static function (\PDO $pdo) use ($request, $actor, $data): int {
            self::assertNameFree($pdo, $data['name'], null);
            $leader = self::assertLeaderEligible($pdo, (int) $data['leader_member_id']);

            $pdo->prepare('INSERT INTO team_leaders (name, leader_member_id) VALUES (?, ?)')->execute([$data['name'], $leader['id']]);
            $id = (int) $pdo->lastInsertId();
            // Ketua regu otomatis menjadi anggota regunya sendiri (sama seperti data Excel).
            MemberService::assignTeam($pdo, (int) $leader['id'], $id, date('Y-m-d'));

            AuditLog::record($request, $actor, 'TEAM_CREATED', 'team', $id, $data['name'], null,
                ['name' => $data['name'], 'leader_member_id' => (int) $leader['id'], 'leader' => $leader['member_no']], $pdo);
            return $id;
        });
    }

    /**
     * @param array<string,mixed> $data
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     */
    public static function update(Request $request, array $actor, int $id, array $data, string $version): void
    {
        Database::transaction(static function (\PDO $pdo) use ($request, $actor, $id, $data, $version): void {
            $stmt = $pdo->prepare('SELECT * FROM team_leaders WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw RuleViolation::field('_form', 'Regu tidak ditemukan.');
            }
            if ((string) $row['updated_at'] !== $version) {
                throw RuleViolation::field('_form', 'Data ini baru saja diubah oleh pengguna lain. Muat ulang halaman lalu ulangi perubahan Anda.');
            }

            $newLeader = (int) $data['leader_member_id'];
            if ($newLeader !== (int) $row['leader_member_id']) {
                $m = self::assertLeaderEligible($pdo, $newLeader, $id);
                // Ketua baru harus sudah menjadi anggota regu ini (tidak memindahkan orang diam-diam).
                if (MemberService::currentTeamId($pdo, $newLeader) !== $id) {
                    throw RuleViolation::field('leader_member_id', "{$m['name']} belum menjadi anggota regu ini. Pindahkan dulu lewat Data Anggota.");
                }
            }
            if ($data['name'] !== $row['name']) {
                self::assertNameFree($pdo, $data['name'], $id);
            }
            if ((int) $row['is_active'] === 1 && (int) $data['is_active'] === 0) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM member_team_assignments WHERE team_id = ? AND valid_to IS NULL AND member_id <> ?');
                $stmt->execute([$id, $row['leader_member_id']]);
                $others = (int) $stmt->fetchColumn();
                if ($others > 0) {
                    throw RuleViolation::field('is_active', "Regu masih punya {$others} anggota selain ketua. Pindahkan mereka dulu.");
                }
            }

            $before = [];
            $after  = [];
            foreach (['name' => $row['name'], 'leader_member_id' => (int) $row['leader_member_id'], 'is_active' => (int) $row['is_active']] as $field => $old) {
                $new = $field === 'name' ? $data['name'] : (int) $data[$field];
                if ($new !== $old) {
                    $before[$field] = $old;
                    $after[$field]  = $new;
                }
            }
            if ($after !== []) {
                $pdo->prepare('UPDATE team_leaders SET name = ?, leader_member_id = ?, is_active = ? WHERE id = ?')
                    ->execute([$data['name'], $newLeader, (int) $data['is_active'], $id]);
                AuditLog::record($request, $actor, 'TEAM_UPDATED', 'team', $id, (string) $row['name'], $before, $after, $pdo);
            }
        });
    }

    private static function assertNameFree(\PDO $pdo, string $name, ?int $exceptId): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM team_leaders WHERE name = ? AND is_active = 1 AND id <> ?');
        $stmt->execute([$name, $exceptId ?? 0]);
        if ($stmt->fetchColumn() !== false) {
            throw RuleViolation::field('name', 'Sudah ada regu aktif dengan nama ini.');
        }
    }

    /** @return array{id:int,name:string,member_no:string} */
    private static function assertLeaderEligible(\PDO $pdo, int $memberId, ?int $teamId = null): array
    {
        $stmt = $pdo->prepare("SELECT id, name, member_no, status FROM members WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$memberId]);
        $m = $stmt->fetch();
        if ($m === false) {
            throw RuleViolation::field('leader_member_id', 'Anggota tidak ditemukan.');
        }
        if ($m['status'] !== 'AKTIF') {
            throw RuleViolation::field('leader_member_id', 'Ketua regu harus anggota yang aktif.');
        }
        $stmt = $pdo->prepare('SELECT name FROM team_leaders WHERE leader_member_id = ? AND id <> ?');
        $stmt->execute([$memberId, $teamId ?? 0]);
        $other = $stmt->fetchColumn();
        if ($other !== false) {
            throw RuleViolation::field('leader_member_id', "{$m['name']} sudah menjadi ketua {$other}.");
        }
        return ['id' => (int) $m['id'], 'name' => (string) $m['name'], 'member_no' => (string) $m['member_no']];
    }
}
