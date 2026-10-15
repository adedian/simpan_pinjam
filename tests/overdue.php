<?php
declare(strict_types=1);

/**
 * Tes Tunggakan Pinjaman (OverdueService) dan ringkasan keuangan regu (TeamFinance): definisi tunggakan, pembayaran sebagian,
 * pembayaran yang menunggu validasi, isolasi antar-regu (Yanti vs Siti), dan konsistensi angka antara dashboard, laporan, dan view saldo.
 * Tanggal dibuat relatif terhadap HARI INI agar tes tidak basi. MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\overdue.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Models\Dashboard;
use App\Models\User;
use App\Services\Migrator;
use App\Services\OverdueService;
use App\Services\ReportService;
use App\Services\TeamFinance;
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
/** Transaksi baru berstatus DRAFT; rincian (savings, loans, cicilan, alokasi) harus diisi SEBELUM settle() (trigger). */
function trx(string $type, int $member, ?int $team, int $amount, int $monthId, string $date): int
{
    static $n = 0;
    $n++;
    $pdo = Database::pdo();
    $pdo->prepare('INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(["T-OD-{$n}", "OD-{$n}", $type, $member, $team, $monthId, $date, $amount]);
    return (int) $pdo->lastInsertId();
}
function settle(int $id, string $status = 'DISETUJUI'): void
{
    $pdo = Database::pdo();
    $pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI' WHERE id = {$id}");
    if ($status === 'DISETUJUI') {
        $pdo->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$id}");
    }
}
/** @param array<int,int> $dueIds @param array<int,int> $amounts @return array{trx:int,loan:int,inst:array<int,int>} */
function loan(int $member, int $team, int $principal, int $tenor, int $interest, int $monthId, string $date, array $dueIds, array $amounts): array
{
    $pdo = Database::pdo();
    $id = trx('PENCAIRAN_PINJAMAN', $member, $team, $principal, $monthId, $date);
    $pdo->prepare('INSERT INTO loans (transaction_id, member_id, principal, tenor_months, rate_pct_month, total_interest, disbursed_on) VALUES (?,?,?,?,?,?,?)')->execute([$id, $member, $principal, $tenor, '2.00', $interest, $date]);
    $loanId = (int) $pdo->lastInsertId();
    $inst = [];
    foreach ($amounts as $i => $a) {
        $pdo->prepare('INSERT INTO loan_installments (loan_id, seq, due_month_id, amount_due) VALUES (?,?,?,?)')->execute([$loanId, $i + 1, $dueIds[$i], $a]);
        $inst[] = (int) $pdo->lastInsertId();
    }
    settle($id);
    return ['trx' => $id, 'loan' => $loanId, 'inst' => $inst];
}
function pay(int $member, int $team, int $monthId, int $instId, int $amount, string $status = 'DISETUJUI'): int
{
    $id = trx('ANGSURAN', $member, $team, $amount, $monthId, date('Y-m-d'));
    Database::pdo()->prepare('INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES (?,?,?)')->execute([$id, $instId, $amount]);
    settle($id, $status);
    return $id;
}
$monthAt = static fn (int $offset): string => (new DateTimeImmutable('first day of this month'))->modify(($offset >= 0 ? '+' : '') . $offset . ' month')->format('Y-m-d');
$lastDay = static fn (string $monthDate): string => (new DateTimeImmutable($monthDate))->modify('last day of this month')->format('Y-m-d');
$daysLate = static fn (string $monthDate): int => (int) (new DateTimeImmutable(date('Y-m-d')))->diff(new DateTimeImmutable($lastDay($monthDate)))->days;

