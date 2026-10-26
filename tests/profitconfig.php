<?php
declare(strict_types=1);

/**
 * Tes komponen pembagian bunga dinamis (ProfitConfig, migrasi 013): validasi persentase dan nama, empat sampai lima komponen,
 * versi berlaku-mulai-bulan (bulan lama tidak berubah), hanya-tambah, rekonsiliasi nominal ke total sumber, snapshot yang
 * menyimpan komponennya, laporan Kas SHU per versi, dan rentang bulan bunga diterima.
 * Skenario dasar sama dengan tests/bagihasil.php (mode Excel; pool 96.000 dengan 40/40/20).
 * MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\profitconfig.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Models\User;
use App\Services\Migrator;
use App\Services\ProfitConfig;
use App\Services\ProfitReport;
use App\Services\ProfitShare;
use App\Services\ReportService;
use App\Services\RuleViolation;
use App\Services\UserService;

$testDb = (string) App\Core\Env::get('DB_TEST_NAME', 'simpan_pinjam_adem_ayem_test');
$admin  = [(string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass')];
Database::connect($admin[0], $admin[1], '')->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
Database::configure(['name' => $testDb, 'user' => $admin[0], 'pass' => $admin[1]]);
$pdo = Database::pdo();
$migrator = new Migrator($pdo, dirname(__DIR__) . '/database/migrations');
$migrator->dropEverything();
$migrator->migrate();

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
function one(string $sql, array $params = []): mixed
{
    $st = Database::pdo()->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}
function req(): Request
{
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.3', 'HTTP_USER_AGENT' => 'tes-komponen']);
}
function violation(callable $fn): ?array
{
    try {
        $fn();
    } catch (RuleViolation $e) {
        return $e->errors;
    }
    return null;
}
function sqlFails(callable $fn): bool
{
    try {
        $fn();
    } catch (PDOException $e) {
        return true;
    }
    return false;
}
function trx(string $type, int $member, int $amount, int $monthId, string $date): int
{
    static $n = 0;
    $n++;
    $pdo = Database::pdo();
    $pdo->prepare('INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(["T-PC-{$n}", "PC-{$n}", $type, $member, null, $monthId, $date, $amount]);
    return (int) $pdo->lastInsertId();
}
function approve(int $id): void
{
    $pdo = Database::pdo();
    $pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI' WHERE id = {$id}");
    $pdo->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$id}");
}
function loan(int $member, int $principal, int $tenor, int $interest, int $monthId, string $date, array $dueIds, array $amounts): void
{
    $pdo = Database::pdo();
    $id = trx('PENCAIRAN_PINJAMAN', $member, $principal, $monthId, $date);
    $pdo->prepare('INSERT INTO loans (transaction_id, member_id, principal, tenor_months, rate_pct_month, total_interest, disbursed_on) VALUES (?,?,?,?,?,?,?)')->execute([$id, $member, $principal, $tenor, '2.00', $interest, $date]);
    $loanId = (int) $pdo->lastInsertId();
    foreach ($amounts as $i => $a) {
        $pdo->prepare('INSERT INTO loan_installments (loan_id, seq, due_month_id, amount_due) VALUES (?,?,?,?)')->execute([$loanId, $i + 1, $dueIds[$i], $a]);
    }
    approve($id);
}
/** @param array<int,array{0:string,1:string,2:string}> $rows [kode, nama, persen] */
function rows(array $rows): array
{
    return array_map(static fn (array $r): array => ['code' => $r[0], 'name' => $r[1], 'pct' => $r[2]], $rows);
}

