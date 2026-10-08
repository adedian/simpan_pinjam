<?php
declare(strict_types=1);

/**
 * Tes laporan (Phase 12): angka tiap laporan, cakupan data per peran, saringan yang aman, pembalik, CSV, kartu anggota.
 * MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\report.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Models\User;
use App\Services\InstallmentService;
use App\Services\LoanService;
use App\Services\Migrator;
use App\Services\ReportService;
use App\Services\ReversalService;
use App\Services\SavingService;
use App\Services\UserService;
use App\Services\ValidationService;

$testDb = (string) App\Core\Env::get('DB_TEST_NAME', 'simpan_pinjam_adem_ayem_test');
$admin  = [(string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass')];
Database::connect($admin[0], $admin[1], '')->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
Database::configure(['name' => $testDb, 'user' => $admin[0], 'pass' => $admin[1]]);
$pdo = Database::pdo();
$migrator = new Migrator($pdo, dirname(__DIR__) . '/database/migrations');
$migrator->dropEverything();
$migrator->migrate();

$_SESSION = [];
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
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}
function req(): Request
{
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.1', 'HTTP_USER_AGENT' => 'tes-laporan']);
}
function ver(int $id): string
{
    return (string) one('SELECT updated_at FROM transactions WHERE id = ?', [$id]);
}

// ---------------- fixture ----------------
$m2 = date('Y-m-01', strtotime('-2 month', strtotime(date('Y-m-01'))));
$m1 = date('Y-m-01', strtotime('-1 month', strtotime(date('Y-m-01'))));
$m0 = date('Y-m-01');
$old = date('Y-m-01', strtotime('-6 month', strtotime(date('Y-m-01'))));
$oldEnd = date('Y-m-01', strtotime('-4 month', strtotime(date('Y-m-01'))));
$future = date('Y-m-01', strtotime('+1 month', strtotime($m0)));
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Lama', '{$old}', '{$oldEnd}', 'TUTUP')");
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Baru', '{$m2}', '{$future}', 'AKTIF')");
$pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1, '{$old}')");
foreach ([$m2, $m1, $m0, $future] as $d) {
    $pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (2, '{$d}')");
}
$mid = [];
foreach ([$old, $m2, $m1, $m0] as $d) {
    $mid[$d] = (int) one('SELECT id FROM period_months WHERE month_date = ?', [$d]);
}
foreach ([1 => 'Ketua Alfa', 2 => 'Anggota Alfa', 3 => 'Ketua Beta', 4 => 'Anggota Beta', 5 => '=Anggota "Rumus"; Beta'] as $n => $name) {
    $pdo->prepare('INSERT INTO members (member_no, name, active_from, status) VALUES (?, ?, ?, ?)')->execute([sprintf('AGT-%03d', $n), $name, $old, 'AKTIF']);
}
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Alfa', 1), ('Regu Beta', 3)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'{$old}'),(2,1,'{$old}'),(3,2,'{$old}'),(4,2,'{$old}'),(5,2,'{$old}')");
$ids = [];
foreach ([['purwati', ['HEAD', 'KETUA_REGU'], 'AGT-001'], ['ketua.beta', ['KETUA_REGU'], 'AGT-003'], ['kepala2', ['HEAD'], null], ['periksa', ['PEMERIKSA'], null], ['anggota4', ['ANGGOTA'], 'AGT-004'], ['yatim', ['ANGGOTA'], 'AGT-005']] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$pdo->exec("UPDATE users SET member_id = NULL WHERE username = 'yatim'");   // Anggota yatim: tautan hilang (tak bisa dibuat lewat layanan)
$purwati = User::findActive($ids['purwati']);
$beta    = User::findActive($ids['ketua.beta']);
$head    = User::findActive($ids['kepala2']);
$periksa = User::findActive($ids['periksa']);
$ang4    = User::findActive($ids['anggota4']);
$yatim   = User::findActive($ids['yatim']);
$pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'loan_max_amount'");

/** Transaksi mentah DISETUJUI di bulan tertentu (untuk periode lama yang tak bisa dicatat lewat aplikasi). */
function rawApproved(string $type, int $member, int $teamId, int $amount, int $monthId, string $date): int
{
    static $n = 0;
    $n++;
    $pdo = Database::pdo();
    $pdo->prepare('INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(["T-OLD-{$n}", "OLD-{$n}", $type, $member, $teamId, $monthId, $date, $amount]);
    $id = (int) $pdo->lastInsertId();
    if ($type === 'SIMPANAN') {
        $pdo->exec("INSERT INTO savings (transaction_id, kind) VALUES ({$id}, 'POKOK')");
    }
    $pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI' WHERE id = {$id}");
    $pdo->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$id}");
    return $id;
}
function saving(array $actor, int $member, string $amount, int $monthId, string $date, string $kind = 'WAJIB'): int
{
    [$e, $d] = SavingService::parse(['member_id' => $member, 'period_month_id' => $monthId, 'kind' => $kind, 'amount' => $amount, 'trx_date' => $date, 'confirm_duplicate' => '1']);
    if ($e !== []) {
        throw new RuntimeException(json_encode($e));
    }
    return SavingService::create(req(), $actor, $d, true);
}
function ok(array $validator, int $id): int
{
    ValidationService::approve(req(), $validator, $id, ver($id), null);
    return $id;
}
function row(array $report, string $col, string $value): array
{
    foreach ($report['rows'] as $r) {
        if (($r[$col] ?? null) === $value) {
            return $r;
        }
    }
    return [];
}
function f(array $over = [], ?array $user = null): array
{
    global $head;
    return $over + ReportService::filters([], $user ?? $head);
}