// ---------------- fixture ----------------
// Periode dari 6 bulan lalu sampai 6 bulan ke depan. Bulan 0 = bulan berjalan.
$pdo->prepare("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji tunggakan', ?, ?, 'AKTIF')")->execute([$monthAt(-6), $lastDay($monthAt(6))]);
$mo = [];
for ($o = -6; $o <= 6; $o++) {
    $pdo->prepare('INSERT INTO period_months (period_id, month_date) VALUES (1, ?)')->execute([$monthAt($o)]);
    $mo[$o] = (int) $pdo->lastInsertId();
}
$pdo->exec("INSERT INTO members (member_no, name, active_from, status) VALUES ('AGT-001', 'Yanti A1', '2020-01-01', 'AKTIF'), ('AGT-002', 'Anggota A2', '2020-01-01', 'AKTIF'), ('AGT-003', 'Siti B1', '2020-01-01', 'AKTIF'), ('AGT-004', 'Anggota B2', '2020-01-01', 'AKTIF')");
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Yanti', 1), ('Regu Siti', 3)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1, 1, '2020-01-01'), (2, 1, '2020-01-01'), (3, 2, '2020-01-01'), (4, 2, '2020-01-01')");
$tA = 1;
$tB = 2;
// tabungan (menambah kas dan saldo)
foreach ([[1, $tA, 100000], [2, $tA, 50000], [3, $tB, 200000]] as [$m, $t, $a]) {
    $s = trx('SIMPANAN', $m, $t, $a, $mo[-6], $monthAt(-6));
    $pdo->exec("INSERT INTO savings (transaction_id, kind) VALUES ({$s}, 'WAJIB')");
    settle($s);
}
// A1: pinjaman 1.000.000 tenor 5, bunga 100.000; cicilan 220.000 jatuh tempo bulan -3, -2, -1, 0, +1
$la1 = loan(1, $tA, 1000000, 5, 100000, $mo[-4], $monthAt(-4), [$mo[-3], $mo[-2], $mo[-1], $mo[0], $mo[1]], [220000, 220000, 220000, 220000, 220000]);
pay(1, $tA, $mo[-3], $la1['inst'][0], 220000);    // cicilan 1 lunas
pay(1, $tA, $mo[-2], $la1['inst'][1], 100000);    // cicilan 2 dibayar sebagian
// A2: pinjaman 500.000 tenor 1, bunga 10.000; cicilan 510.000 jatuh tempo bulan -1; pembayaran MENUNGGU VALIDASI
$la2 = loan(2, $tA, 500000, 1, 10000, $mo[-2], $monthAt(-2), [$mo[-1]], [510000]);
$pendingPay = pay(2, $tA, $mo[0], $la2['inst'][0], 510000, 'MENUNGGU_VALIDASI');
// B1: pinjaman 500.000 tenor 2, bunga 20.000... dua cicilan 300.000 jatuh tempo bulan -2 dan -1, belum dibayar
$lb1 = loan(3, $tB, 500000, 2, 20000, $mo[-3], $monthAt(-3), [$mo[-2], $mo[-1]], [300000, 300000]);
// B2: pinjaman LUNAS (tidak boleh muncul): 100.000 tenor 1 bunga 2.000, cicilan 102.000 bulan -1 dibayar penuh
$lb2 = loan(4, $tB, 100000, 1, 2000, $mo[-2], $monthAt(-2), [$mo[-1]], [102000]);
pay(4, $tB, $mo[-1], $lb2['inst'][0], 102000);

