<?php
declare(strict_types=1);

/**
 * Penyesuaian rencana pembayaran pinjaman untuk perkiraan bagi hasil (lihat migrasi 008 dan services/ProfitShare.php).
 * Memakai akun admin database (DB_ADMIN_*); tidak ada jalur web untuk mengubahnya.
 *
 *   php database/tools/plan_adjust.php --list                         daftar penyesuaian yang ada
 *   php database/tools/plan_adjust.php --file=BERKAS.json             rencana saja (tidak menulis apa pun)
 *   php database/tools/plan_adjust.php --file=BERKAS.json --apply     tulis ke database (satu transaksi) + catatan audit
 *   php database/tools/plan_adjust.php --remove-all --apply           hapus semua penyesuaian
 *   tambahkan --db=NAMA untuk database lain (mis. database uji)
 *
 * Format berkas JSON: daftar objek. Pinjaman dikenali dari anggota, pokok, dan bulan pencairan:
 *   [{"member_no": "AGT-012", "principal": 1000000, "disbursed_month": "2026-03-01",
 *     "month": "2026-06-01", "amount": -220000, "note": "cicilan ditunda"}]
 * `amount` ditambahkan ke rencana bulan `month` (negatif = dikurangi). Baris yang sama persis dilewati; baris dengan
 * jumlah berbeda untuk pinjaman+bulan yang sama ditolak (hapus dulu dengan --remove-all).
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__, 2) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    }
}
$dbName = (string) ($options['db'] ?? Config::get('database.name'));
$apply  = isset($options['apply']);

try {
    $pdo = Database::connect((string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass'), $dbName);

    if (isset($options['list'])) {
        $rows = $pdo->query(
            "SELECT m.member_no, m.name, ln.principal, pd.month_date AS disbursed, pm.month_date AS month, a.amount, a.note
             FROM loan_plan_adjustments a JOIN loans ln ON ln.id = a.loan_id JOIN members m ON m.id = ln.member_id
             JOIN transactions t ON t.id = ln.transaction_id JOIN period_months pd ON pd.id = t.period_month_id
             JOIN period_months pm ON pm.id = a.period_month_id ORDER BY m.member_no, ln.id, pm.month_date"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            printf("%-9s %-18s pokok %10s cair %s  bulan %s  %+12d  %s\n", $r['member_no'], $r['name'], number_format((int) $r['principal'], 0, ',', '.'), substr((string) $r['disbursed'], 0, 7), substr((string) $r['month'], 0, 7), (int) $r['amount'], (string) $r['note']);
        }
        echo count($rows) . " penyesuaian.\n";
        exit(0);
    }

    if (isset($options['remove-all'])) {
        $n = (int) $pdo->query('SELECT COUNT(*) FROM loan_plan_adjustments')->fetchColumn();
        echo "{$n} penyesuaian akan dihapus.\n";
        if (!$apply) {
            echo "(Rencana saja. Tambahkan --apply untuk menghapus.)\n";
            exit(0);
        }
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM loan_plan_adjustments');
        $pdo->prepare('INSERT INTO audit_logs (action, entity_type, after_data) VALUES (?,?,?)')->execute(['PLAN_ADJUSTED', 'import', json_encode(['dihapus' => $n], JSON_UNESCAPED_UNICODE)]);
        $pdo->commit();
        echo "Dihapus.\n";
        exit(0);
    }

    $file = (string) ($options['file'] ?? '');
    if ($file === '' || !is_file($file)) {
        throw new RuntimeException('Berkas tidak ditemukan. Pakai --file=BERKAS.json (atau --list / --remove-all).');
    }
    $items = json_decode((string) file_get_contents($file), true);
    if (!is_array($items) || $items === []) {
        throw new RuntimeException('Berkas JSON kosong atau tidak sah.');
    }

    $plan = [];
    foreach ($items as $i => $it) {
        $n = $i + 1;
        $amount = $it['amount'] ?? null;
        if (!is_array($it) || !is_int($amount) || $amount === 0 || !is_int($it['principal'] ?? null)) {
            throw new RuntimeException("Butir {$n}: 'amount' dan 'principal' harus bilangan bulat dan amount tidak boleh 0.");
        }
        foreach (['disbursed_month', 'month'] as $k) {
            if (!preg_match('/^\d{4}-\d{2}-01$/', (string) ($it[$k] ?? ''))) {
                throw new RuntimeException("Butir {$n}: '{$k}' harus tanggal 1 (YYYY-MM-01).");
            }
        }
        $loan = $pdo->prepare(
            "SELECT ln.id FROM loans ln JOIN members m ON m.id = ln.member_id JOIN transactions t ON t.id = ln.transaction_id
             JOIN period_months pd ON pd.id = t.period_month_id JOIN v_loan_balances b ON b.loan_id = ln.id
             WHERE m.member_no = ? AND ln.principal = ? AND pd.month_date = ?"
        );
        $loan->execute([(string) ($it['member_no'] ?? ''), $it['principal'], $it['disbursed_month']]);
        $loanIds = $loan->fetchAll(PDO::FETCH_COLUMN);
        if (count($loanIds) !== 1) {
            throw new RuntimeException("Butir {$n}: pinjaman {$it['member_no']} pokok {$it['principal']} cair {$it['disbursed_month']} " . (count($loanIds) === 0 ? 'tidak ditemukan.' : 'tidak unik.'));
        }
        $month = $pdo->prepare('SELECT id FROM period_months WHERE month_date = ?');
        $month->execute([$it['month']]);
        $monthId = $month->fetchColumn();
        if ($monthId === false) {
            throw new RuntimeException("Butir {$n}: bulan {$it['month']} tidak ada di periode mana pun.");
        }
        $exists = $pdo->prepare('SELECT amount FROM loan_plan_adjustments WHERE loan_id = ? AND period_month_id = ?');
        $exists->execute([(int) $loanIds[0], (int) $monthId]);
        $have = $exists->fetchColumn();
        if ($have !== false && (int) $have !== $amount) {
            throw new RuntimeException("Butir {$n}: sudah ada penyesuaian berbeda ({$have}) untuk pinjaman dan bulan itu. Hapus dulu dengan --remove-all.");
        }
        $plan[] = ['loan' => (int) $loanIds[0], 'month' => (int) $monthId, 'amount' => $amount, 'note' => mb_substr((string) ($it['note'] ?? ''), 0, 255), 'skip' => $have !== false];
        printf("  %s pinjaman #%d bulan %s %+d%s\n", $have !== false ? 'sudah ada' : 'tambah   ', (int) $loanIds[0], $it['month'], $amount, $have !== false ? ' (dilewati)' : '');
    }

    $todo = array_filter($plan, static fn (array $p): bool => !$p['skip']);
    echo count($todo) . ' baris akan ditulis, ' . (count($plan) - count($todo)) . " dilewati (sudah ada).\n";
    if (!$apply) {
        echo "(Rencana saja. Tambahkan --apply untuk menulis.)\n";
        exit(0);
    }
    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT INTO loan_plan_adjustments (loan_id, period_month_id, amount, note) VALUES (?,?,?,?)');
    foreach ($todo as $p) {
        $ins->execute([$p['loan'], $p['month'], $p['amount'], $p['note'] === '' ? null : $p['note']]);
    }
    $pdo->prepare('INSERT INTO audit_logs (action, entity_type, after_data) VALUES (?,?,?)')->execute(['PLAN_ADJUSTED', 'import', json_encode(['ditulis' => count($todo)], JSON_UNESCAPED_UNICODE)]);
    $pdo->commit();
    echo "Tersimpan.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