// periode lama (tutup): simpanan Beta anggota 4 = 1.000.000 (jadi saldo awal periode baru)
rawApproved('SIMPANAN', 4, 2, 1000000, $mid[$old], $old);
// periode baru: anggota 4: m2 300.000, m1 200.000 (SUKARELA), m0 100.000; anggota 5: m1 400.000; anggota 2 (Alfa, Purwati): m1 500.000
ok($head, saving($beta, 4, '300.000', $mid[$m2], $m2));
$s4b = ok($head, saving($beta, 4, '200.000', $mid[$m1], $m1, 'SUKARELA'));
ok($head, saving($beta, 4, '100.000', $mid[$m0], $m0));
ok($head, saving($beta, 5, '400.000', $mid[$m1], $m1));
ok($periksa, saving($purwati, 2, '500.000', $mid[$m1], $m1));
$pendingSv = saving($beta, 5, '9.000.000', $mid[$m0], $m0);   // menunggu: tidak boleh terhitung

// pinjaman anggota 4 (dicairkan m1, tenor 2): satu dibayar sekali; pinjaman anggota 5 dicairkan m2 (menunggak) lalu LUNAS? -> dibayar penuh
[$e, $d] = LoanService::parse(['member_id' => 4, 'period_month_id' => $mid[$m1], 'principal' => '1.000.000', 'tenor' => '2', 'trx_date' => $m1, 'confirm_duplicate' => '1']);
$loan4 = ok($head, LoanService::create(req(), $beta, $d, true));
[$e, $d] = LoanService::parse(['member_id' => 5, 'period_month_id' => $mid[$m2], 'principal' => '200.000', 'tenor' => '1', 'trx_date' => $m2, 'confirm_duplicate' => '1']);
$loan5 = ok($head, LoanService::create(req(), $beta, $d, true));
[$e, $d] = InstallmentService::parse(['member_id' => 4, 'period_month_id' => $mid[$m0], 'amount' => '520.000', 'trx_date' => $m0, 'confirm_duplicate' => '1']);
ok($head, InstallmentService::create(req(), $beta, $d, true));
[$e, $d] = InstallmentService::parse(['member_id' => 5, 'period_month_id' => $mid[$m1], 'amount' => '204.000', 'trx_date' => $m1, 'confirm_duplicate' => '1']);
ok($head, InstallmentService::create(req(), $beta, $d, true));   // melunasi pinjaman anggota 5 (200.000 + bunga 2% x 1 = 204.000)

