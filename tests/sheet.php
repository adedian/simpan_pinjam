<?php
declare(strict_types=1);

/**
 * Tes formulir cetak per anggota (Rekap Pinjaman, Tabungan Hari Raya) dan mesin bagi hasil ProfitShare.
 * Skenario kecil dengan angka yang dihitung tangan (lihat komentar). MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\sheet.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\View;
use App\Services\MemberSheet;
use App\Services\Migrator;
use App\Services\ProfitShare;
use App\Services\ReportService;
use App\Services\SheetWorkbook;

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

/** Transaksi mentah (DRAFT); rincian ditambahkan pemanggil, lalu approve(). */
function trx(string $type, int $member, int $team, int $amount, int $monthId, string $date): int
{
    static $n = 0;
    $n++;
    $pdo = Database::pdo();
    $pdo->prepare('INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(["T-SHEET-{$n}", "SHEET-{$n}", $type, $member, $team, $monthId, $date, $amount]);
    return (int) $pdo->lastInsertId();
}
function setStatus(int $id, string $status): void
{
    $pdo = Database::pdo();
    $pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI' WHERE id = {$id}");
    if ($status === 'DISETUJUI') {
        $pdo->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$id}");
    }
}
function saving(int $member, int $team, int $amount, int $monthId, string $date, string $status = 'DISETUJUI'): int
{
    $id = trx('SIMPANAN', $member, $team, $amount, $monthId, $date);
    Database::pdo()->exec("INSERT INTO savings (transaction_id, kind) VALUES ({$id}, 'WAJIB')");
    setStatus($id, $status);
    return $id;
}
/** @param array<int,int> $dueIds @param array<int,int> $amounts @return array<int,int> id cicilan */
function loan(int $member, int $team, int $principal, int $tenor, int $interest, int $monthId, string $date, array $dueIds, array $amounts): array
{
    $pdo = Database::pdo();
    $id = trx('PENCAIRAN_PINJAMAN', $member, $team, $principal, $monthId, $date);
    $pdo->prepare('INSERT INTO loans (transaction_id, member_id, principal, tenor_months, rate_pct_month, total_interest, disbursed_on) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, $member, $principal, $tenor, '2.00', $interest, $date]);
    $loanId = (int) $pdo->lastInsertId();
    $inst = [];
    foreach ($amounts as $i => $a) {
        $pdo->prepare('INSERT INTO loan_installments (loan_id, seq, due_month_id, amount_due) VALUES (?,?,?,?)')->execute([$loanId, $i + 1, $dueIds[$i], $a]);
        $inst[] = (int) $pdo->lastInsertId();
    }
    setStatus($id, 'DISETUJUI');
    return $inst;
}
function payment(int $member, int $team, int $monthId, string $date, int $installmentId, int $amount): void
{
    $id = trx('ANGSURAN', $member, $team, $amount, $monthId, $date);
    Database::pdo()->prepare('INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES (?,?,?)')->execute([$id, $installmentId, $amount]);
    setStatus($id, 'DISETUJUI');
}

// ---------------- fixture ----------------
// Periode aktif Mar-Jun 2026 (4 bulan). Pertemuan Mar dan Apr berisi tanggal; Mei dan Jun kosong (kembali ke tanggal 1).
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji', '2026-03-01', '2026-06-30', 'AKTIF')");
$pdo->exec("INSERT INTO period_months (period_id, month_date, meeting_date) VALUES (1,'2026-03-01','2026-03-05'),(1,'2026-04-01','2026-04-04'),(1,'2026-05-01',NULL),(1,'2026-06-01',NULL)");
$mo = [];
foreach (Database::select('SELECT id FROM period_months ORDER BY month_date') as $i => $r) {
    $mo[$i + 1] = (int) $r['id'];
}
$members = [1 => ['AGT-001', 'Bu Alfa', 'E - 08A', 0], 2 => ['AGT-002', 'Bu Beta', 'Non JH', 0], 3 => ['AGT-003', 'Bu <b>Gama</b>', 'L - 08', 1], 4 => ['AGT-004', 'Bu Delta', null, 0]];
foreach ($members as $n => [$no, $name, $addr, $exempt]) {
    $pdo->prepare('INSERT INTO members (member_no, name, address_block, active_from, status, reserve_exempt) VALUES (?,?,?,?,?,?)')->execute([$no, $name, $addr, '2026-03-01', 'AKTIF', $exempt]);
}
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Satu', 1), ('Regu Dua', 4)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'2026-03-01'),(2,1,'2026-03-01'),(3,1,'2026-03-01'),(4,2,'2026-03-01')");

// Tabungan: A 100.000 (Mar) dan 100.000 (Apr); B 300.000 (Mar); C 200.000 (Apr). D: hanya transaksi MENUNGGU (tidak boleh ikut).
saving(1, 1, 100000, $mo[1], '2026-03-05');
saving(1, 1, 100000, $mo[2], '2026-04-04');
saving(2, 1, 300000, $mo[1], '2026-03-05');
$savingC = saving(3, 1, 200000, $mo[2], '2026-04-04');
saving(4, 2, 999999, $mo[2], '2026-04-04', 'MENUNGGU_VALIDASI');
// A meminjam 1.000.000, tenor 2, bunga 40.000; cicilan 520.000 jatuh tempo Apr dan Mei. A membayar cicilan 1 pada April.
$inst = loan(1, 1, 1000000, 2, 40000, $mo[1], '2026-03-05', [$mo[2], $mo[3]], [520000, 520000]);
payment(1, 1, $mo[2], '2026-04-04', $inst[0], 520000);

/*
 * Hitungan tangan. Data terakhir = April (indeks 2). Cicilan 2 (jatuh tempo Mei) diproyeksikan dibayar Mei.
 *   bunga per pembayaran = 520.000 x 40.000 / 1.040.000 = 20.000 -> bunga diterima: Apr 20.000, Mei 20.000
 *   pool = 40% x bunga bulan berikutnya: akhir Mar = 8.000 (penabung) + 8.000 (peminjam); akhir Apr = 8.000 + 8.000
 *   saldo akhir Mar: A 100.000, B 300.000 (jumlah 400.000)    akhir Apr: A 200.000, B 300.000, C 200.000 (jumlah 700.000)
 *   penabung kotor: A = 2.000 + 2.285,714 = 4.285,714   B = 6.000 + 3.428,571 = 9.428,571   C = 2.285,714
 *   sisa pokok A: akhir Mar 1.000.000; akhir Apr 1.000.000 - 520.000 x 1.000.000/1.040.000 = 500.000 -> A menerima 100% pool peminjam = 16.000
 *   cadangan 5% (C dikecualikan): A 4.071  B 8.957  C 2.286 | peminjam A 15.200
 */
$period = ReportService::filters([], null)['period'];
$ps = ProfitShare::compute($period);
check('bagi hasil: periode dan data terakhir', $ps['period_id'] === $period && $ps['as_of'] === '2026-04-01' && $ps['months'] === 4);
check('bagi hasil: pool = 80% x bunga yang diterima (Apr dan Mei)', abs($ps['interest_pool'] - 32000.0) < 0.001);
check('bagi hasil: tidak ada pool yang tertinggal', abs($ps['undistributed']) < 0.001);
check('bagi hasil penabung A = 4.071 (setelah cadangan 5%)', $ps['members'][1]['saver'] === 4071);
check('bagi hasil penabung B = 8.957', $ps['members'][2]['saver'] === 8957);
check('bagi hasil penabung C = 2.286 (dikecualikan dari cadangan)', $ps['members'][3]['saver'] === 2286 && $ps['members'][3]['reserve_exempt'] === true);
check('bagi hasil peminjam A = 15.200; lainnya tidak punya pinjaman', $ps['members'][1]['borrower'] === 15200 && $ps['members'][2]['borrower'] === 0 && $ps['members'][3]['borrower'] === 0);
check('bagi hasil: transaksi MENUNGGU (D) tidak ikut; D tidak punya bagian', !isset($ps['members'][4]) || ($ps['members'][4]['saver'] === 0 && $ps['members'][4]['borrower'] === 0));
check('bagi hasil: jumlah kotor = pool (tak ada rupiah hilang atau dikarang)', abs(array_sum(array_map(static fn (array $m): float => $m['saver_gross'] + $m['borrower_gross'], $ps['members'])) - 32000.0) < 0.001);

$head = ['roles' => ['HEAD'], 'member_id' => null, 'team_id' => null];
$sheetA = MemberSheet::build($head, 1);
check('formulir: anggota ada, identitas benar', $sheetA !== null && $sheetA['member']['no'] === 1 && $sheetA['member']['name'] === 'Bu Alfa' && $sheetA['member']['address'] === 'E - 08A');
$sv = $sheetA['saving'];
check('tabungan: masuk per bulan dan total berjalan', array_column($sv['rows'], 'in') === [100000, 100000, 0, 0] && array_column($sv['rows'], 'total') === [100000, 200000, 200000, 200000]);
check('tabungan: tanggal = tanggal pertemuan, kosong kembali ke tanggal 1 bulan itu', array_column($sv['rows'], 'date') === ['2026-03-05', '2026-04-04', '2026-05-01', '2026-06-01']);
check('tabungan: ringkasan (total, total pinjaman, bagi hasil, diterima)', $sv['total'] === 200000 && $sv['loan_total'] === 1000000 && $sv['saver_share'] === 4071 && $sv['borrower_share'] === 15200 && $sv['received'] === 219271);
$ln = $sheetA['loan'];
check('pinjaman: pokok, bunga, bayar per bulan', array_column($ln['rows'], 'pokok') === [1000000, 0, 0, 0] && array_column($ln['rows'], 'bunga') === [40000, 0, 0, 0] && array_column($ln['rows'], 'bayar') === [0, 520000, 0, 0]);
check('pinjaman: sisa berjalan = pokok + bunga - bayar, dan sisa akhir', array_column($ln['rows'], 'sisa') === [1040000, 520000, 520000, 520000] && $ln['remaining'] === 520000);
check('pinjaman: keterangan tenor dan nomor cicilan', $ln['rows'][0]['note'] === 'Tenor 2 bulan' && $ln['rows'][1]['note'] === 'Cicilan ke-1' && $ln['rows'][2]['note'] === '');

$sheetB = MemberSheet::build($head, 2);
check('anggota tanpa pinjaman: sisa nol, total pinjaman nol, bagi hasil pinjaman nol', $sheetB['loan']['remaining'] === 0 && $sheetB['saving']['loan_total'] === 0 && $sheetB['saving']['borrower_share'] === 0 && $sheetB['saving']['received'] === 300000 + 8957);
check('anggota tanpa alamat: kosong, bukan galat', MemberSheet::build($head, 4)['member']['address'] === '');
check('anggota tidak ada = null', MemberSheet::build($head, 999) === null);

// Cakupan: ketua Regu Dua hanya anggota regunya; diri sendiri hanya dirinya; tanpa izin = null.
$ketuaDua = ['roles' => ['KETUA_REGU'], 'member_id' => 4, 'team_id' => 2];
check('cakupan: ketua regu lain tidak bisa membuka anggota regu Satu (null)', MemberSheet::build($ketuaDua, 1) === null && MemberSheet::build($ketuaDua, 4) !== null);
$ketuaSatu = ['roles' => ['KETUA_REGU'], 'member_id' => 1, 'team_id' => 1];
$idsOf = static fn (array $sheets): array => array_map('intval', array_column(array_column($sheets, 'member'), 'id'));
$own = $idsOf(MemberSheet::buildMany($ketuaSatu, ReportService::filters([], $ketuaSatu)));
sort($own);
check('cakupan: cetak massal ketua regu hanya anggota regunya', $own === [1, 2, 3]);
check('cakupan: pengguna tanpa izin laporan tidak mendapat apa pun', MemberSheet::build(['roles' => [], 'member_id' => null, 'team_id' => null], 1) === null && MemberSheet::buildMany(null, ReportService::filters([], null)) === []);
check('cakupan: bagi hasil tetap dihitung dari SELURUH koperasi walau ketua regu hanya melihat regunya', MemberSheet::build($ketuaSatu, 1)['saving']['saver_share'] === 4071);
$many = MemberSheet::buildMany($head, ReportService::filters([], $head));
$allIds = $idsOf($many);
$sorted = $allIds;
sort($sorted);
check('cetak massal: semua anggota, terurut per nama regu (Regu Dua sebelum Regu Satu)', $sorted === [1, 2, 3, 4] && $allIds[0] === 4);
$f = ReportService::filters(['q' => 'Beta'], $head);
check('cetak massal: saringan nama', count(MemberSheet::buildMany($head, $f)) === 1);

// Tampilan: judul, angka berpemisah titik, "-" untuk nol, nama diloloskan.
$htmlP = View::include('partials/sheet-pinjaman', ['sheet' => $sheetA]);
$htmlT = View::include('partials/sheet-tabungan', ['sheet' => $sheetA]);
check('tampilan pinjaman: judul, kolom, dan baris SISA PINJAMAN', str_contains($htmlP, 'REKAP PINJAMAN') && str_contains($htmlP, 'BAYAR ANGSURAN') && str_contains($htmlP, 'SISA PINJAMAN') && str_contains($htmlP, '1.040.000') && str_contains($htmlP, '520.000'));
check('tampilan pinjaman: tanggal gaya Excel dan nol tampil "-"', str_contains($htmlP, '5-Mar-26') && str_contains($htmlP, '1-May-26') && str_contains($htmlP, '>-<'));
check('tampilan tabungan: judul, ringkasan, dan total di terima', str_contains($htmlT, 'Tabungan Hari Raya') && str_contains($htmlT, 'Total Tabungan') && str_contains($htmlT, 'Bagi Hasil Tabungan') && str_contains($htmlT, 'Total Pinjaman') && str_contains($htmlT, 'Bagi Hasil Pinjaman') && str_contains($htmlT, 'Total di Terima') && str_contains($htmlT, '219.271'));
check('tampilan tabungan: aktif per bulan mulai', str_contains($htmlT, 'Maret 2026'));
$htmlG = View::include('partials/sheet-tabungan', ['sheet' => MemberSheet::build($head, 3)]);
check('tampilan: nama dengan HTML diloloskan (tidak ada tag mentah)', !str_contains($htmlG, '<b>Gama') && str_contains($htmlG, '&lt;b&gt;Gama'));
check('tampilan: bagi hasil diberi keterangan perkiraan mengikuti Excel, belum dikurangi sisa pinjaman', str_contains($htmlT, 'perkiraan mengikuti perhitungan Excel') && str_contains($htmlT, 'Belum dikurangi sisa pinjaman'));
check('helper: sheet_date dan sheet_num', sheet_date('2026-03-07') === '7-Mar-26' && sheet_date(null) === '-' && sheet_num(0) === '-' && sheet_num(1234567) === '1.234.567');

// Unduhan Excel: tata letak mengikuti formulir (lihat SheetWorkbook)
require_once __DIR__ . '/xlsx_reader.php';
$wb  = xlsx_read(SheetWorkbook::build([$sheetA, $sheetB]));
$lp  = $wb['sheets']['Rekap Pinjaman'] ?? [];
$tb  = $wb['sheets']['Tabungan Hari Raya'] ?? [];
check('excel formulir: berkas sah dengan dua lembar, bernama seperti formulir', $wb['ok'] && array_keys($wb['sheets']) === ['Rekap Pinjaman', 'Tabungan Hari Raya']);
check('excel pinjaman: judul, identitas (NO. URUT / NAMA / ALAMAT), dan kepala tabel biru', xlsx_v($lp, 'A1') === 'REKAP PINJAMAN "ADEM AYEM"' && xlsx_v($lp, 'A2') === 'NO. URUT' && xlsx_v($lp, 'D2') === 1.0 && xlsx_v($lp, 'D3') === 'Bu Alfa' && xlsx_v($lp, 'D4') === 'E - 08A'
    && xlsx_v($lp, 'A5') === 'NO' && xlsx_v($lp, 'C5') === "PINJAMAN
POKOK" && xlsx_v($lp, 'E5') === "BAYAR ANGSURAN
+ BUNGA" && xlsx_v($lp, 'G5') === 'Keterangan' && in_array('A1:G1', $lp['merges'], true));
check('excel pinjaman: baris bulan (tanggal asli Excel, pokok, bunga, bayar, sisa, keterangan) dan SISA PINJAMAN', xlsx_v($lp, 'B6') === 46086.0 && xlsx_v($lp, 'C6') === 1000000.0 && xlsx_v($lp, 'D6') === 40000.0 && xlsx_v($lp, 'F6') === 1040000.0 && xlsx_v($lp, 'G6') === 'Tenor 2 bulan'
    && xlsx_v($lp, 'E7') === 520000.0 && xlsx_v($lp, 'F7') === 520000.0 && xlsx_v($lp, 'G7') === 'Cicilan ke-1' && xlsx_v($lp, 'A10') === 'SISA PINJAMAN' && xlsx_v($lp, 'F10') === 520000.0);
check('excel pinjaman: formulir kedua (anggota B) menyusul di bawah dengan satu baris kosong', xlsx_v($lp, 'A12') === 'REKAP PINJAMAN "ADEM AYEM"' && xlsx_v($lp, 'D13') === 2.0 && xlsx_v($lp, 'D14') === 'Bu Beta' && xlsx_v($lp, 'D15') === 'Non JH' && xlsx_v($lp, 'A11') === null);
check('excel tabungan: judul, identitas, "Aktif Per", kepala tabel', xlsx_v($tb, 'A1') === 'Tabungan Hari Raya  "Adem Ayem"' && xlsx_v($tb, 'A2') === 'NO. URUT' && xlsx_v($tb, 'C2') === 1.0 && xlsx_v($tb, 'C3') === 'Bu Alfa' && xlsx_v($tb, 'A4') === 'DAWIS / BLOK' && xlsx_v($tb, 'C4') === 'E - 08A'
    && xlsx_v($tb, 'D5') === 'Aktif Per' && xlsx_v($tb, 'E5') === 'Maret 2026' && xlsx_v($tb, 'E6') === "TOTAL
TABUNGAN");
check('excel tabungan: masuk, keluar kosong, total berjalan, dan ringkasan (total, bagi hasil, total pinjaman, total di terima)', xlsx_v($tb, 'C7') === 100000.0 && xlsx_v($tb, 'E7') === 100000.0 && xlsx_v($tb, 'E8') === 200000.0 && ($tb['cells']['D7']['type'] ?? '') === 'empty'
    && xlsx_v($tb, 'A11') === 'Total Tabungan' && xlsx_v($tb, 'E11') === 200000.0 && xlsx_v($tb, 'A12') === 'Bagi Hasil Tabungan' && xlsx_v($tb, 'E12') === 4071.0 && xlsx_v($tb, 'A13') === 'Total Pinjaman' && xlsx_v($tb, 'D13') === 1000000.0
    && xlsx_v($tb, 'E14') === 15200.0 && xlsx_v($tb, 'A15') === 'Total di Terima' && xlsx_v($tb, 'E15') === 219271.0 && str_contains((string) xlsx_v($tb, 'A16'), 'perkiraan'));
check('excel formulir: nama dengan HTML/rumus tersimpan sebagai teks', (function () use ($head): bool {
    $g = MemberSheet::build($head, 3);
    $g['member']['name'] = '=HYPERLINK("x")';
    $x = xlsx_read(SheetWorkbook::build([$g]));
    $c = $x['sheets']['Rekap Pinjaman']['cells']['D3'];
    return $c['type'] === 'str' && $c['formula'] === false && $c['v'] === '=HYPERLINK("x")';
})());
$wb3 = xlsx_read(SheetWorkbook::build([$sheetA, $sheetB, $sheetA]));
check('excel formulir: cetak banyak anggota memberi pemisah halaman tiap dua formulir (dua lembar)', $wb3['sheets']['Rekap Pinjaman']['breaks'] === [21] && $wb3['sheets']['Tabungan Hari Raya']['breaks'] === [33] && $wb['sheets']['Rekap Pinjaman']['breaks'] === []);

// Koreksi: simpanan C dibalik (transaksi pembalik DISETUJUI di bulan asal) -> netral di formulir dan di bagi hasil.
$rev = trx('SIMPANAN', 3, 1, 200000, $mo[2], '2026-04-04');
$pdo->exec("UPDATE transactions SET reverses_id = {$savingC} WHERE id = {$rev}");
setStatus($rev, 'DISETUJUI');
$sheetC = MemberSheet::build($head, 3);
$ps2 = ProfitShare::compute($period);
check('koreksi: simpanan yang dibalik tidak muncul di formulir (masuk 0, total 0)', $sheetC['saving']['rows'][1]['in'] === 0 && $sheetC['saving']['total'] === 0);
check('koreksi: bagi hasil C jadi 0 dan seluruh pool tetap terbagi (A dan B menerima lebih)', $ps2['members'][3]['saver'] === 0 && abs($ps2['undistributed']) < 0.001 && $ps2['members'][2]['saver'] > 8957);

// Keterangan: dua pinjaman satu anggota, pembayaran sebagian, dan teks panjang tidak meluber di Excel.
// D (id 4): L1 pokok 1.000.000 tenor 2 (cicilan 520.000 x 2, jatuh tempo bln 2 dan 3); L2 pokok 500.000 tenor 1 (cicilan 510.000, bln 2).
// Bln 2: bayar L1 #1 penuh dan L2 #1 hanya 80.000 (sebagian). Bln 3: L2 #1 dilunasi 430.000.
$d1 = loan(4, 2, 1000000, 2, 40000, $mo[1], '2026-03-05', [$mo[2], $mo[3]], [520000, 520000]);
$d2 = loan(4, 2, 500000, 1, 10000, $mo[1], '2026-03-05', [$mo[2]], [510000]);
payment(4, 2, $mo[2], '2026-04-04', $d1[0], 520000);
payment(4, 2, $mo[2], '2026-04-04', $d2[0], 80000);
payment(4, 2, $mo[3], '2026-05-01', $d2[0], 430000);
$notesD = array_column(MemberSheet::build($head, 4)['loan']['rows'], 'note');
check('keterangan: tenor dua pinjaman satu bulan digabung ("Tenor 2 & 1 bulan")', $notesD[0] === 'Tenor 2 & 1 bulan');
check('keterangan: cicilan dua pinjaman dibedakan per pinjaman (pokok) dan yang baru terbayar sebagian ditandai', $notesD[1] === 'Pinj. 1.000.000: cicilan ke-1; Pinj. 500.000: cicilan ke-1 (sebagian)');
check('keterangan: pelunasan sisa cicilan yang tadinya sebagian tidak lagi ditandai sebagian', $notesD[2] === 'Cicilan ke-1' && $notesD[3] === '');
$wbD = xlsx_read(SheetWorkbook::build([MemberSheet::build($head, 4)]));
$lpD = $wbD['sheets']['Rekap Pinjaman'];
check('excel: kolom Keterangan lebar (38) dan teks panjang diberi tinggi baris agar membungkus, bukan meluber; teks pendek memakai tinggi bawaan', ($lpD['cols'][7] ?? 0) >= 38.0 && ($lpD['heights'][7] ?? 0) >= 30.0 && !isset($lpD['heights'][6]) && xlsx_v($lpD, 'G7') === $notesD[1]);

echo "\n";
if ($failed === []) {
    echo "Formulir cetak dan bagi hasil: {$passed} lulus, 0 gagal.\n";
    exit(0);
}
echo "Formulir cetak dan bagi hasil: {$passed} lulus, " . count($failed) . " gagal.\n";
exit(1);
