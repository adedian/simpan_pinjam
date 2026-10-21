<?php
declare(strict_types=1);

/**
 * Tes Pencairan Akhir (PayoutService): hitungan baris, penyusunan dari snapshot, potongan manual, hanya-tambah, pembatalan,
 * penerimaan uang, pecahan, dan struktur laporan. Fixture sama dengan tests/bagihasil.php (+ Z menabung 250.500).
 * MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\payout.php
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
use App\Services\PayoutService;
use App\Services\ProfitReport;
use App\Services\ReportWorkbook;
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
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.3', 'HTTP_USER_AGENT' => 'tes-pencairan']);
}
/** @return array<string,string>|null */
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
        ->execute(["T-PY-{$n}", "PY-{$n}", $type, $member, null, $monthId, $date, $amount]);
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

// ======================= hitungan baris (murni) =======================
$c = PayoutService::calc(250500, 12345, 0, 0, 0, 0, 1000);
check('calc: 250.500 + 12.345 = 262.845 dibulatkan KE BAWAH ke 262.000; sisa pembulatan 845', $c === ['subtotal' => 262845, 'net' => 262000, 'rounding' => 845, 'shortfall' => 0]);
$c = PayoutService::calc(250500, 12345, 0, 0, 0, 0, 100);
check('calc: pembulatan 100 => 262.800, sisa 45; pembulatan 1 => persis 262.845', $c['net'] === 262800 && $c['rounding'] === 45 && PayoutService::calc(250500, 12345, 0, 0, 0, 0, 1)['net'] === 262845);
$c = PayoutService::calc(300000, 20000, 10000, 100000, 25000, 5000, 1000);
check('calc: potongan pinjaman, arisan, dan lain mengurangi: 330.000 - 130.000 = 200.000', $c['subtotal'] === 200000 && $c['net'] === 200000 && $c['rounding'] === 0 && $c['shortfall'] === 0);
$c = PayoutService::calc(100000, 9500, 2533, 1020000, 0, 0, 1000);
check('calc: potongan melebihi hak => diterima 0, kekurangan dicatat (bukan negatif)', $c['net'] === 0 && $c['subtotal'] === -907967 && $c['shortfall'] === 907967 && $c['rounding'] === 0);
$c = PayoutService::calc(50000, 0, 0, 50000, 0, 0, 1000);
check('calc: tepat nol => diterima 0 dan tanpa kekurangan', $c['net'] === 0 && $c['shortfall'] === 0);
check('calc: pecahan di bawah satu unit => diterima 0 dan seluruhnya jadi sisa pembulatan', PayoutService::calc(999, 0, 0, 0, 0, 0, 1000) === ['subtotal' => 999, 'net' => 0, 'rounding' => 999, 'shortfall' => 0]);