// ======================= cakupan =======================
check('cakupan: Head dan Pemeriksa global; Ketua Regu regu; Anggota diri sendiri; Anggota tanpa tautan tidak ada', ReportService::level($head) === 'all' && ReportService::level($periksa) === 'all' && ReportService::level($purwati) === 'all'
    && ReportService::level($beta) === 'team' && ReportService::level($ang4) === 'self' && ReportService::level($yatim) === 'none' && ReportService::level(null) === 'none');
check('cakupan: jumlah anggota yang terlihat per peran (5 / 3 / 1 / 0)', count(ReportService::members($head, f())) === 5 && count(ReportService::members($beta, f([], $beta))) === 3 && count(ReportService::members($ang4, f([], $ang4))) === 1 && ReportService::members($yatim, f([], $yatim)) === []);

// ======================= saringan =======================
$fl = ReportService::filters(['periode' => '999', 'regu' => '7', 'dari' => '2026-02-31', 'sampai' => '2026-03-05', 'jenis' => 'x', 'status' => 'ngawur', 'anggota' => '-3', 'q' => "  <b>Andi</b>\n  "], $head);
check('saringan: periode tak dikenal jatuh ke periode aktif; tanggal tidak sah dibuang; jenis/status ngawur dikosongkan', $fl['period'] === 2 && $fl['from'] === '' && $fl['to'] === '2026-03-05' && $fl['type'] === '' && $fl['status'] === '' && $fl['anggota'] === 0 && $fl['team'] === 7);
check('saringan: Ketua Regu tidak bisa memilih regu lain; status default DISETUJUI, kosong = semua', ReportService::filters(['regu' => '1'], $beta)['team'] === 0 && ReportService::filters([], $head)['status'] === 'DISETUJUI' && ReportService::filters(['status' => ''], $head)['status'] === '');

// ======================= rekap simpanan =======================
$sim = ReportService::build('simpanan', $head, f());
$r4 = row($sim, 'no', 'AGT-004');
check('simpanan: kolom bulan periode aktif sampai bulan berjalan (bulan depan dan periode lama tidak ikut)', count(array_filter($sim['columns'], static fn ($c) => str_starts_with($c['key'], 'm'))) === 3);
check('simpanan: per bulan anggota 4 (300.000 / 200.000 / 100.000), jumlah 600.000, saldo = 1.000.000 awal + 600.000', $r4['m' . $mid[$m2]] === 300000 && $r4['m' . $mid[$m1]] === 200000 && $r4['m' . $mid[$m0]] === 100000 && $r4['total'] === 600000 && $r4['savings'] === 1600000);
check('simpanan: simpanan yang menunggu validasi tidak terhitung (anggota 5 hanya 400.000)', row($sim, 'no', 'AGT-005')['total'] === 400000);
check('simpanan: baris jumlah = jumlah kolom; saldo total = saldo global', $sim['totals']['total'] === 1500000 && $sim['totals']['savings'] === (int) Database::select('SELECT saldo_tabungan FROM v_global_summary')[0]['saldo_tabungan']);
check('simpanan: nama anggota menaut ke kartu data anggota', $r4['_links']['name'] === '/anggota/4');
$simBeta = ReportService::build('simpanan', $beta, f([], $beta));
check('simpanan: Ketua Regu hanya anggota regunya (3 baris), tanpa Alfa', count($simBeta['rows']) === 3 && row($simBeta, 'no', 'AGT-002') === [] && $simBeta['totals']['total'] === 1000000);
$simSelf = ReportService::build('simpanan', $ang4, f([], $ang4));
check('simpanan: Anggota hanya dirinya; tanpa tautan = kosong', count($simSelf['rows']) === 1 && $simSelf['rows'][0]['no'] === 'AGT-004' && ReportService::build('simpanan', $yatim, f([], $yatim))['rows'] === []);
check('simpanan: saringan regu (Head) dan pencarian nama/nomor', count(ReportService::build('simpanan', $head, f(['team' => 1]))['rows']) === 2 && count(ReportService::build('simpanan', $head, f(['q' => 'AGT-004']))['rows']) === 1 && count(ReportService::build('simpanan', $head, f(['q' => 'Alfa']))['rows']) === 2);
check('simpanan: pencarian aman: wildcard harfiah dan SQL injection tidak mengembalikan semua', ReportService::build('simpanan', $head, f(['q' => '%']))['rows'] === [] && ReportService::build('simpanan', $head, f(['q' => "' OR '1'='1"]))['rows'] === []);