$ids = [];
foreach ([['kepala', ['HEAD'], null], ['yanti', ['KETUA_REGU'], 'AGT-001'], ['siti', ['KETUA_REGU'], 'AGT-003'], ['anggota.a2', ['ANGGOTA'], 'AGT-002']] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$head   = User::findActive($ids['kepala']);
$yanti  = User::findActive($ids['yanti']);
$siti   = User::findActive($ids['siti']);
$angA2  = User::findActive($ids['anggota.a2']);

// ======================= definisi tunggakan =======================
$all = OverdueService::items($head);
$bySeq = [];
foreach ($all as $i) {
    $bySeq[$i['loan_no'] . '#' . $i['seq']] = $i;
}
$noA1 = (string) one('SELECT doc_no FROM transactions WHERE id = ?', [$la1['trx']]);
$noA2 = (string) one('SELECT doc_no FROM transactions WHERE id = ?', [$la2['trx']]);
$noB1 = (string) one('SELECT doc_no FROM transactions WHERE id = ?', [$lb1['trx']]);
check('tunggakan: hanya cicilan yang bulan jatuh temponya SUDAH LEWAT; bulan berjalan dan masa depan tidak dihitung (5 cicilan: A1 ke-2 dan ke-3, A2 ke-1, B1 ke-1 dan ke-2)', count($all) === 5 && !isset($bySeq[$noA1 . '#4']) && !isset($bySeq[$noA1 . '#5']) && isset($bySeq[$noA1 . '#2'], $bySeq[$noA1 . '#3'], $bySeq[$noA2 . '#1'], $bySeq[$noB1 . '#1'], $bySeq[$noB1 . '#2']));
check('tunggakan: cicilan yang sudah lunas tidak dihitung (A1 ke-1) dan pinjaman yang lunas penuh tidak muncul (B2)', !isset($bySeq[$noA1 . '#1']) && !in_array('AGT-004', array_column($all, 'member_no'), true));
check('tunggakan: pembayaran sebagian hanya menyisakan sisa kewajiban (A1 ke-2: 220.000 - 100.000 = 120.000), berstatus dibayar sebagian', $bySeq[$noA1 . '#2']['remaining'] === 120000 && $bySeq[$noA1 . '#2']['paid'] === 100000 && $bySeq[$noA1 . '#2']['amount_due'] === 220000 && $bySeq[$noA1 . '#2']['status'] === 'Dibayar sebagian');
check('tunggakan: cicilan yang tidak dibayar sama sekali menyisakan seluruh nominal, status belum dibayar (A1 ke-3: 220.000)', $bySeq[$noA1 . '#3']['remaining'] === 220000 && $bySeq[$noA1 . '#3']['paid'] === 0 && $bySeq[$noA1 . '#3']['status'] === 'Belum dibayar');
check('tunggakan: pembayaran yang MENUNGGU validasi belum mengurangi tunggakan (A2 tetap 510.000) dan nominalnya dicatat terpisah', $bySeq[$noA2 . '#1']['remaining'] === 510000 && $bySeq[$noA2 . '#1']['pending'] === 510000 && $bySeq[$noA2 . '#1']['status'] === 'Belum dibayar');
check('tunggakan: tanggal jatuh tempo = akhir bulan jatuh tempo dan hari keterlambatan dihitung dari sana (A1 ke-2 jatuh tempo bulan -2)', $bySeq[$noA1 . '#2']['due_date'] === $lastDay($monthAt(-2)) && $bySeq[$noA1 . '#2']['days_late'] === $daysLate($monthAt(-2)) && $bySeq[$noA1 . '#2']['days_late'] >= 28 && $bySeq[$noA1 . '#3']['days_late'] === $daysLate($monthAt(-1)) && $bySeq[$noA1 . '#3']['days_late'] >= 1);
check('tunggakan: terlama di atas (urut hari terlambat menurun)', (function () use ($all): bool {
    $d = array_column($all, 'days_late');
    $sorted = $d;
    rsort($sorted);
    return $d === $sorted;
})());
check('tunggakan: tautan ke pinjaman (id transaksi pencairan), anggota, nomor pinjaman, dan jadwal (cicilan ke-n dari tenor) tersedia', $bySeq[$noA1 . '#2']['loan_trx_id'] === $la1['trx'] && $bySeq[$noA1 . '#2']['tenor'] === 5 && $bySeq[$noA1 . '#2']['member_id'] === 1 && $bySeq[$noA1 . '#2']['name'] === 'Yanti A1');

// ======================= ringkasan =======================
$sumHead = OverdueService::summary($all);
check('ringkasan Superadmin: 3 anggota, 5 angsuran, total 120.000 + 220.000 + 510.000 + 300.000 + 300.000 = 1.450.000; menunggu validasi 510.000', $sumHead['members'] === 3 && $sumHead['installments'] === 5 && $sumHead['amount'] === 1450000 && $sumHead['pending'] === 510000 && $sumHead['max_days'] === $daysLate($monthAt(-2)));
check('konsistensi: total tunggakan = jumlah sisa di view v_overdue_installments (satu sumber)', $sumHead['amount'] === (int) one('SELECT SUM(remaining_amount) FROM v_overdue_installments') && $sumHead['installments'] === (int) one('SELECT COUNT(*) FROM v_overdue_installments'));
$ty = OverdueService::items($yanti);
$ts = OverdueService::items($siti);
check('Yanti hanya melihat tunggakan regunya: 2 anggota, 3 angsuran, 850.000 (120.000 + 220.000 + 510.000)', OverdueService::summary($ty) === ['members' => 2, 'installments' => 3, 'amount' => 850000, 'pending' => 510000, 'max_days' => $daysLate($monthAt(-2))] && array_unique(array_column($ty, 'team_name')) === ['Regu Yanti']);
check('Siti hanya melihat tunggakan regunya: 1 anggota, 2 angsuran, 600.000', OverdueService::summary($ts)['members'] === 1 && OverdueService::summary($ts)['installments'] === 2 && OverdueService::summary($ts)['amount'] === 600000 && array_unique(array_column($ts, 'team_name')) === ['Regu Siti']);
check('isolasi: Yanti tidak melihat satu pun anggota Siti (dan sebaliknya), walau dipaksa lewat saringan regu atau pencarian nama', !in_array('AGT-003', array_column($ty, 'member_no'), true) && !in_array('AGT-001', array_column($ts, 'member_no'), true)
    && !in_array('AGT-003', array_column(OverdueService::items($yanti, ['team' => $tB]), 'member_no'), true) && OverdueService::items($yanti, ['q' => 'Siti']) === [] && OverdueService::items($yanti, ['q' => $noB1]) === []);
check('isolasi: jumlah Yanti + Siti = jumlah Superadmin (tidak ada yang hilang atau ganda)', OverdueService::summary($ty)['amount'] + OverdueService::summary($ts)['amount'] === $sumHead['amount'] && count($ty) + count($ts) === count($all));
check('Superadmin bisa menyaring regu dan mencari (nama, nomor anggota, nomor pinjaman)', count(OverdueService::items($head, ['team' => $tB])) === 2 && count(OverdueService::items($head, ['q' => 'Yanti'])) === 2 && count(OverdueService::items($head, ['q' => $noA2])) === 1 && OverdueService::items($head, ['q' => '%']) === []);
check('Anggota hanya melihat tunggakannya sendiri (A2); tanpa pengguna tidak ada apa pun', array_column(OverdueService::items($angA2), 'member_no') === ['AGT-002'] && OverdueService::items(null) === []);
$panelY = OverdueService::panel($yanti, 2);
check('panel: 2 baris teratas dan sisanya diberi tahu; ringkasan tetap atas SEMUA tunggakan regu', count($panelY['items']) === 2 && $panelY['more'] === 1 && $panelY['summary']['installments'] === 3 && count(OverdueService::panel($head)['teams']) === 2);

// ======================= pembayaran divalidasi memperbarui tunggakan =======================
$pdo->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$pendingPay}");
$after = OverdueService::items($yanti);
check('setelah pembayaran A2 DISETUJUI: tunggakan A2 hilang, tersisa 2 angsuran A1 (340.000); pembayaran sebagian lain tidak berubah', OverdueService::summary($after) === ['members' => 1, 'installments' => 2, 'amount' => 340000, 'pending' => 0, 'max_days' => $daysLate($monthAt(-2))] && !in_array('AGT-002', array_column($after, 'member_no'), true));
$payRest = pay(1, $tA, $mo[0], $la1['inst'][2], 220000);   // A1 melunasi cicilan ke-3
pay(1, $tA, $mo[0], $la1['inst'][1], 120000);               // dan sisa cicilan ke-2
check('setelah A1 melunasi semuanya: anggota itu tidak lagi tampil menunggak; regu Yanti nol; regu Siti tidak terpengaruh', OverdueService::items($yanti) === [] && OverdueService::summary(OverdueService::items($yanti))['amount'] === 0 && OverdueService::summary(OverdueService::items($siti))['amount'] === 600000);