// ======================= fixture =======================
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Panjang', '2027-01-01', '2027-12-31', 'AKTIF')");
for ($m = 1; $m <= 12; $m++) {
    $pdo->exec(sprintf("INSERT INTO period_months (period_id, month_date) VALUES (1, '2027-%02d-01')", $m));
}
$mo = [];
foreach (Database::select('SELECT id FROM period_months ORDER BY month_date') as $i => $r) {
    $mo[$i + 1] = (int) $r['id'];
}
$pdo->exec("INSERT INTO members (member_no, name, active_from, status) VALUES ('AGT-001', 'Anggota X', '2027-01-01', 'AKTIF'), ('AGT-002', 'Anggota Y', '2027-01-01', 'AKTIF'), ('AGT-003', 'Anggota Z', '2027-01-01', 'AKTIF'), ('AGT-004', 'Anggota W', '2027-01-01', 'AKTIF')");
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu X', 1), ('Regu Y', 2)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1, 1, '2027-01-01'), (2, 2, '2027-01-01'), (3, 1, '2027-01-01'), (4, 2, '2027-01-01')");
foreach ([[1, 100000], [2, 300000], [3, 250500]] as [$mem, $amt]) {
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
$head = User::findActive($ids['kepala']);
$period = (int) one('SELECT id FROM periods LIMIT 1');

// ======================= sebelum ada snapshot =======================
check('dasar: tanpa snapshot, tidak ada dasar penyusunan dan pencairan ditolak', PayoutService::baseSnapshot($period) === null && PayoutService::preview($period, 1) === null
    && isset(violation(fn () => PayoutService::create(req(), $head, $period, 1, 1000, [], null))['_form']) && (int) one('SELECT COUNT(*) FROM final_payouts') === 0);

$snapId = ProfitReport::createSnapshot(req(), $head, $period, 'Dasar pencairan');
$sl = [];
foreach (Database::select('SELECT * FROM profit_share_lines WHERE snapshot_id = ?', [$snapId]) as $l) {
    $sl[(int) $l['member_id']] = $l;
}
check('dasar: snapshot terbaru yang berlaku menjadi dasar; baris ada untuk X, Y, Z (W tanpa saldo atau pinjaman tidak ada)', PayoutService::baseSnapshot($period)['id'] === $snapId && array_keys($sl) === [1, 2, 3]);

// ======================= pratinjau =======================
$pv = PayoutService::preview($period, $snapId);
$byNo = array_column($pv['rows'], null, 'no');
check('pratinjau: hanya anggota yang punya tabungan, bagi hasil, atau pinjaman (3), urut nomor; W tidak ikut', array_column($pv['rows'], 'no') === ['AGT-001', 'AGT-002', 'AGT-003']);
check('pratinjau: angka X dari snapshot (tabungan 100.000, bagi hasil penabung+peminjam, sisa pinjaman 1.020.000 pokok+bunga) dan Y (300.000, 1.100.000)',
    $byNo['AGT-001']['savings'] === 100000 && $byNo['AGT-001']['loan'] === 1020000 && $byNo['AGT-001']['saver'] + $byNo['AGT-001']['borrower'] > 0
    && $byNo['AGT-002']['savings'] === 300000 && $byNo['AGT-002']['loan'] === 1100000 && $byNo['AGT-003']['savings'] === 250500 && $byNo['AGT-003']['loan'] === 0);
check('pratinjau: regu tampil dan data belum bergerak sejak snapshot (drift 0)', $byNo['AGT-001']['team'] === 'Regu X' && $byNo['AGT-002']['team'] === 'Regu Y' && $pv['drift'] === 0 && $pv['as_of'] === '2027-07-01');
check('pratinjau: snapshot tak dikenal atau dari periode lain = null', PayoutService::preview($period, 99999) === null);

// ======================= penyusunan: validasi =======================
check('susun: pembulatan di luar pilihan ditolak', isset(violation(fn () => PayoutService::create(req(), $head, $period, $snapId, 777, [], null))['unit']) && (int) one('SELECT COUNT(*) FROM final_payouts') === 0);
$e = violation(fn () => PayoutService::create(req(), $head, $period, $snapId, 1000, [3 => ['arisan' => '12,5', 'other' => 'abc', 'note' => 'x']], null));
check('susun: potongan harus angka rupiah bulat (desimal dan huruf ditolak per anggota), tidak ada yang tersimpan', isset($e['d_3_arisan']) && isset($e['d_3_other']) && (int) one('SELECT COUNT(*) FROM final_payouts') === 0);
$e = violation(fn () => PayoutService::create(req(), $head, $period, $snapId, 1000, [3 => ['arisan' => '10.000']], null));
check('susun: ada potongan tanpa keterangan ditolak', isset($e['d_3_note']) && (int) one('SELECT COUNT(*) FROM final_payouts') === 0);
$e = violation(fn () => PayoutService::create(req(), $head, $period, $snapId, 1000, [3 => ['arisan' => "-5", 'note' => 'n']], null));
check('susun: potongan negatif ditolak', isset($e['d_3_arisan']));

// ======================= penyusunan: berhasil =======================
$id = PayoutService::create(req(), $head, $period, $snapId, 1000, [3 => ['arisan' => '10.000', 'other' => '5000', 'note' => '<b>Arisan</b> Maret']], '  Pembagian <i>THR</i>  ');
$p = PayoutService::find($id);
$lines = array_column($p['lines'], null, 'member_no');
check('susun: tersimpan, 3 baris urut nomor anggota, tercatat di audit dengan rincian', $id > 0 && count($p['lines']) === 3 && array_column($p['lines'], 'member_no') === ['AGT-001', 'AGT-002', 'AGT-003'] && (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'PAYOUT_CREATED' AND entity_id = ?", [$id]) === 1);
$x = $lines['AGT-001'];
$expX = PayoutService::calc(100000, (int) $sl[1]['saver_net'], (int) $sl[1]['borrower_net'], 1020000, 0, 0, 1000);
check('baris X: pinjaman 1.020.000 melebihi hak => diterima 0, kekurangan = selisih, bukan negatif', (int) $x['net_paid'] === 0 && (int) $x['shortfall'] === $expX['shortfall'] && (int) $x['shortfall'] > 0 && (int) $x['loan_outstanding'] === 1020000 && (int) $x['subtotal'] === $expX['subtotal']);
$z = $lines['AGT-003'];
$expZ = PayoutService::calc(250500, (int) $sl[3]['saver_net'], (int) $sl[3]['borrower_net'], 0, 10000, 5000, 1000);
check('baris Z: tabungan + bagi hasil - arisan 10.000 - lain 5.000 dibulatkan ke bawah (sesuai hitungan murni)', (int) $z['net_paid'] === $expZ['net'] && (int) $z['rounding'] === $expZ['rounding'] && (int) $z['subtotal'] === $expZ['subtotal'] && (int) $z['arisan'] === 10000 && (int) $z['other'] === 5000 && (int) $z['net_paid'] % 1000 === 0 && (int) $z['net_paid'] > 0);
check('baris Z: nilai sumber sama dengan snapshot (tabungan 250.500, bagi hasil penabung dan peminjam)', (int) $z['savings'] === 250500 && (int) $z['saver_share'] === (int) $sl[3]['saver_net'] && (int) $z['borrower_share'] === (int) $sl[3]['borrower_net']);
check('keterangan: tersimpan apa adanya sebagai teks (HTML tidak dieksekusi saat tampil), catatan dirapikan', str_contains((string) $z['deduction_note'], 'Arisan') && $p['note'] === 'Pembagian <i>THR</i>');
$sumNet = 0; $sumRound = 0; $sumShort = 0; $sumLoan = 0;
foreach ($p['lines'] as $l) {
    $sumNet += (int) $l['net_paid']; $sumRound += (int) $l['rounding']; $sumShort += (int) $l['shortfall']; $sumLoan += (int) $l['loan_outstanding'];
    check('kontrol baris ' . $l['member_no'] . ': tabungan + bagi hasil - potongan = diterima + pembulatan - kekurangan',
        (int) $l['savings'] + (int) $l['saver_share'] + (int) $l['borrower_share'] - (int) $l['loan_outstanding'] - (int) $l['arisan'] - (int) $l['other'] === (int) $l['net_paid'] + (int) $l['rounding'] - (int) $l['shortfall']);
}
check('jumlah di kepala = jumlah baris (dibayarkan, pembulatan, kekurangan, pinjaman, tabungan)', (int) $p['net_total'] === $sumNet && (int) $p['rounding_total'] === $sumRound && (int) $p['shortfall_total'] === $sumShort && (int) $p['loan_total'] === $sumLoan
    && (int) $p['savings_total'] === 650500 && (int) $p['arisan_total'] === 10000 && (int) $p['other_total'] === 5000 && (int) $p['members_count'] === 3);
check('susun: satu pencairan berlaku per periode: penyusunan kedua ditolak, tetap 1', str_contains(violation(fn () => PayoutService::create(req(), $head, $period, $snapId, 1000, [], null))['_form'] ?? '', 'sudah punya pencairan') && (int) one('SELECT COUNT(*) FROM final_payouts') === 1);

// ======================= hanya-tambah =======================
check('hanya-tambah: UPDATE dan DELETE pada pencairan dan barisnya ditolak (trigger)', (function () use ($pdo): bool {
    $blocked = 0;
    foreach (["UPDATE final_payouts SET note = 'x'", 'DELETE FROM final_payouts', 'UPDATE final_payout_lines SET net_paid = 1', 'DELETE FROM final_payout_lines'] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            $blocked++;
        }
    }
    return $blocked === 4;
})());

// ======================= perubahan data sesudah snapshot =======================
$s2 = trx('SIMPANAN', 3, 100000, $mo[9], '2027-09-05');
$pdo->exec("INSERT INTO savings (transaction_id, kind) VALUES ({$s2}, 'SUKARELA')");
approve($s2);
$pAfter = PayoutService::find($id);
check('histori: simpanan baru sesudah disusun TIDAK mengubah pencairan (tabungan Z tetap 250.500, jumlah sama)', (int) array_column($pAfter['lines'], null, 'member_no')['AGT-003']['savings'] === 250500 && (int) $pAfter['net_total'] === (int) $p['net_total']);

// ======================= pecahan =======================
$den = PayoutService::denominations($p['lines']);
$val = 0;
foreach ($den['counts'] as $d => $n) {
    $val += $d * $n;
}
check('pecahan: nilai semua pecahan + sisa = jumlah dibayarkan; kelipatan 1.000 => tanpa sisa; hanya Z yang dibayar', $val + $den['leftover'] === (int) $p['net_total'] && $den['leftover'] === 0 && $den['total'] === (int) $p['net_total']);
$den2 = PayoutService::denominations([['net_paid' => 387000], ['net_paid' => 1800]]);
check('pecahan: contoh tangan 387.000 = 3x100.000 + 1x50.000 + 1x20.000 + 1x10.000 + 1x5.000 + 1x2.000; 1.800 = 1x1.000 + 1x500 + 1x200 + 1x100', $den2['counts'][100000] === 3 && $den2['counts'][50000] === 1 && $den2['counts'][20000] === 1 && $den2['counts'][10000] === 1 && $den2['counts'][5000] === 1 && $den2['counts'][2000] === 1
    && $den2['counts'][1000] === 1 && $den2['counts'][500] === 1 && $den2['counts'][200] === 1 && $den2['counts'][100] === 1 && $den2['leftover'] === 0);
check('pecahan: sisa di bawah Rp 100 dilaporkan sebagai sisa (bukan hilang)', PayoutService::denominations([['net_paid' => 1250]])['leftover'] === 50);

// ======================= laporan (HTML dan Excel) =======================
$rep = PayoutService::report($p);
check('laporan: struktur sama dengan tabel lain (kolom, baris, jumlah) dan jumlah kolom Diterima = jumlah dibayarkan', $rep['key'] === 'pencairan' && count($rep['rows']) === 3 && $rep['totals']['net'] === (int) $p['net_total'] && $rep['totals']['savings'] === 650500 && str_contains($rep['subtitle'], 'Juli 2027') && str_contains($rep['subtitle'], 'Berlaku'));
$xlsx = ReportWorkbook::build($rep);
check('laporan: Excel sah (berkas zip) dari struktur yang sama', str_starts_with($xlsx, 'PK') && strlen($xlsx) > 1000);

// ======================= penerimaan uang =======================
$zLine = (int) $z['id'];
$xLine = (int) $x['id'];
check('terima: tanggal harus valid dan tidak di masa depan', isset(violation(fn () => PayoutService::receive(req(), $head, $id, $zLine, '2027-13-40', null))['received_on']) && isset(violation(fn () => PayoutService::receive(req(), $head, $id, $zLine, date('Y-m-d', strtotime('+3 days')), null))['received_on']) && (int) one('SELECT COUNT(*) FROM final_payout_receipts') === 0);
check('terima: tidak boleh sebelum pencairan disusun', isset(violation(fn () => PayoutService::receive(req(), $head, $id, $zLine, '2020-01-01', null))['received_on']));
check('terima: anggota yang diterimanya 0 (X) tidak bisa dicatat; baris dari pencairan lain tidak dikenal', str_contains(violation(fn () => PayoutService::receive(req(), $head, $id, $xLine, date('Y-m-d'), null))['_form'] ?? '', 'tidak menerima uang tunai') && isset(violation(fn () => PayoutService::receive(req(), $head, $id, 99999, date('Y-m-d'), null))['_form']));
PayoutService::receive(req(), $head, $id, $zLine, date('Y-m-d'), 'Diterima langsung');
$pr = array_column(PayoutService::find($id)['lines'], null, 'member_no')['AGT-003'];
check('terima: tercatat dengan tanggal, pencatat, catatan; di audit (jumlah dan anggota); baris pencairan tidak berubah', $pr['received_on'] === date('Y-m-d') && $pr['received_note'] === 'Diterima langsung' && $pr['received_by'] === 'Kepala'
    && (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'PAYOUT_RECEIVED' AND entity_id = ?", [$id]) === 1 && (int) $pr['net_paid'] === (int) $z['net_paid']);
check('terima: sekali per anggota (kedua ditolak), tetap 1 catatan', str_contains(violation(fn () => PayoutService::receive(req(), $head, $id, $zLine, date('Y-m-d'), null))['_form'] ?? '', 'sudah dicatat') && (int) one('SELECT COUNT(*) FROM final_payout_receipts') === 1);
check('terima: catatan penerimaan tidak bisa diubah atau dihapus (trigger)', (function () use ($pdo): bool {
    $blocked = 0;
    foreach (["UPDATE final_payout_receipts SET note = 'x'", 'DELETE FROM final_payout_receipts'] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            $blocked++;
        }
    }
    return $blocked === 2;
})());

// ======================= pembatalan =======================
check('batal: alasan wajib (kosong atau terlalu pendek ditolak)', isset(violation(fn () => PayoutService::void(req(), $head, $id, '  '))['reason']) && isset(violation(fn () => PayoutService::void(req(), $head, $id, 'abc'))['reason']));
check('batal: ditolak bila sudah ada anggota yang dicatat menerima uang; tidak ada catatan batal', str_contains(violation(fn () => PayoutService::void(req(), $head, $id, 'Alasan yang cukup'))['_form'] ?? '', 'sudah ada anggota yang dicatat menerima') && (int) one('SELECT COUNT(*) FROM final_payout_voids') === 0);
check('snapshot dasar: pembatalan snapshot ditolak selama pencairan berlaku memakainya', str_contains(violation(fn () => ProfitReport::voidSnapshot(req(), $head, $period, $snapId, 'Mencoba membatalkan dasar'))['_form'] ?? '', 'Pencairan Akhir') && (int) one('SELECT COUNT(*) FROM profit_share_snapshot_voids') === 0);

// pencairan kedua tanpa penerimaan, untuk menguji pembatalan
// buat periode kedua berisi data kecil agar bisa disusun terpisah dari yang sudah ada penerimaannya
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Dua', '2028-01-01', '2028-12-31', 'AKTIF')");
for ($m = 1; $m <= 12; $m++) {
    $pdo->exec(sprintf("INSERT INTO period_months (period_id, month_date) VALUES (2, '2028-%02d-01')", $m));
}
$mo2 = array_map(static fn (array $r): int => (int) $r['id'], Database::select('SELECT id FROM period_months WHERE period_id = 2 ORDER BY month_date'));
$s3 = trx('SIMPANAN', 3, 80000, $mo2[0], '2028-01-05');
$pdo->exec("INSERT INTO savings (transaction_id, kind) VALUES ({$s3}, 'WAJIB')");
approve($s3);
$snap2 = ProfitReport::createSnapshot(req(), $head, 2, null);
$id2 = PayoutService::create(req(), $head, 2, $snap2, 100, [], null);
check('periode lain: pencairan disusun terpisah dari periode pertama (satu berlaku per periode, bukan global)', $id2 > 0 && (int) one('SELECT COUNT(*) FROM final_payouts') === 2 && PayoutService::batches(2)[0]['id'] == $id2 && count(PayoutService::batches($period)) === 1);
check('pencairan: snapshot milik periode lain tidak bisa dipakai', PayoutService::preview(2, $snapId) === null);
PayoutService::void(req(), $head, $id2, 'Salah memilih pembulatan');
$p2 = PayoutService::find($id2);
check('batal: tercatat dengan alasan, pelaku, waktu; di audit; pencairan dan barisnya TIDAK berubah (masih terbaca)', $p2['voided'] === true && $p2['void_reason'] === 'Salah memilih pembulatan' && $p2['void_name'] === 'Kepala' && (int) $p2['members_count'] === count($p2['lines'])
    && (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'PAYOUT_VOIDED' AND entity_id = ?", [$id2]) === 1 && str_contains(PayoutService::report($p2)['subtitle'], 'DIBATALKAN'));
check('batal: tidak bisa dibatalkan dua kali; tidak bisa mencatat penerimaan pada yang dibatalkan', str_contains(violation(fn () => PayoutService::void(req(), $head, $id2, 'Batal lagi dua kali'))['_form'] ?? '', 'sudah dibatalkan')
    && str_contains(violation(fn () => PayoutService::receive(req(), $head, $id2, (int) $p2['lines'][0]['id'], date('Y-m-d'), null))['_form'] ?? '', 'sudah dibatalkan'));
$id3 = PayoutService::create(req(), $head, 2, $snap2, 1000, [], 'Ulang');
check('batal: sesudah dibatalkan, pencairan baru boleh disusun ulang (2 pada periode itu, 1 berlaku)', $id3 > $id2 && count(PayoutService::batches(2)) === 2 && PayoutService::batches(2)[0]['void_reason'] === null && PayoutService::batches(2)[1]['void_reason'] !== null);
PayoutService::void(req(), $head, $id3, 'Dibatalkan agar snapshot dapat dibatalkan');
ProfitReport::voidSnapshot(req(), $head, 2, $snap2, 'Snapshot salah');
check('snapshot dasar: setelah pencairannya dibatalkan, snapshot boleh dibatalkan; pencairan ke snapshot batal tidak bisa disusun', (int) one('SELECT COUNT(*) FROM profit_share_snapshot_voids WHERE snapshot_id = ?', [$snap2]) === 1
    && isset(violation(fn () => PayoutService::create(req(), $head, 2, $snap2, 1000, [], null))['_form']));
check('batal: catatan pembatalan tidak bisa diubah atau dihapus (trigger)', (function () use ($pdo): bool {
    $blocked = 0;
    foreach (["UPDATE final_payout_voids SET reason = 'x'", 'DELETE FROM final_payout_voids'] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            $blocked++;
        }
    }
    return $blocked === 2;
})());

echo "\n" . ($failed === [] ? "Semua {$passed} pemeriksaan lulus.\n" : count($failed) . " GAGAL dari " . ($passed + count($failed)) . ".\n");
exit($failed === [] ? 0 : 1);