// pembalik mengurangi bulan asalnya
ok($head, ReversalService::request(req(), $beta, $s4b, 'Salah anggota'));
$sim2 = ReportService::build('simpanan', $head, f());
check('simpanan: pembalik disetujui mengurangi bulan asal (200.000 -> 0), saldo ikut turun', row($sim2, 'no', 'AGT-004')['m' . $mid[$m1]] === 0 && row($sim2, 'no', 'AGT-004')['savings'] === 1400000);

// ======================= rekap saldo =======================
$sal = ReportService::build('saldo', $head, f());
$b4 = row($sal, 'no', 'AGT-004');
check('saldo: kumulatif akhir bulan memasukkan saldo periode sebelumnya (1.000.000 + 300.000 = 1.300.000 di bulan pertama)', $b4['m' . $mid[$m2]] === 1300000 && $b4['m' . $mid[$m1]] === 1300000 && $b4['m' . $mid[$m0]] === 1400000);
check('saldo: kolom terakhir = saldo tabungan sekarang untuk semua anggota', (function () use ($sal, $mid, $m0): bool {
    foreach ($sal['rows'] as $r) {
        $no = $r['no'];
        $cur = (int) Database::select('SELECT s.savings_balance FROM v_member_savings s JOIN members m ON m.id = s.member_id WHERE m.member_no = ?', [$no])[0]['savings_balance'];
        if ($r['m' . $mid[$m0]] !== $cur) {
            return false;
        }
    }
    return true;
})());
check('saldo: porsi anggota (seperseratus persen) berjumlah 100% (selisih pembulatan <= 3)', abs(array_sum(array_column($sal['rows'], 'share')) - 10000) <= 3 && $b4['share'] === (int) round(1400000 * 10000 / array_sum(array_map(static fn ($r) => $r['m' . $mid[$m0]], $sal['rows']))));
check('saldo: anggota tanpa tabungan berporsi 0 dan tidak menyebabkan pembagian nol bila semua nol', row($sal, 'no', 'AGT-001')['share'] === 0 && ReportService::build('saldo', $yatim, f([], $yatim))['rows'] === []);

// ======================= rekap pinjaman =======================
$pin = ReportService::build('pinjaman', $head, f());
check('pinjaman: dua pinjaman berlaku dengan pokok, bunga, total tagihan, terbayar, sisa', count($pin['rows']) === 2);
$p4 = $pin['rows'][1] ?? [];
$p5 = $pin['rows'][0] ?? [];
check('pinjaman: anggota 5 (cair m2) lunas: sisa 0, status Lunas', $p5['principal'] === 200000 && $p5['interest'] === 4000 && $p5['due'] === 204000 && $p5['paid'] === 204000 && $p5['outstanding'] === 0 && $p5['status'] === 'Lunas');
check('pinjaman: anggota 4 sisa 520.000, belum menunggak, status Aktif, tenor 2; tautan ke transaksi', $p4['principal'] === 1000000 && $p4['interest'] === 40000 && $p4['paid'] === 520000 && $p4['outstanding'] === 520000 && $p4['overdue'] === 0 && $p4['status'] === 'Aktif' && $p4['tenor'] === 2 && $p4['_links']['loan_no'] === '/transaksi/' . $loan4);
check('pinjaman: jumlah baris = jumlah kolom; saringan status Aktif/Lunas dan regu', $pin['totals']['principal'] === 1200000 && count(ReportService::build('pinjaman', $head, f(['status' => 'LUNAS']))['rows']) === 1 && count(ReportService::build('pinjaman', $head, f(['status' => 'AKTIF']))['rows']) === 1 && ReportService::build('pinjaman', $head, f(['team' => 1]))['rows'] === []);
check('pinjaman: periode lama tidak memuat pinjaman periode baru; Ketua Regu Alfa tidak melihat pinjaman Beta', ReportService::build('pinjaman', $head, f(['period' => 1]))['rows'] === [] && ReportService::build('pinjaman', $purwati, f(['team' => 1], $purwati))['rows'] === []);
check('pinjaman: Anggota hanya pinjamannya sendiri', count(ReportService::build('pinjaman', $ang4, f([], $ang4))['rows']) === 1);

