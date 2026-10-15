<?php
declare(strict_types=1);

/**
 * Tes Report Pembagian Bunga (ProfitReport): rincian per siklus, cakupan per peran, kontrol total, snapshot hanya-tambah.
 * Memakai skenario ProfitShare yang angkanya dihitung tangan (lihat tests/profit.php): mode Excel => X penabung 9.500,
 * Y penabung 28.500, peminjam A (=X) 2.533, B (=Y) 43.067; pool 96.000, tak terbagi 8.000.
 * MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\bagihasil.php
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
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.2', 'HTTP_USER_AGENT' => 'tes-bagihasil']);
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
function trx(string $type, int $member, int $amount, int $monthId, string $date): int
{
    static $n = 0;
    $n++;
    $pdo = Database::pdo();
    $pdo->prepare('INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(["T-BH-{$n}", "BH-{$n}", $type, $member, null, $monthId, $date, $amount]);
    return (int) $pdo->lastInsertId();
}
function approve(int $id): void
{
    $pdo = Database::pdo();
    $pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI' WHERE id = {$id}");
    $pdo->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$id}");
}
/** @param array<int,int> $dueIds @param array<int,int> $amounts */
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

// ---------------- fixture ----------------
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
foreach ([['kepala', ['HEAD'], null], ['ketua.x', ['KETUA_REGU'], 'AGT-001'], ['ketua.y', ['KETUA_REGU'], 'AGT-002'], ['anggota.z', ['ANGGOTA'], 'AGT-003']] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$head   = User::findActive($ids['kepala']);
$ketuaX = User::findActive($ids['ketua.x']);
$ketuaY = User::findActive($ids['ketua.y']);
$angZ   = User::findActive($ids['anggota.z']);
$period = (int) one('SELECT id FROM periods LIMIT 1');
$f = static fn (?array $u, array $q = []): array => ReportService::filters($q + ['periode' => (string) $period], $u);

// ======================= rincian per siklus (ProfitShare detail) =======================
$core = static fn (array $ms): array => array_map(static fn (array $m): array => array_intersect_key($m, array_flip(['saver', 'borrower', 'saver_gross', 'borrower_gross', 'reserve_exempt'])), $ms);
$plain = ProfitShare::compute($period, true);
$det   = ProfitShare::compute($period, true, true);
check('detail: opsi rincian TIDAK mengubah angka (sama persis dengan tanpa rincian)', $core($det['members']) === $core($plain['members']) && abs($det['interest_pool'] - $plain['interest_pool']) < 1e-9 && abs($det['undistributed'] - $plain['undistributed']) < 1e-9);
check('detail: 5 siklus (akhir bulan 7..11, bunga diterima bulan 8..12)', array_keys($det['cycles']) === [7, 8, 9, 10, 11] && $det['cycles'][7]['month'] === '2027-07-01' && $det['cycles'][7]['interest_month'] === '2027-08-01');
check('detail: bunga diterima bulan 8 = 40.000, bulan 9..12 = 20.000; pool penabung/peminjam = 40% masing-masing', abs($det['cycles'][7]['interest'] - 40000) < 0.001 && abs($det['cycles'][8]['interest'] - 20000) < 0.001 && abs($det['cycles'][7]['saver_pool'] - 16000) < 0.001 && abs($det['cycles'][7]['borrower_pool'] - 16000) < 0.001);
$traceOk = true;
foreach ($det['cycles'] as $k => $c) {
    $sg = 0.0;
    $bg = 0.0;
    foreach ($det['members'] as $m) {
        $sg += $m['rows'][$k]['sg'] ?? 0.0;
        $bg += $m['rows'][$k]['bg'] ?? 0.0;
    }
    $traceOk = $traceOk && abs($sg - ($c['saver_paid'] ? $c['saver_pool'] : 0.0)) < 0.001 && abs($bg - ($c['borrower_paid'] ? $c['borrower_pool'] : 0.0)) < 0.001;
}
check('detail: tiap siklus, jumlah bagian semua anggota = pool siklus itu (kecuali yang tidak dibagikan): dapat ditelusuri', $traceOk);
check('detail: siklus 10 pool penabung tidak dibagikan (mode Excel), peminjam tetap dibagikan; dasar sisa bunga sejak siklus 4', $det['cycles'][10]['saver_paid'] === false && $det['cycles'][10]['borrower_paid'] === true && $det['cycles'][7]['basis'] === 'bunga');
$rowsSum = 0.0;
foreach ($det['members'] as $m) {
    foreach ($m['rows'] as $r) {
        $rowsSum += ($r['sg'] ?? 0.0) + ($r['bg'] ?? 0.0);
    }
}
check('detail: jumlah semua bagian per siklus + tak terbagi = pool total (tidak ada rupiah hilang atau ganda)', abs($rowsSum + $det['undistributed'] - $det['interest_pool']) < 0.001);
check('posisi akhir bulan data terakhir (bulan 7): X saldo 100.000 sisa 1.020.000, Y saldo 300.000 sisa 1.100.000 (pokok + bunga)', $plain['members'][1]['savings_last'] === 100000 && $plain['members'][2]['savings_last'] === 300000 && $plain['members'][1]['outstanding_last'] === 1020000 && $plain['members'][2]['outstanding_last'] === 1100000);

// ======================= laporan tabel dan cakupan =======================
$rep = ReportService::build('bagihasil', $head, $f($head));
$byNo = array_column($rep['rows'], null, 'no');
check('laporan: Superadmin melihat semua anggota (3), kunci laporan terdaftar', count($rep['rows']) === 3 && $rep['key'] === 'bagihasil' && in_array('bagihasil', ReportService::KEYS, true));
check('laporan: angka X = penabung 9.500 + peminjam 2.533 = 12.033; Y = 28.500 + 43.067 = 71.567; Z = 0', $byNo['AGT-001']['saver'] === 9500 && $byNo['AGT-001']['borrower'] === 2533 && $byNo['AGT-001']['total'] === 12033
    && $byNo['AGT-002']['saver'] === 28500 && $byNo['AGT-002']['borrower'] === 43067 && $byNo['AGT-002']['total'] === 71567 && $byNo['AGT-003']['total'] === 0);
check('laporan: kolom regu, Ketua Regu, saldo tabungan, dan sisa pinjaman', $byNo['AGT-001']['team'] === 'Regu X' && $byNo['AGT-001']['leader'] === 'Anggota X' && $byNo['AGT-002']['leader'] === 'Anggota Y' && $byNo['AGT-001']['savings'] === 100000 && $byNo['AGT-002']['outstanding'] === 1100000);
check('laporan: bruto - cadangan = bagi hasil (selisih pembulatan <= 1), cadangan Y sekitar 5%', abs($byNo['AGT-002']['gross'] - $byNo['AGT-002']['reserve'] - $byNo['AGT-002']['total']) <= 1 && $byNo['AGT-002']['reserve'] > 3500 && $byNo['AGT-002']['reserve'] < 3800);
check('laporan: jumlah baris = jumlah per kolom (total 83.600 = 12.033 + 71.567)', $rep['totals']['total'] === 83600 && $rep['totals']['saver'] === 38000 && $rep['totals']['borrower'] === 45600);
check('laporan: kontrol pool: dibagikan bruto + tak terbagi (8.000) = pool 96.000, seimbang', $rep['control']['pool'] === 96000 && $rep['control']['undistributed'] === 8000 && $rep['control']['ok'] === true && abs($rep['control']['gross'] + 8000 - 96000) <= 1);
check('laporan: status, tanggal hitung, dan data sampai tercantum (perkiraan, mode Excel)', $rep['meta']['source'] === 'langsung' && $rep['meta']['mode'] === 'EXCEL' && $rep['meta']['as_of'] === '2027-07-01' && str_contains($rep['subtitle'], 'Perkiraan') && str_contains($rep['subtitle'], 'Juli 2027') && str_contains($rep['note'], 'Excel'));
check('laporan: tautan nama ke rincian anggota', $byNo['AGT-001']['_links']['name'] === '/laporan/bagihasil/1?periode=' . $period);
$teamIds = Database::select('SELECT id FROM team_leaders ORDER BY id');
$rx = ReportService::build('bagihasil', $head, $f($head, ['regu' => (string) $teamIds[0]['id']]));
check('filter: regu dan pencarian nama bekerja (Regu X = X dan Z; cari "Anggota Y" = Y saja)', array_column($rx['rows'], 'no') === ['AGT-001', 'AGT-003'] && array_column(ReportService::build('bagihasil', $head, $f($head, ['q' => 'Anggota Y']))['rows'], 'no') === ['AGT-002']);
$kx = ReportService::build('bagihasil', $ketuaX, $f($ketuaX));
check('cakupan: Ketua Regu X hanya melihat anggota regunya (X dan Z), tidak Y; tanpa kontrol global', array_column($kx['rows'], 'no') === ['AGT-001', 'AGT-003'] && !isset($kx['control']) && $kx['totals']['total'] === 12033);
$ky = ReportService::build('bagihasil', $ketuaY, $f($ketuaY, ['regu' => (string) $teamIds[0]['id']]));
check('cakupan: Ketua Regu Y tidak bisa memaksa regu lain lewat parameter regu; hanya Y', array_column($ky['rows'], 'no') === ['AGT-002']);
$az = ReportService::build('bagihasil', $angZ, $f($angZ));
check('cakupan: Anggota hanya melihat dirinya sendiri', array_column($az['rows'], 'no') === ['AGT-003'] && !isset($az['control']));
check('cakupan: tamu (tanpa pengguna) tidak melihat apa pun', ReportService::build('bagihasil', null, $f(null))['rows'] === []);

// ======================= rincian anggota =======================
$dh = ProfitReport::memberDetail($head, 2, $f($head));
check('rincian: Superadmin melihat anggota Y: 5 siklus, bunga bulan itu, dan jumlah seluruh koperasi', $dh !== null && count($dh['cycles']) === 5 && $dh['global'] === true && isset($dh['cycles'][0]['interest'], $dh['cycles'][0]['sum_savings']));
$sumS = 0.0;
$sumB = 0.0;
foreach ($dh['cycles'] as $c) {
    $sumS += $c['saver'];
    $sumB += $c['borrower'];
}
check('rincian: jumlah bagian per siklus = bruto anggota; bruto - cadangan = bagi hasil di laporan (28.500 dan 43.067)', abs($sumS - $dh['sum']['saver_gross']) < 0.001 && abs($sumB - $dh['sum']['borrower_gross']) < 0.001 && $dh['sum']['saver'] === 28500 && $dh['sum']['borrower'] === 43067 && $dh['sum']['total'] === 71567);
check('rincian: porsi penabung Y = 75% (300.000 / 400.000) pada siklus 7', abs($dh['cycles'][0]['saver_share'] - 0.75) < 1e-9);
check('rincian: Ketua Regu X membuka anggota regunya (Z) tetapi TIDAK anggota regu lain (Y); Anggota tidak membuka orang lain', ProfitReport::memberDetail($ketuaX, 3, $f($ketuaX)) !== null && ProfitReport::memberDetail($ketuaX, 2, $f($ketuaX)) === null && ProfitReport::memberDetail($angZ, 1, $f($angZ)) === null);
$dz = ProfitReport::memberDetail($ketuaX, 1, $f($ketuaX));
check('rincian: angka seluruh koperasi (bunga bulan itu, pool, jumlah semua anggota) TIDAK ikut untuk Ketua Regu', $dz['global'] === false && !isset($dz['cycles'][0]['interest']) && !isset($dz['cycles'][0]['sum_savings']) && !isset($dz['cycles'][0]['sum_basis']) && isset($dz['cycles'][0]['saver_share']));

// ======================= snapshot =======================
check('snapshot: awalnya belum ada', ProfitReport::snapshots($period) === []);
check('snapshot: Superadmin menetapkan; satu snapshot dengan baris untuk anggota yang punya saldo atau pinjaman (2; Z tanpa keduanya tampil 0 di laporan), tercatat di audit', (function () use ($head, $period): bool {
    $id = ProfitReport::createSnapshot(req(), $head, $period, 'Dasar THR 2027');
    return $id > 0 && (int) one('SELECT COUNT(*) FROM profit_share_lines WHERE snapshot_id = ?', [$id]) === 2 && (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'PROFIT_SNAPSHOT_CREATED' AND entity_id = ?", [$id]) === 1;
})());
$snap = ProfitReport::snapshots($period)[0];
check('snapshot: tersimpan dengan bulan data, penetap, dan catatan', $snap['as_of'] === '2027-07-01' && $snap['created_by'] === 'Kepala' && $snap['note'] === 'Dasar THR 2027');
$sn = ReportService::build('bagihasil', $head, $f($head, ['snapshot' => (string) $snap['id']]));
check('snapshot: angkanya sama dengan perhitungan langsung saat dibuat, berstatus ditetapkan', $sn['meta']['source'] === 'snapshot' && array_column($sn['rows'], 'total', 'no') === array_column($rep['rows'], 'total', 'no') && str_contains($sn['subtitle'], 'Snapshot ditetapkan'));
check('snapshot: kontrol total snapshot seimbang (bruto + tak terbagi = pool)', $sn['control']['ok'] === true && $sn['control']['pool'] === 96000 && $sn['control']['undistributed'] === 8000);
check('snapshot: penetapan kedua untuk keadaan data yang sama ditolak (tidak ada pembagian ganda)', str_contains(violation(fn () => ProfitReport::createSnapshot(req(), $head, $period, null))['_form'] ?? '', 'sudah ada') && (int) one('SELECT COUNT(*) FROM profit_share_snapshots') === 1);
check('snapshot: tidak bisa diubah atau dihapus (trigger): UPDATE dan DELETE pada snapshot maupun barisnya ditolak', (function () use ($pdo): bool {
    $blocked = 0;
    foreach (["UPDATE profit_share_snapshots SET note = 'x'", 'DELETE FROM profit_share_snapshots', 'UPDATE profit_share_lines SET saver_net = 1', 'DELETE FROM profit_share_lines'] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            $blocked++;
        }
    }
    return $blocked === 4;
})());

// perubahan transaksi sesudah snapshot: perhitungan langsung berubah, snapshot tidak
$s2 = trx('SIMPANAN', 1, 200000, $mo[9], '2027-09-05');
$pdo->exec("INSERT INTO savings (transaction_id, kind) VALUES ({$s2}, 'SUKARELA')");
approve($s2);
$repLive = ReportService::build('bagihasil', $head, $f($head));
$repSnap = ReportService::build('bagihasil', $head, $f($head, ['snapshot' => (string) $snap['id']]));
check('histori: setelah simpanan baru, perhitungan langsung berubah (bulan data 9, saldo X naik) tetapi snapshot lama TETAP sama', $repLive['meta']['as_of'] === '2027-09-01' && array_column($repLive['rows'], 'savings', 'no')['AGT-001'] === 300000
    && array_column($repSnap['rows'], 'total', 'no') === array_column($rep['rows'], 'total', 'no') && array_column($repSnap['rows'], 'savings', 'no')['AGT-001'] === 100000);
check('histori: snapshot kedua untuk bulan data baru diperbolehkan; yang lama tidak tertimpa', ProfitReport::createSnapshot(req(), $head, $period, null) > 0 && count(ProfitReport::snapshots($period)) === 2);
check('snapshot: id snapshot tak dikenal jatuh ke perhitungan langsung (bukan galat)', ReportService::build('bagihasil', $head, $f($head, ['snapshot' => '99999']))['meta']['source'] === 'langsung');
check('snapshot: rincian anggota dari snapshot sama dengan yang disimpan (Y 28.500 + 43.067)', (function () use ($head, $f, $snap): bool {
    $d = ProfitReport::memberDetail($head, 2, $f($head, ['snapshot' => (string) $snap['id']]));
    return $d !== null && $d['sum']['total'] === 71567 && count($d['cycles']) === 5 && $d['data']['source'] === 'snapshot';
})());

// ======================= pembatalan snapshot =======================
$second = ProfitReport::snapshots($period)[0];   // terbaru dulu: bulan data 9; [1] = bulan data 7 ($snap)
check('batal: snapshot yang masih berlaku tidak bertanda batal', $second['as_of'] === '2027-09-01' && $second['voided'] === null && ProfitReport::snapshots($period)[1]['voided'] === null);
check('batal: alasan wajib (kosong atau kurang dari 5 karakter ditolak), tidak ada catatan tersimpan',
    isset(violation(fn () => ProfitReport::voidSnapshot(req(), $head, $period, $second['id'], '   '))['reason'])
    && isset(violation(fn () => ProfitReport::voidSnapshot(req(), $head, $period, $second['id'], 'abc'))['reason'])
    && (int) one('SELECT COUNT(*) FROM profit_share_snapshot_voids') === 0);
check('batal: snapshot tak dikenal ditolak', isset(violation(fn () => ProfitReport::voidSnapshot(req(), $head, $period, 99999, 'alasan yang cukup'))['_form']) && (int) one('SELECT COUNT(*) FROM profit_share_snapshot_voids') === 0);
$linesBefore = (int) one('SELECT COUNT(*) FROM profit_share_lines');
$snapsBefore = (int) one('SELECT COUNT(*) FROM profit_share_snapshots');
$noteBefore  = (string) one('SELECT note FROM profit_share_snapshots WHERE id = ?', [$second['id']]);
ProfitReport::voidSnapshot(req(), $head, $period, $second['id'], 'Ada simpanan yang terlewat');
$afterVoid = ProfitReport::snapshots($period)[0];
check('batal: tercatat dengan alasan, pelaku, dan waktu; di audit; snapshot dan barisnya TIDAK berubah',
    $afterVoid['voided'] !== null && $afterVoid['voided']['reason'] === 'Ada simpanan yang terlewat' && $afterVoid['voided']['by'] === 'Kepala' && $afterVoid['voided']['at'] !== ''
    && (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'PROFIT_SNAPSHOT_VOIDED' AND entity_id = ?", [$second['id']]) === 1
    && (int) one('SELECT COUNT(*) FROM profit_share_lines') === $linesBefore && (int) one('SELECT COUNT(*) FROM profit_share_snapshots') === $snapsBefore
    && (string) one('SELECT note FROM profit_share_snapshots WHERE id = ?', [$second['id']]) === $noteBefore);
check('batal: tidak bisa dibatalkan dua kali', str_contains(violation(fn () => ProfitReport::voidSnapshot(req(), $head, $period, $second['id'], 'Coba lagi dua kali'))['_form'] ?? '', 'sudah dibatalkan') && (int) one('SELECT COUNT(*) FROM profit_share_snapshot_voids') === 1);
$voidRep = ReportService::build('bagihasil', $head, $f($head, ['snapshot' => (string) $second['id']]));
check('batal: laporan snapshot batal masih terbaca, berstatus DIBATALKAN, memuat alasan, dan kontrolnya tetap seimbang',
    $voidRep['meta']['source'] === 'snapshot' && $voidRep['meta']['voided'] !== null && str_contains($voidRep['subtitle'], 'Snapshot DIBATALKAN') && str_contains($voidRep['note'], 'Ada simpanan yang terlewat') && $voidRep['rows'] !== [] && $voidRep['control']['ok'] === true);
check('batal: rincian anggota dari snapshot batal membawa penanda batal', ProfitReport::memberDetail($head, 2, $f($head, ['snapshot' => (string) $second['id']]))['data']['voided'] !== null && ProfitReport::memberDetail($head, 2, $f($head, ['snapshot' => (string) $snap['id']]))['data']['voided'] === null);
check('batal: catatan pembatalan tidak bisa diubah atau dihapus (trigger)', (function () use ($pdo): bool {
    $blocked = 0;
    foreach (["UPDATE profit_share_snapshot_voids SET reason = 'x'", 'DELETE FROM profit_share_snapshot_voids'] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            $blocked++;
        }
    }
    return $blocked === 2;
})());
check('batal: bulan data yang sama boleh ditetapkan ulang; yang dibatalkan tetap ada; penetapan ganda atas yang berlaku tetap ditolak',
    ProfitReport::createSnapshot(req(), $head, $period, 'Ulang setelah batal') > 0 && count(ProfitReport::snapshots($period)) === 3
    && str_contains(violation(fn () => ProfitReport::createSnapshot(req(), $head, $period, null))['_form'] ?? '', 'sudah ada') && count(ProfitReport::snapshots($period)) === 3);
check('batal: snapshot bulan lain tidak terpengaruh', array_values(array_filter(ProfitReport::snapshots($period), static fn (array $s): bool => $s['id'] === $snap['id']))[0]['voided'] === null);

echo "\n" . ($failed === [] ? "Semua {$passed} pemeriksaan lulus.\n" : count($failed) . " GAGAL dari " . ($passed + count($failed)) . ".\n");
exit($failed === [] ? 0 : 1);
