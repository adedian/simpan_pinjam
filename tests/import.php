<?php
declare(strict_types=1);

/**
 * Tes mesin impor Excel dengan data SINTETIS (bukan data koperasi) + uji atomisitas di database uji.
 *   C:\xampp\php\php.exe tests\import.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\ExcelImporter;
use App\Services\Migrator;

$passed = 0;
$failed = [];
function check(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        return;
    }
    $failed[] = $name;
    echo "  GAGAL  {$name}\n";
}

function dataset(callable $tweak = null): array
{
    $months = [];
    foreach (['2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08'] as $mk) {
        $months[] = ['key' => $mk, 'meeting_date' => $mk . '-05', 'location' => 'Balai'];
    }
    $d = [
        'source' => ['file' => 'sintetis'],
        'period' => ['name' => 'Uji', 'start' => '2026-03-01', 'end' => '2026-08-31'],
        'rate_pct_month' => 2,
        'months' => $months,
        'teams' => [['name' => 'Bu A', 'leader_no' => 1]],
        'members' => [
            ['no' => 1, 'name' => 'Bu A', 'address' => 'A-1', 'team' => 'Bu A', 'active_from' => '2026-03-01', 'reserve_exempt' => true, 'excel_row' => 9],
            ['no' => 2, 'name' => 'Bu B', 'address' => 'B-2', 'team' => 'Bu A', 'active_from' => '2026-03-01', 'reserve_exempt' => false, 'excel_row' => 10],
        ],
        'monthly' => [
            '2026-03' => [
                ['no' => 1, 'tabungan' => 100000, 'tarik' => null, 'pinjam' => 1000000, 'tempo' => 5, 'bayar' => null],
                ['no' => 2, 'tabungan' => 50000, 'tarik' => null, 'pinjam' => null, 'tempo' => null, 'bayar' => null],
            ],
            '2026-04' => [['no' => 1, 'tabungan' => 100000, 'tarik' => null, 'pinjam' => null, 'tempo' => null, 'bayar' => 500000]],
            '2026-05' => [
                ['no' => 1, 'tabungan' => null, 'tarik' => null, 'pinjam' => null, 'tempo' => null, 'bayar' => 160000],
                ['no' => 2, 'tabungan' => null, 'tarik' => null, 'pinjam' => 3000000, 'tempo' => 2, 'bayar' => null],
            ],
            '2026-06' => [['no' => 2, 'tabungan' => null, 'tarik' => null, 'pinjam' => null, 'tempo' => null, 'bayar' => 1560000]],
            '2026-07' => [['no' => 2, 'tabungan' => null, 'tarik' => null, 'pinjam' => null, 'tempo' => null, 'bayar' => 1560000]],
        ],
        'expenses' => [],
        'checks' => [
            'month_totals' => [
                '2026-03' => ['tabungan' => 150000, 'pinjam' => 1000000, 'bayar' => 0],
                '2026-04' => ['tabungan' => 100000, 'pinjam' => 0, 'bayar' => 500000],
                '2026-05' => ['tabungan' => 0, 'pinjam' => 3000000, 'bayar' => 160000],
                '2026-06' => ['tabungan' => 0, 'pinjam' => 0, 'bayar' => 1560000],
                '2026-07' => ['tabungan' => 0, 'pinjam' => 0, 'bayar' => 1560000],
                '2026-08' => ['tabungan' => 0, 'pinjam' => 0, 'bayar' => 0],
            ],
            'cash_closing' => ['2026-03' => -850000, '2026-04' => -250000, '2026-05' => -3090000, '2026-06' => -1530000, '2026-07' => 30000, '2026-08' => 30000],
            'member_savings' => ['1' => 200000, '2' => 50000],
            'member_outstanding' => ['1' => 440000, '2' => 0],
            'total_pinjaman' => 4000000, 'total_bunga' => 220000, 'total_biaya' => 0,
        ],
    ];
    return $tweak ? $tweak($d) : $d;
}

function failedChecks(array $plan): array
{
    return array_column(array_filter($plan['checks'], fn ($c) => !$c['ok']), 'name');
}

// ---------- rencana (tanpa database) ----------
$plan = (new ExcelImporter(dataset()))->plan();
check('plan: semua pemeriksaan lolos', failedChecks($plan) === []);
check('plan: jumlah transaksi (3 simpanan + 2 pinjaman + 4 angsuran)', count($plan['trxs']) === 9);
$alloc = [];
foreach ($plan['trxs'] as $t) {
    if ($t['type'] === 'ANGSURAN' && $t['month'] === '2026-04') {
        $alloc = $t['allocations'];
    }
}
check('plan: alokasi cicilan tertua dulu, sebagian diperbolehkan', array_map(fn ($a) => [$a['seq'], $a['amount']], $alloc) === [[1, 220000], [2, 220000], [3, 60000]]);
check('plan: pembayaran bulan berikut melanjutkan cicilan yang sebagian', (function () use ($plan): bool {
    foreach ($plan['trxs'] as $t) {
        if ($t['type'] === 'ANGSURAN' && $t['month'] === '2026-05' && $t['member_no'] === 1) {
            return array_map(fn ($a) => [$a['seq'], $a['amount']], $t['allocations']) === [[3, 160000]];
        }
    }
    return false;
})());
check('plan: jumlah alokasi = nominal angsuran', (function () use ($plan): bool {
    foreach ($plan['trxs'] as $t) {
        if ($t['type'] === 'ANGSURAN' && array_sum(array_column($t['allocations'], 'amount')) !== $t['amount']) {
            return false;
        }
    }
    return true;
})());
check('plan: cicilan terakhir menyerap sisa pembulatan', (function () use ($plan): bool {
    $inst = $plan['loans']['L2-2026-05']['installments'];
    return array_sum(array_column($inst, 'amount_due')) === 3120000;
})());
check('plan: tunggakan terdeteksi (cicilan 4 & 5 Bu A belum dibayar, jatuh tempo Jul-Agu)', (function () use ($plan): bool {
    $o = $plan['report']['overdue'];
    return count($o) === 1 && $o[0]['name'] === 'Bu A' && $o[0]['count'] === 1 && $o[0]['amount'] === 220000;
})());
check('plan: pengecualian cadangan dilaporkan', $plan['report']['exempt'] === ['Bu A']);

// angka kontrol yang salah harus menggagalkan rencana
$wrong = (new ExcelImporter(dataset(function (array $d): array {
    $d['checks']['member_savings']['2'] = 51000;
    return $d;
})))->plan();
check('verifikasi: saldo anggota salah terdeteksi', !(new ExcelImporter(dataset()))->isSafeToCommit($wrong) && failedChecks($wrong) !== []);
check('verifikasi: nama pemeriksaan menunjuk saldo tabungan', in_array('Saldo tabungan per anggota cocok (2 anggota): selisih', failedChecks($wrong), true));

$wrongCash = (new ExcelImporter(dataset(function (array $d): array {
    $d['checks']['cash_closing']['2026-05'] = 0;
    return $d;
})))->plan();
check('verifikasi: saldo kas bulanan salah terdeteksi', in_array('Saldo kas akhir 2026-05', failedChecks($wrongCash), true));

// pembayaran berlebih (tidak bisa dialokasikan) memblokir commit
$over = (new ExcelImporter(dataset(function (array $d): array {
    $d['monthly']['2026-07'][0]['bayar'] = 1600000; // 40.000 lebih dari sisa tagihan
    $d['checks']['month_totals']['2026-07']['bayar'] = 1600000;
    return $d;
})))->plan();
check('overpay: angsuran tak teralokasi terdeteksi', $over['unallocated'] === [['member_no' => 2, 'month' => '2026-07', 'amount' => 40000]]);
check('overpay: plan tidak aman di-commit', !(new ExcelImporter([]))->isSafeToCommit($over));

// tenor melewati akhir periode
$threw = false;
try {
    (new ExcelImporter(dataset(function (array $d): array {
        $d['monthly']['2026-08'] = [['no' => 2, 'tabungan' => null, 'tarik' => null, 'pinjam' => 1000000, 'tempo' => 2, 'bayar' => null]];
        return $d;
    })))->plan();
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'melewati akhir periode');
}
check('tenor: melewati akhir periode ditolak', $threw);

$threw = false;
try {
    ExcelImporter::load(__DIR__ . '/tidak-ada.json');
} catch (RuntimeException $e) {
    $threw = true;
}
check('load: file tidak ada ditolak', $threw);

// ---------- database: commit atomik ----------
$testDb = (string) App\Core\Env::get('DB_TEST_NAME', 'simpan_pinjam_adem_ayem_test');
$admin  = [(string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass')];
Database::connect($admin[0], $admin[1], '')->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = Database::connect($admin[0], $admin[1], $testDb);
$fresh = function () use ($pdo): void {
    $m = new Migrator($pdo, dirname(__DIR__) . '/database/migrations');
    $m->dropEverything();
    $m->migrate();
};
$count = fn (string $t): int => (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();

// a) rencana tidak aman -> commit ditolak sebelum menyentuh database
$fresh();
$bad = false;
try {
    (new ExcelImporter(dataset()))->commit($pdo, $wrong);
} catch (RuntimeException $e) {
    $bad = true;
}
check('commit: rencana gagal verifikasi ditolak', $bad && $count('members') === 0);

// b) angka kontrol yang lolos di memori tetapi salah di DB -> rollback total
// (kas tutup dibuat salah HANYA untuk verifikasi database dengan memalsukan plan yang sudah "aman")
$fresh();
$tampered = dataset(function (array $d): array {
    $d['checks']['total_bunga'] = 221000;
    return $d;
});
$imp = new ExcelImporter($tampered);
$planOk = (new ExcelImporter(dataset()))->plan();
$rolled = false;
try {
    $imp->commit($pdo, $planOk); // plan 'aman' dari data asli, tetapi kontrol DB memakai $tampered
} catch (RuntimeException $e) {
    $rolled = str_contains($e->getMessage(), 'Verifikasi database gagal');
}
check('commit: selisih di database membatalkan SEMUA tulisan', $rolled);
check('rollback: tidak ada anggota tertinggal', $count('members') === 0);
check('rollback: tidak ada transaksi tertinggal', $count('transactions') === 0);
check('rollback: nomor urut ikut kembali (tanpa celah)', $count('number_sequences') === 0);
check('rollback: tidak ada periode tertinggal', $count('periods') === 0);
check('rollback: audit log tidak tertulis', $count('audit_logs') === 0);

// c) commit sukses
$fresh();
$counts = (new ExcelImporter(dataset()))->commit($pdo, $planOk);
check('commit: jumlah transaksi', $counts['transaksi'] === 9 && $count('transactions') === 9);
check('commit: semua DISETUJUI', (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE status='DISETUJUI' AND source='IMPOR_EXCEL'")->fetchColumn() === 9);
check('commit: riwayat validasi 2 baris per transaksi', $count('transaction_validations') === 18);
check('commit: 2 pinjaman, 7 cicilan', $count('loans') === 2 && $count('loan_installments') === 7);
check('commit: nomor transaksi berurutan tanpa celah', (int) $pdo->query("SELECT last_value FROM number_sequences WHERE seq_key='TRX-2026'")->fetchColumn() === 9);
check('commit: nomor unik', (int) $pdo->query('SELECT COUNT(DISTINCT trx_no) FROM transactions')->fetchColumn() === 9);
check('commit: nomor dokumen sesuai jenis', (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE (type='SIMPANAN' AND doc_no LIKE 'SMP-2026-%') OR (type='PENCAIRAN_PINJAMAN' AND doc_no LIKE 'PJM-2026-%') OR (type='ANGSURAN' AND doc_no LIKE 'ANG-2026-%')")->fetchColumn() === 9);
$g = $pdo->query('SELECT * FROM v_global_summary')->fetch();
check('commit: saldo global dari view', (int) $g['saldo_tabungan'] === 250000 && (int) $g['kas_tersedia'] === 30000 && (int) $g['piutang_beredar'] === 440000);
check('commit: invarian nol', (int) $g['selisih'] === 0);
check('commit: integritas bersih', $count('v_integrity_issues') === 0);
check('commit: pengecualian cadangan tersimpan', (int) $pdo->query("SELECT reserve_exempt FROM members WHERE member_no='AGT-001'")->fetchColumn() === 1);
check('commit: ketua regu ada di regunya sendiri', (int) $pdo->query("SELECT COUNT(*) FROM member_team_assignments a JOIN team_leaders t ON t.id=a.team_id WHERE a.member_id=t.leader_member_id")->fetchColumn() === 1);
check('commit: audit log impor tercatat', $count('audit_logs') === 1);
$rejected = false;
try {
    $pdo->exec("UPDATE transactions SET amount = amount + 1 WHERE source = 'IMPOR_EXCEL' LIMIT 1");
} catch (PDOException $e) {
    $rejected = true;
}
check('commit: data impor tidak bisa diubah (trigger)', $rejected);

// d) impor ulang ke database yang sudah terisi ditolak
$again = false;
try {
    (new ExcelImporter(dataset()))->commit($pdo, $planOk);
} catch (RuntimeException $e) {
    $again = str_contains($e->getMessage(), 'tidak kosong');
}
check('commit: impor kedua ditolak', $again && $count('transactions') === 9);

// ---------- hasil ----------
echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