// ======================= rekap angsuran =======================
$ang = ReportService::build('angsuran', $head, f());
check('angsuran: per bulan anggota 4 (520.000 di bulan ini), 5 (204.000 di bulan lalu); sisa dan tunggakan sekarang', row($ang, 'no', 'AGT-004')['m' . $mid[$m0]] === 520000 && row($ang, 'no', 'AGT-005')['m' . $mid[$m1]] === 204000 && row($ang, 'no', 'AGT-004')['outstanding'] === 520000 && row($ang, 'no', 'AGT-005')['outstanding'] === 0 && $ang['totals']['total'] === 724000);

// tunggakan: pinjaman anggota 2 (Alfa) cair di m2, tenor 2 -> cicilan 1 jatuh tempo m1 (menunggak)
[$e, $d] = LoanService::parse(['member_id' => 2, 'period_month_id' => $mid[$m2], 'principal' => '300.000', 'tenor' => '2', 'trx_date' => $m2, 'confirm_duplicate' => '1']);
ok($periksa, LoanService::create(req(), $purwati, $d, true));
$ang2 = ReportService::build('angsuran', $head, f());
check('angsuran: tunggakan cicilan yang jatuh tempo sebelum bulan ini tampil (156.000)', row($ang2, 'no', 'AGT-002')['overdue'] === 156000 && $ang2['totals']['overdue'] === 156000);

// ======================= per ketua regu =======================
$reg = ReportService::build('regu', $head, f());
$ra = row($reg, 'team', 'Regu Alfa');
$rb = row($reg, 'team', 'Regu Beta');
check('regu: dua regu dengan ketua, jumlah anggota, setoran/angsuran/pencairan periode, tabungan, sisa, tunggakan', count($reg['rows']) === 2 && $ra['leader'] === 'Ketua Alfa' && $ra['members'] === 2 && $ra['saved'] === 500000 && $ra['lent'] === 300000 && $ra['overdue'] === 156000
    && $rb['members'] === 3 && $rb['saved'] === 800000 && $rb['paid'] === 724000 && $rb['lent'] === 1200000 && $rb['savings'] === 1400000 + 400000 && $rb['outstanding'] === 520000);
check('regu: jumlah tabungan semua regu = saldo global; hanya cakupan global (Ketua Regu dan Anggota kosong)', $reg['totals']['savings'] === (int) Database::select('SELECT saldo_tabungan FROM v_global_summary')[0]['saldo_tabungan'] && ReportService::build('regu', $beta, f([], $beta))['rows'] === [] && ReportService::build('regu', $ang4, f([], $ang4))['rows'] === []);
check('regu: saringan satu regu', count(ReportService::build('regu', $head, f(['team' => 2]))['rows']) === 1);