// ---------------- fixture (sama dengan tests/bagihasil.php) ----------------
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Panjang', '2027-01-01', '2027-12-31', 'AKTIF')");
for ($m = 1; $m <= 12; $m++) {
    $pdo->exec(sprintf("INSERT INTO period_months (period_id, month_date) VALUES (1, '2027-%02d-01')", $m));
}
$mo = [];
foreach (Database::select('SELECT id FROM period_months ORDER BY month_date') as $i => $r) {
    $mo[$i + 1] = (int) $r['id'];
}
$pdo->exec("INSERT INTO members (member_no, name, active_from, status) VALUES ('AGT-001', 'Anggota X', '2027-01-01', 'AKTIF'), ('AGT-002', 'Anggota Y', '2027-01-01', 'AKTIF'), ('AGT-003', 'Anggota Z', '2027-01-01', 'AKTIF')");
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu X', 1), ('Regu Y', 2)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1, 1, '2027-01-01'), (2, 2, '2027-01-01'), (3, 1, '2027-01-01')");
foreach ([[1, 100000], [2, 300000]] as [$mem, $amt]) {
    $s = trx('SIMPANAN', $mem, $amt, $mo[1], '2027-01-05');
    $pdo->exec("INSERT INTO savings (transaction_id, kind) VALUES ({$s}, 'WAJIB')");
    approve($s);
}
loan(1, 1000000, 1, 20000, $mo[7], '2027-07-05', [$mo[8]], [1020000]);
loan(2, 1000000, 5, 100000, $mo[7], '2027-07-05', [$mo[8], $mo[9], $mo[10], $mo[11], $mo[12]], [220000, 220000, 220000, 220000, 220000]);
$ids = [];
foreach ([['kepala', ['HEAD'], null], ['ketua.x', ['KETUA_REGU'], 'AGT-001']] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$head   = User::findActive($ids['kepala']);
$ketuaX = User::findActive($ids['ketua.x']);
$period = (int) one('SELECT id FROM periods LIMIT 1');
$f = static fn (?array $u, array $q = []): array => ReportService::filters($q + ['periode' => (string) $period], $u);

// ======================= versi awal dari migrasi =======================
$v0 = ProfitConfig::versions();
check('awal: satu versi berlaku sejak awal, tiga komponen tetap 40 / 40 / 20 (disalin dari Pengaturan)', count($v0) === 1 && $v0[0]['effective_from'] === '2000-01-01'
    && array_column($v0[0]['items'], 'pct', 'code') === ['PENABUNG' => 4000, 'PEMINJAM' => 4000, 'SHU' => 2000] && array_sum(array_column($v0[0]['items'], 'pct')) === 10000);
$base = ProfitShare::compute($period, true, true);
check('awal: angka bagi hasil TIDAK berubah oleh migrasi (pool 96.000, tak terbagi 8.000; X 9.500 + 2.533; Y 28.500 + 43.067)',
    (int) round($base['interest_pool']) === 96000 && (int) round($base['undistributed']) === 8000 && $base['members'][1]['saver'] === 9500 && $base['members'][1]['borrower'] === 2533
    && $base['members'][2]['saver'] === 28500 && $base['members'][2]['borrower'] === 43067);
check('awal: total sumber 120.000 dan pembagian ke komponen: penabung 48.000, peminjam 48.000, Kas SHU 24.000', (int) round($base['total_interest']) === 120000
    && (int) round($base['funds']['PENABUNG']) === 48000 && (int) round($base['funds']['PEMINJAM']) === 48000 && (int) round($base['funds']['SHU']) === 24000);

// ======================= validasi isian =======================
$std = [['PENABUNG', 'Penabung', '30'], ['PEMINJAM', 'Peminjam', '30'], ['SHU', 'Kas SHU', '20'], ['', 'Dana Cadangan', '10'], ['', 'Dana Operasional', '10']];
[$e, $items, $total] = ProfitConfig::normalize(rows($std));
check('validasi: lima komponen (tiga tetap + dua dana) yang berjumlah 100% diterima, kode dana dibuat otomatis', $e === [] && $total === 10000 && count($items) === 5 && array_column($items, 'code') === ['PENABUNG', 'PEMINJAM', 'SHU', 'D1', 'D2']
    && array_column($items, 'kind') === ['PENABUNG', 'PEMINJAM', 'SHU', 'DANA', 'DANA']);
[$e] = ProfitConfig::normalize(rows([['PENABUNG', 'Penabung', '30'], ['PEMINJAM', 'Peminjam', '30'], ['SHU', 'Kas SHU', '20'], ['', 'Dana Cadangan', '10']]));
check('validasi: total 90% -> pesan "tersisa 10%"', isset($e['total']) && str_contains($e['total'], 'tersisa 10%'));
[$e] = ProfitConfig::normalize(rows([['PENABUNG', 'Penabung', '50'], ['PEMINJAM', 'Peminjam', '40'], ['SHU', 'Kas SHU', '20'], ['', 'Dana', '5']]));
check('validasi: total 115% -> pesan "melebihi ... 15%"', isset($e['total']) && str_contains($e['total'], 'melebihi') && str_contains($e['total'], '15%'));
[$e] = ProfitConfig::normalize(rows([['PENABUNG', 'Penabung', '12,5'], ['PEMINJAM', 'Peminjam', '37,5'], ['SHU', 'Kas SHU', '50']]));
check('validasi: persentase desimal (koma) diterima, 12,5 + 37,5 + 50 = 100', $e === []);
check('validasi: 12,5 disimpan sebagai 1250 seperseratus persen', ProfitConfig::normalize(rows([['PENABUNG', 'Penabung', '12,5'], ['PEMINJAM', 'Peminjam', '37,5'], ['SHU', 'Kas SHU', '50']]))[1][0]['pct'] === 1250);
[$e] = ProfitConfig::normalize(rows([['PENABUNG', 'Penabung', 'abc'], ['PEMINJAM', 'Peminjam', '30'], ['SHU', 'Kas SHU', '70']]));
check('validasi: persentase bukan angka ditolak per baris', isset($e['pct0']));
[$e] = ProfitConfig::normalize(rows([['PENABUNG', 'Penabung', '30.555'], ['PEMINJAM', 'Peminjam', '30'], ['SHU', 'Kas SHU', '40']]));
check('validasi: lebih dari 2 desimal ditolak', isset($e['pct0']));
[$e] = ProfitConfig::normalize(rows([['PENABUNG', 'Penabung', '-5'], ['PEMINJAM', 'Peminjam', '30'], ['SHU', 'Kas SHU', '75']]));
check('validasi: persentase negatif ditolak', isset($e['pct0']));
[$e] = ProfitConfig::normalize(rows([['PENABUNG', 'Penabung', '101'], ['PEMINJAM', 'Peminjam', '0'], ['SHU', 'Kas SHU', '0']]));
check('validasi: persentase di atas 100 ditolak', isset($e['pct0']));
[$e] = ProfitConfig::normalize(rows([['PENABUNG', '', '40'], ['PEMINJAM', 'Peminjam', '40'], ['SHU', 'Kas SHU', '20']]));
check('validasi: nama kosong ditolak', isset($e['name0']));
[$e] = ProfitConfig::normalize(rows([['PENABUNG', 'Dana', '40'], ['PEMINJAM', 'dana', '40'], ['SHU', 'Kas SHU', '20']]));
check('validasi: nama ganda (tanpa memedulikan huruf besar) ditolak', isset($e['name1']));
[$e] = ProfitConfig::normalize(rows([['PENABUNG', str_repeat('x', 61), '40'], ['PEMINJAM', 'Peminjam', '40'], ['SHU', 'Kas SHU', '20']]));
check('validasi: nama lebih dari 60 karakter ditolak', isset($e['name0']));
[$e] = ProfitConfig::normalize(rows([['PENABUNG', 'Penabung', '50'], ['SHU', 'Kas SHU', '50']]));
check('validasi: komponen tetap (Peminjam) tidak boleh dihapus', isset($e['_form']) && str_contains($e['_form'], 'Peminjam'));
[$e] = ProfitConfig::normalize(rows([['PENABUNG', 'Penabung', '40'], ['PEMINJAM', 'Peminjam', '40'], ['SHU', 'Kas SHU', '20'], ['SHU', 'Kas SHU 2', '0']]));
check('validasi: Kas SHU ganda ditolak', isset($e['_form']));
[$e, $it] = ProfitConfig::normalize(rows([['PENABUNG', 'Penabung', '40'], ['PEMINJAM', 'Peminjam', '40'], ['SHU', 'Kas SHU', '20'], ['', '', ''], ['', '', '']]));
check('validasi: baris dana kosong (tanpa JavaScript) diabaikan', $e === [] && count($it) === 3);
[$e] = ProfitConfig::normalize(rows([['PENABUNG', "Peña<b>bung</b>\n", '40'], ['PEMINJAM', 'Peminjam', '40'], ['SHU', 'Kas SHU', '20']]));
check('validasi: nama dibersihkan dari karakter kontrol (tag disimpan sebagai teks, tampilan memakai e())', $e === []);
check('validasi: jenis ditentukan dari kode di server, bukan isian: kode ngawur menjadi dana baru', ProfitConfig::normalize(rows([['PENABUNG', 'Penabung', '40'], ['PEMINJAM', 'Peminjam', '40'], ['SHU', 'Kas SHU', '10'], ['HACK', 'Lain', '10']]))[1][3]['kind'] === 'DANA'
    && preg_match('/^D\d+$/', ProfitConfig::normalize(rows([['PENABUNG', 'Penabung', '40'], ['PEMINJAM', 'Peminjam', '40'], ['SHU', 'Kas SHU', '10'], ['HACK', 'Lain', '10']]))[1][3]['code']) === 1);

// ======================= alokasi nominal =======================
$items5 = ProfitConfig::normalize(rows($std))[1];
foreach ([1000000, 1000001, 999999, 7, 1, 0, 123456789] as $t) {
    $a = ProfitConfig::allocate($items5, $t);
    check("alokasi: jumlah semua komponen = total sumber ({$t}), tanpa selisih", array_sum($a) === $t && count($a) === 5);
}
$a = ProfitConfig::allocate($items5, 1000000);
check('alokasi: 1.000.000 -> penabung 300.000, peminjam 300.000, Kas SHU 200.000, dana 100.000 + 100.000', $a === ['PENABUNG' => 300000, 'PEMINJAM' => 300000, 'D1' => 100000, 'D2' => 100000, 'SHU' => 200000]);
$a = ProfitConfig::allocate($items5, 1000001);
check('alokasi: selisih pembulatan ditanggung Kas SHU (komponen lain dibulatkan setengah ke atas)', $a['PENABUNG'] === 300000 && $a['D1'] === 100000 && $a['SHU'] === 200001);

// ======================= versi baru =======================
$minNow = ProfitConfig::minEffectiveFrom();
check('versi: bulan berlaku paling awal = bulan sesudah versi terakhir (2000-02)', $minNow === '2000-02-01');
$v = fn (string $from, array $r, bool $confirm = true, ?string $note = null): int => ProfitConfig::save(req(), $head, $from, ProfitConfig::normalize(rows($r))[1], $note, $confirm);
check('versi: bulan berlaku sebelum/sama dengan versi terakhir ditolak', isset(violation(fn () => $v('2000-01-01', $std))['effective_from']));
check('versi: total persentase salah ditolak walau lolos pemeriksa antarmuka', isset(violation(fn () => ProfitConfig::save(req(), $head, '2027-10-01', [['code' => 'PENABUNG', 'kind' => 'PENABUNG', 'name' => 'P', 'pct' => 5000], ['code' => 'PEMINJAM', 'kind' => 'PEMINJAM', 'name' => 'Q', 'pct' => 4000], ['code' => 'SHU', 'kind' => 'SHU', 'name' => 'S', 'pct' => 2000]], null, true))['total']));
check('versi: bulan berlaku yang sudah lewat tanpa pernyataan paham ditolak dan tidak menyimpan apa pun', (function () use ($v, $std): bool {
    $e = violation(fn () => $v(date('Y-m-01', strtotime('-1 month')), $std, false));
    return isset($e['confirm_past']) && (int) one('SELECT COUNT(*) FROM profit_share_configs') === 1;
})());
check('versi: bulan di masa depan tidak butuh pernyataan paham', (function () use ($v, $std): bool {
    $id = $v('2027-10-01', $std, false, 'Rapat anggota');
    return $id > 0;
})());
$id = (int) one('SELECT id FROM profit_share_configs WHERE effective_from = ?', ['2027-10-01']);
check('versi: tersimpan sebagai versi kedua dengan lima komponen terurut, tercatat di audit', $id > 0 && (int) one('SELECT COUNT(*) FROM profit_share_configs') === 2 && (int) one('SELECT COUNT(*) FROM profit_share_config_items WHERE config_id = ?', [$id]) === 5
    && (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'PROFIT_CONFIG_CHANGED' AND entity_id = ?", [$id]) === 1);
check('versi: forMonth memilih versi yang benar (Sep 2027 -> lama 40/40/20; Okt 2027 dan sesudahnya -> baru)', ProfitConfig::forMonth('2027-09-15')['effective_from'] === '2000-01-01' && ProfitConfig::forMonth('2027-10-01')['effective_from'] === '2027-10-01'
    && ProfitConfig::forMonth('2028-03-01')['effective_from'] === '2027-10-01' && ProfitConfig::forMonth('1999-01-01')['effective_from'] === '2000-01-01');
check('versi: versi lama tidak berubah (nama dan persentase tetap)', array_column(ProfitConfig::versions()[0]['items'], 'pct', 'code') === ['PENABUNG' => 4000, 'PEMINJAM' => 4000, 'SHU' => 2000]);
check('versi: hanya-tambah — UPDATE dan DELETE di tabel versi ditolak database', sqlFails(fn () => $pdo->exec("UPDATE profit_share_configs SET note = 'x'")) && sqlFails(fn () => $pdo->exec('DELETE FROM profit_share_configs'))
    && sqlFails(fn () => $pdo->exec('UPDATE profit_share_config_items SET pct_hundredths = 1')) && sqlFails(fn () => $pdo->exec('DELETE FROM profit_share_config_items')));
check('versi: dua versi tidak boleh berlaku mulai bulan yang sama', isset(violation(fn () => $v('2027-10-01', $std, true))['effective_from']));
$aud = Database::select("SELECT before_data, after_data FROM audit_logs WHERE action = 'PROFIT_CONFIG_CHANGED' ORDER BY id DESC LIMIT 1")[0];
check('audit: sebelum dan sesudah memuat komponen dan persentase', str_contains((string) $aud['before_data'], 'Penabung 40%') && str_contains((string) $aud['after_data'], 'Dana Operasional 10%'));

// ======================= perhitungan memakai versi per bulan =======================
$new = ProfitShare::compute($period, true, true);
check('hitung: siklus lama (bunga diterima Agustus) tetap 40%: pool penabung 16.000; siklus Oktober memakai 30%: 6.000', abs($new['cycles'][7]['saver_pool'] - 16000) < 0.001 && abs($new['cycles'][9]['saver_pool'] - 6000) < 0.001
    && abs($new['cycles'][9]['borrower_pool'] - 6000) < 0.001 && $new['cycles'][9]['saver_pct'] === 0.3 && $new['cycles'][7]['saver_pct'] === 0.4);
check('hitung: pool anggota = 32.000 + 16.000 (40/40) + 3 x 12.000 (30/30) = 84.000, bukan 96.000', (int) round($new['interest_pool']) === 84000);
check('hitung: total sumber tetap 120.000; komponen: penabung 42.000, peminjam 42.000, Kas SHU 24.000, dana 6.000 + 6.000', (int) round($new['total_interest']) === 120000
    && (int) round($new['funds']['PENABUNG']) === 42000 && (int) round($new['funds']['PEMINJAM']) === 42000 && (int) round($new['funds']['SHU']) === 24000 && (int) round($new['funds']['D1']) === 6000 && (int) round($new['funds']['D2']) === 6000);
check('hitung: jumlah komponen = total sumber (tidak ada rupiah hilang atau ganda)', abs(array_sum($new['funds']) - $new['total_interest']) < 0.001);
check('hitung: dua versi tercatat sebagai dipakai', count($new['configs']) === 2);

// ======================= laporan dan komponen =======================
$ds = ProfitReport::dataset($period, 0);
$comp = array_column($ds['components'], null, 'code');
check('laporan: tabel komponen memuat lima komponen dengan nama, persentase berurutan, dan nominal', count($ds['components']) === 5 && $comp['PENABUNG']['pct'] === '40% → 30%' && $comp['D1']['name'] === 'Dana Cadangan' && $comp['D1']['pct'] === '10%');
check('laporan: jumlah nominal komponen = total sumber 120.000 persis (Kas SHU menyerap pembulatan)', array_sum(array_column($ds['components'], 'amount')) === 120000 && $ds['total_interest'] === 120000);
$rep = ReportService::build('bagihasil', $head, $f($head));
check('laporan: meta membawa komponen untuk cakupan global; ringkasan memakai nama komponen', count($rep['meta']['components']) === 5 && $rep['meta']['total_interest'] === 120000 && str_contains($rep['summary'][1]['label'], 'penabung'));
$repX = ReportService::build('bagihasil', $ketuaX, $f($ketuaX));
check('cakupan: Ketua Regu tidak mendapat nominal komponen maupun total sumber seluruh koperasi', $repX['meta']['total_interest'] === 0 && array_sum(array_column($repX['meta']['components'], 'amount')) === 0 && !isset($repX['control']));
check('laporan: teks aturan menyebut komponen dana', str_contains($rep['note'], 'Dana Cadangan'));

// ======================= snapshot menyimpan komponen =======================
$snapId = ProfitReport::createSnapshot(req(), $head, $period, 'THR');
$stored = (string) one('SELECT components_json FROM profit_share_snapshots WHERE id = ?', [$snapId]);
check('snapshot: komponen, versi, dan total tersimpan di components_json', str_contains($stored, 'Dana Operasional') && str_contains($stored, 'total_interest'));
$snapSet = ProfitReport::dataset($period, $snapId);
check('snapshot: tampilannya sama dengan perhitungan saat ditetapkan (5 komponen, jumlah 120.000)', count($snapSet['components']) === 5 && array_sum(array_column($snapSet['components'], 'amount')) === 120000);
check('versi: setelah ada snapshot data Juli 2027, versi baru paling awal Agustus 2027 pun tetap harus setelah versi terakhir (Nov 2027)', ProfitConfig::minEffectiveFrom() === '2027-11-01');
$id2 = $v('2027-11-01', [['PENABUNG', 'Tabungan Anggota', '20'], ['PEMINJAM', 'Jasa Pinjaman', '20'], ['SHU', 'SHU Anggota', '30'], ['D1', 'Dana Cadangan', '15'], ['D2', 'Dana Operasional', '15']], true);
check('versi: nama diubah di versi baru; snapshot lama tetap menampilkan nama dan persentase lamanya', $id2 > 0 && ProfitReport::dataset($period, $snapId)['components'][0]['name'] === 'Penabung' && ProfitReport::dataset($period, $snapId)['components'][0]['pct'] === '40% → 30%');
$live = ProfitReport::dataset($period, 0);
check('laporan langsung: nama terbaru dipakai, persentase berurutan antar versi (40% → 30% → 20%)', array_column($live['components'], 'name', 'code')['PENABUNG'] === 'Tabungan Anggota' && array_column($live['components'], 'pct', 'code')['PENABUNG'] === '40% → 30% → 20%');
check('laporan langsung: tetap seimbang dengan total sumber setelah tiga versi', array_sum(array_column($live['components'], 'amount')) === $live['total_interest']);

// ======================= rentang bulan bunga diterima =======================
$win = ProfitShare::compute($period, true, true, ['from' => '2027-09-01', 'to' => '2027-10-01']);
check('rentang: jendela bunga Sep–Okt 2027 hanya memuat dua siklus (8 dan 9), total sumber 40.000', array_keys($win['cycles']) === [8, 9] && (int) round($win['total_interest']) === 40000);
$winRep = ReportService::build('bagihasil', $head, $f($head, ['dari' => '2027-09-10', 'sampai' => '2027-10-20']));
check('rentang: laporan Pembagian Bunga untuk rentang tanggal menampilkan subtitle dan total sumber 40.000 serta tanpa tombol snapshot (meta window)', $winRep['meta']['window'] === ['from' => '2027-09-01', 'to' => '2027-10-01']
    && $winRep['meta']['total_interest'] === 40000 && str_contains($winRep['subtitle'], 'rentang'));
check('rentang: sebaran per anggota pada jendela = jumlah bagian siklus di jendela saja (kurang dari seluruh periode)', $winRep['totals']['total'] > 0 && $winRep['totals']['total'] < $rep['totals']['total']);

// ======================= laporan Jasa Pinjaman dan Kas SHU per versi =======================
loan(1, 500000, 2, 20000, $mo[10], '2027-10-05', [$mo[11], $mo[12]], [260000, 260000]);
loan(2, 500000, 2, 20000, $mo[11], '2027-11-05', [$mo[12], $mo[12]], [260000, 260000]);
$pdo->exec("UPDATE periods SET status = 'TUTUP'");   // periode uji ada di masa depan: dianggap tutup supaya semua bulan tampil
$shu = ReportService::build('shu', $head, $f($head));
$rowsBy = array_column($shu['rows'], null, 'month');
check('Kas SHU: bulan lama (Juli 2027, bunga 120.000) memakai 40/40/20: penabung 48.000, peminjam 48.000, Kas SHU 24.000', $rowsBy['Juli 2027']['saver'] === 48000 && $rowsBy['Juli 2027']['borrower'] === 48000 && $rowsBy['Juli 2027']['shu_in'] === 24000);
check('Kas SHU: Oktober 2027 (bunga 20.000) memakai 30/30/20 + dana 10/10: 6.000 / 6.000 / Kas SHU 4.000 / dana 2.000 + 2.000', $rowsBy['Oktober 2027']['saver'] === 6000 && $rowsBy['Oktober 2027']['borrower'] === 6000 && $rowsBy['Oktober 2027']['shu_in'] === 4000
    && $rowsBy['Oktober 2027']['d_D1'] === 2000 && $rowsBy['Oktober 2027']['d_D2'] === 2000);
check('Kas SHU: November 2027 memakai versi 20/20/30 + 15/15 (nama baru): 4.000 / 4.000 / 6.000 / 3.000 + 3.000', $rowsBy['November 2027']['saver'] === 4000 && $rowsBy['November 2027']['borrower'] === 4000 && $rowsBy['November 2027']['shu_in'] === 6000 && $rowsBy['November 2027']['d_D1'] === 3000);
check('Kas SHU: kontrol seimbang (semua komponen = bunga dibukukan) dan kolom dana tampil dengan nama terbaru', $shu['control']['ok'] === true && in_array('Dana Cadangan', array_column($shu['columns'], 'label'), true) && in_array('Jasa Pinjaman', array_column($shu['columns'], 'label'), true));
check('Kas SHU: bulan tanpa pinjaman nol; jumlah kolom = jumlah baris', $rowsBy['Agustus 2027']['interest'] === 0 && $shu['totals']['shu_in'] === array_sum(array_column($shu['rows'], 'shu_in')));

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