// ======================= ringkasan keuangan regu (TeamFinance) =======================
$fa = TeamFinance::summary($tA);
$fb = TeamFinance::summary($tB);
// Regu Yanti sekarang: tabungan 150.000; pinjaman A1 1.100.000 dibayar 220.000 + 100.000 + 220.000 + 120.000 = 660.000 -> sisa 440.000; A2 510.000 dibayar 510.000 -> 0.
check('keuangan regu Yanti: Saldo Total = tabungan anggota regu (100.000 + 50.000)', $fa['savings'] === 150000 && $fa['members'] === 2);
check('keuangan regu Yanti: Sisa Pinjaman = sisa tagihan pinjaman anggota regu (A1 1.100.000 - 660.000 = 440.000; A2 lunas)', $fa['outstanding'] === 440000);
check('keuangan regu Yanti: Total Bunga = bunga dibukukan pinjaman berlaku regu (100.000 + 10.000) dan bagian yang sudah terbayar pro rata', $fa['interest'] === 110000 && $fa['interest_received'] === (int) round(100000 * 660000 / 1100000) + 10000);
// arus kas regu: + tabungan 150.000 + angsuran (220.000 + 100.000 + 510.000 + 220.000 + 120.000 [yang DISETUJUI]) - pencairan (1.000.000 + 500.000)
$cashA = 150000 + (220000 + 100000 + 510000 + 120000 + (int) one("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE id = {$payRest} AND status = 'DISETUJUI'")) - 1500000;
check('keuangan regu Yanti: Sisa Kas = arus kas transaksi regu (setoran + angsuran - pencairan), bisa negatif', $fa['cash'] === $cashA && $fa['cash'] < 0);
check('keuangan regu Siti: tabungan 200.000, sisa pinjaman 520.000 (B1 520.000 belum dibayar; B2 lunas), bunga 22.000, arus kas 200.000 + 102.000 - 600.000 = -298.000', $fb['savings'] === 200000 && $fb['outstanding'] === 520000 && $fb['interest'] === 22000 && $fb['cash'] === 200000 + 102000 - 600000);
$g = Database::select('SELECT * FROM v_global_summary')[0];
check('konsistensi global: jumlah semua regu = angka global (tabungan, piutang, bunga dibukukan, kas); tanpa biaya, kas regu jumlahnya = kas koperasi', array_sum(array_column(TeamFinance::all(), 'savings')) === (int) $g['saldo_tabungan'] && array_sum(array_column(TeamFinance::all(), 'outstanding')) === (int) $g['piutang_beredar']
    && array_sum(array_column(TeamFinance::all(), 'interest')) === (int) $g['bunga_dibukukan'] && array_sum(array_column(TeamFinance::all(), 'cash')) === (int) $g['kas_tersedia'] && (int) $g['selisih'] === 0);