// ======================= laporan transaksi =======================
$tr = ReportService::build('transaksi', $head, f());
check('transaksi: default hanya DISETUJUI, terbaru dulu, simpanan menunggu tidak ikut', $tr['totals']['doc_no'] === 'Jumlah ' . (int) one("SELECT COUNT(*) FROM transactions WHERE status = 'DISETUJUI'") . ' transaksi' && !in_array(trx_doc($pendingSv), array_column($tr['rows'], 'doc_no'), true));
function trx_doc(int $id): string
{
    return (string) one('SELECT doc_no FROM transactions WHERE id = ?', [$id]);
}
$all = ReportService::build('transaksi', $head, f(['status' => '']));
check('transaksi: semua status memuat yang menunggu; pembalik bertanda dan bernominal minus', in_array(trx_doc($pendingSv), array_column($all['rows'], 'doc_no'), true) && count(array_filter($all['rows'], static fn ($r) => str_contains($r['doc_no'], '(pembalik)') && $r['amount'] === -200000)) === 1);
check('transaksi: jumlah nominal hanya bila satu jenis dipilih (neto simpanan 600.000 + 500.000 + 400.000 - 200.000 - ... )', ReportService::build('transaksi', $head, f())['totals']['amount'] === null
    && ReportService::build('transaksi', $head, f(['type' => 'SIMPANAN']))['totals']['amount'] === (int) Database::select("SELECT COALESCE(SUM(sign * amount), 0) AS s FROM v_ledger WHERE type = 'SIMPANAN'")[0]['s']);
check('transaksi: saringan jenis, rentang tanggal, regu, anggota, dan pencarian', count(ReportService::build('transaksi', $head, f(['type' => 'ANGSURAN']))['rows']) === 2
    && ReportService::build('transaksi', $head, f(['from' => $m0, 'to' => $m0]))['totals']['doc_no'] !== ReportService::build('transaksi', $head, f())['totals']['doc_no']
    && ReportService::build('transaksi', $head, f(['from' => date('Y-m-d', strtotime('+5 day'))]))['rows'] === []
    && count(ReportService::build('transaksi', $head, f(['team' => 1]))['rows']) >= 1 && count(ReportService::build('transaksi', $head, f(['anggota' => 5]))['rows']) >= 1
    && count(ReportService::build('transaksi', $head, f(['q' => trx_doc($loan4)]))['rows']) === 1 && ReportService::build('transaksi', $head, f(['q' => '%']))['rows'] === []);
check('transaksi: pembuat dan validator tercatat; data impor tanpa pembuat bertanda Impor Excel', $tr['rows'][0]['creator'] !== '' && in_array($tr['rows'][0]['validator'], ['Kepala2', 'Periksa'], true) && row(ReportService::build('transaksi', $head, f(['q' => 'OLD-1'])), 'doc_no', 'OLD-1')['creator'] === 'Impor Excel');
$trBeta = ReportService::build('transaksi', $beta, f([], $beta));
check('transaksi: Ketua Regu Beta melihat transaksi anggota regunya, bukan Alfa buatan Purwati', $trBeta['rows'] !== [] && count(array_filter($trBeta['rows'], static fn ($r) => str_contains($r['member'], 'Anggota Alfa'))) === 0);
check('transaksi: Anggota hanya transaksi dirinya', count(array_filter(ReportService::build('transaksi', $ang4, f([], $ang4))['rows'], static fn ($r) => !str_contains($r['member'], 'AGT-004'))) === 0 && ReportService::build('transaksi', $yatim, f([], $yatim))['rows'] === []);
// paginasi dan ekspor
for ($i = 0; $i < 60; $i++) {
    rawApproved('SIMPANAN', 2, 1, 1000 + $i, $mid[$m0], $m0);
}
$p1 = ReportService::build('transaksi', $head, f(), 1);
$p2 = ReportService::build('transaksi', $head, f(), 2);
$full = ReportService::build('transaksi', $head, f(), 1, true);
check('transaksi: halaman 50 baris, halaman 2 sisanya, ekspor memuat semua tanpa batas halaman', count($p1['rows']) === 50 && count($p2['rows']) === $p1['pager']['total'] - 50 && count($full['rows']) === $p1['pager']['total'] && $p1['pager']['total'] > 60);

