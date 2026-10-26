<?php
declare(strict_types=1);

/**
 * Pemeriksa integritas keuangan. Aman dijalankan kapan saja (hanya membaca).
 *   php database/tools/check_integrity.php [--db=NAMA]
 * Kode keluar 0 = bersih, 1 = ada masalah.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__, 2) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Helpers\Money;

$dbName = Config::get('database.name');
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--db=([A-Za-z0-9_]+)$/', $arg, $m)) {
        $dbName = $m[1];
    }
}

try {
    $pdo = Database::connect((string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass'), (string) $dbName);

    $g = $pdo->query('SELECT * FROM v_global_summary')->fetch();
    echo "== RINGKASAN GLOBAL ({$dbName}) ==\n";
    printf("  Saldo tabungan (Saldo Global)  %s\n", Money::format((int) $g['saldo_tabungan']));
    printf("  Kas tersedia                   %s\n", Money::format((int) $g['kas_tersedia']));
    printf("  Piutang beredar                %s\n", Money::format((int) $g['piutang_beredar']));
    printf("  Bunga dibukukan                %s\n", Money::format((int) $g['bunga_dibukukan']));
    printf("  Biaya                          %s\n", Money::format((int) $g['biaya']));
    printf("  Selisih invarian (harus 0)     %s\n", Money::format((int) $g['selisih']));

    $teams = $pdo->query(
        "SELECT tl.name, COUNT(DISTINCT a.member_id) AS anggota, COALESCE(SUM(s.savings_balance), 0) AS tabungan
         FROM team_leaders tl
         LEFT JOIN member_team_assignments a ON a.team_id = tl.id AND a.valid_to IS NULL
         LEFT JOIN v_member_savings s ON s.member_id = a.member_id
         GROUP BY tl.id, tl.name ORDER BY tl.id"
    )->fetchAll();
    if ($teams !== []) {
        echo "\n== TABUNGAN PER REGU (keanggotaan saat ini) ==\n";
        foreach ($teams as $t) {
            printf("  %-22s %3d anggota  %s\n", $t['name'], $t['anggota'], Money::format((int) $t['tabungan']));
        }
    }

    $counts = $pdo->query("SELECT status, COUNT(*) n FROM transactions GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    echo "\n== TRANSAKSI PER STATUS ==\n";
    echo $counts === [] ? "  (belum ada)\n" : '  ' . implode(', ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($counts), $counts)) . "\n";

    $issues = $pdo->query('SELECT issue, reference, detail FROM v_integrity_issues ORDER BY issue, reference')->fetchAll();

    // Pemeriksaan tambahan (migrasi 012-013), hanya bila tabelnya sudah ada.
    $has = static fn (string $t): bool => (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$t}'")->fetchColumn() === 1;
    if ($has('profit_share_configs')) {
        foreach ($pdo->query('SELECT c.id, c.effective_from, COALESCE(SUM(i.pct_hundredths), 0) AS total, SUM(i.kind = "PENABUNG") AS a, SUM(i.kind = "PEMINJAM") AS b, SUM(i.kind = "SHU") AS s FROM profit_share_configs c LEFT JOIN profit_share_config_items i ON i.config_id = c.id GROUP BY c.id, c.effective_from')->fetchAll() as $c) {
            if ((int) $c['total'] !== 10000 || (int) $c['a'] !== 1 || (int) $c['b'] !== 1 || (int) $c['s'] !== 1) {
                $issues[] = ['issue' => 'KONFIGURASI_BUNGA_TIDAK_VALID', 'reference' => (string) $c['effective_from'], 'detail' => 'total ' . (int) $c['total'] . ' (harus 10000), penabung/peminjam/SHU = ' . (int) $c['a'] . '/' . (int) $c['b'] . '/' . (int) $c['s']];
            }
        }
    }
    // Pinjaman disetujui yang sebagian cicilannya bertanggal dan sebagian tidak = penetapan tanggal tidak lengkap.
    foreach ($pdo->query("SELECT ln.id, t.doc_no FROM loan_installments li JOIN loans ln ON ln.id = li.loan_id JOIN transactions t ON t.id = ln.transaction_id AND t.status = 'DISETUJUI' GROUP BY ln.id, t.doc_no HAVING COUNT(li.due_date) > 0 AND COUNT(li.due_date) < COUNT(*)")->fetchAll() as $p) {
        $issues[] = ['issue' => 'JADWAL_TANGGAL_TIDAK_LENGKAP', 'reference' => (string) $p['doc_no'], 'detail' => 'sebagian cicilan bertanggal jatuh tempo, sebagian tidak'];
    }
    echo "\n== MASALAH INTEGRITAS ==\n";
    if ($issues === []) {
        echo "  Tidak ada. Semua pemeriksaan lolos.\n";
        exit(0);
    }
    foreach ($issues as $i) {
        printf("  %-30s %-18s %s\n", $i['issue'], $i['reference'], $i['detail']);
    }
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