$members = Dashboard::teamMembers($tA);
check('konsistensi: dashboard (daftar anggota regu) dan TeamFinance menghasilkan angka yang sama untuk tabungan, sisa pinjaman, tunggakan', array_sum(array_column($members, 'savings')) === $fa['savings'] && array_sum(array_column($members, 'outstanding')) === $fa['outstanding'] && array_sum(array_column($members, 'overdue')) === OverdueService::summary(OverdueService::items($yanti))['amount']);
$teamRep = ReportService::build('regu', $head, ReportService::filters(['periode' => '1'], $head));
$repRows = array_column($teamRep['rows'], null, 'team');
check('konsistensi: Laporan Per Ketua Regu memakai angka yang sama dengan dashboard (tabungan, sisa pinjaman, bunga, arus kas, tunggakan)', $repRows['Regu Yanti']['savings'] === $fa['savings'] && $repRows['Regu Yanti']['outstanding'] === $fa['outstanding'] && $repRows['Regu Yanti']['interest'] === $fa['interest'] && $repRows['Regu Yanti']['cash'] === $fa['cash']
    && $repRows['Regu Siti']['savings'] === $fb['savings'] && $repRows['Regu Siti']['cash'] === $fb['cash'] && $repRows['Regu Siti']['overdue'] === OverdueService::summary(OverdueService::items($siti))['amount']);
check('keuangan regu: transaksi yang belum disetujui (menunggu validasi) tidak ikut: menambah pembayaran MENUNGGU tidak mengubah angka', (function () use ($tA, $fa, $pdo, $la1): bool {
    $before = TeamFinance::summary($tA);
    pay(1, $tA, 1, $la1['inst'][3], 100000, 'MENUNGGU_VALIDASI');
    return TeamFinance::summary($tA) === $before && $before === $fa;
})());

echo "\n" . ($failed === [] ? "Semua {$passed} pemeriksaan lulus.\n" : count($failed) . " GAGAL dari " . ($passed + count($failed)) . ".\n");
exit($failed === [] ? 0 : 1);