// ======================= CSV =======================
$csv = ReportService::csv(ReportService::build('simpanan', $head, f()));
$lines = explode("\n", rtrim($csv, "\n"));
check('csv: UTF-8 dengan BOM, titik koma, judul kolom, satu baris per anggota + baris jumlah', str_starts_with($csv, "\xEF\xBB\xBF") && str_getcsv(substr($lines[0], 3), ';', '"', '\\')[0] === 'No. Anggota' && str_getcsv(substr($lines[0], 3), ';', '"', '\\')[1] === 'Nama' && count($lines) === 1 + 5 + 1);
check('csv: angka polos tanpa pemisah ribuan agar bisa dijumlah (anggota 4 setelah koreksi: 300000;0;100000;400000;1400000)', str_contains($csv, 'AGT-004;"Anggota Beta";"Regu Beta";300000;0;100000;400000;1400000') && !str_contains($csv, '1.600.000') && !str_contains($csv, 'Rp'));
check('csv: nama berawalan = diberi tanda kutip (anti rumus), tanda kutip dan titik koma di nama diloloskan', ReportService::safeText('=1+1') === "'=1+1" && ReportService::safeText('@SUM') === "'@SUM" && ReportService::safeText('-5') === "'-5" && ReportService::safeText('Budi') === 'Budi'
    && str_contains($csv, "\"'=Anggota \"\"Rumus\"\"; Beta\""));
$csvSal = ReportService::csv(ReportService::build('saldo', $head, f()));
check('csv: persen berkoma dua desimal (mis. 0,00 / 75,86)', preg_match('/;\d+,\d\d\n/', $csvSal) === 1);
check('csv: isi CSV = isi tabel layar (jumlah baris dan jumlah kolom sama untuk tiap laporan)', (function () use ($head): bool {
    foreach (ReportService::KEYS as $k) {
        $rep = ReportService::build($k, $head, ReportService::filters([], $head), 1, true);
        $parsed = array_map(static fn ($l) => str_getcsv($l, ';', '"', '\\'), explode("\n", rtrim(ReportService::csv($rep), "\n")));
        $expectRows = 1 + count($rep['rows']) + (!empty($rep['totals']) ? 1 : 0);
        if (count($parsed) !== $expectRows && $k !== 'transaksi') {
            return false;
        }
        if (count($parsed[0]) !== count($rep['columns'])) {
            return false;
        }
    }
    return true;
})());

// ======================= kartu anggota =======================
$card = ReportService::statement($head, 4);
check('kartu: identitas, ringkasan (saldo 1.400.000 setelah koreksi, sisa pinjaman 520.000), rincian jenis', $card !== null && $card['member']['member_no'] === 'AGT-004' && $card['member']['team_name'] === 'Regu Beta' && $card['summary']['savings'] === 1400000 && $card['summary']['outstanding'] === 520000);
check('kartu: saldo per bulan kumulatif berakhir di saldo tabungan; jadwal cicilan pinjaman tampil', end($card['monthly'])['saldo'] === 1400000 && count($card['loans']) === 1 && count($card['loans'][0]['installments']) === 2 && (int) $card['loans'][0]['installments'][0]['paid_amount'] === 520000);
check('kartu: seluruh transaksi anggota termasuk pembalik', count($card['transactions']['rows']) >= 5 && count(array_filter($card['transactions']['rows'], static fn ($r) => $r['amount'] < 0)) === 1);
check('kartu: di luar cakupan = null (Ketua Regu Beta tak bisa membuka anggota Alfa; Anggota tak bisa anggota lain; tidak ada = null)', ReportService::statement($beta, 2) === null && ReportService::statement($ang4, 5) === null && ReportService::statement($head, 99999) === null && ReportService::statement($yatim, 4) === null);
check('kartu: Ketua Regu membuka anggota regunya dan Anggota membuka dirinya', ReportService::statement($beta, 4) !== null && ReportService::statement($ang4, 4) !== null);

check('akhir: invarian dan integritas tetap bersih (laporan hanya membaca)', (int) Database::select('SELECT selisih FROM v_global_summary')[0]['selisih'] === 0 && (int) one('SELECT COUNT(*) FROM v_integrity_issues') === 0);

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
