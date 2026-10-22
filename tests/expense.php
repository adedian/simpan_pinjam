<?php
declare(strict_types=1);

/**
 * Tes Biaya (ExpenseService) dan Laporan Jasa Pinjaman + Kas SHU (ShuReport): aturan pencatatan, alur status, validasi,
 * pengaruh ke kas dan Kas SHU, cakupan. Bulan dibuat relatif terhadap hari ini (periode aktif 12 bulan, mulai 5 bulan lalu).
 * MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\expense.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Models\User;
use App\Services\ExpenseService;
use App\Services\Migrator;
use App\Services\ReportService;
use App\Services\ReportWorkbook;
use App\Services\RuleViolation;
use App\Services\ShuReport;
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
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.4', 'HTTP_USER_AGENT' => 'tes-biaya']);
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
        ->execute(["T-BY-{$n}", "BY-{$n}", $type, $member, null, $monthId, $date, $amount]);
    return (int) $pdo->lastInsertId();
}
function approveRaw(int $id): void
{
    $pdo = Database::pdo();
    $pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI' WHERE id = {$id}");
    $pdo->exec("UPDATE transactions SET status = 'DISETUJUI' WHERE id = {$id}");
}
function ver(int $id): string
{
    return (string) one('SELECT updated_at FROM transactions WHERE id = ?', [$id]);
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
    approveRaw($id);
}
function data(array $over = []): array
{
    return $over + ['category' => 'Cetak laporan', 'period_month_id' => 0, 'amount' => '45.000', 'trx_date' => '', 'description' => 'Uji', 'confirm_duplicate' => '1'];
}

// ======================= fixture: periode aktif 12 bulan, mulai 5 bulan lalu (6 bulan pertama sudah berjalan) =======================
$start = strtotime(date('Y-m-01') . ' -5 months');
$pdo->prepare("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji Biaya', ?, ?, 'AKTIF')")
    ->execute([date('Y-m-d', $start), date('Y-m-d', strtotime(date('Y-m-d', $start) . ' +12 months -1 day'))]);
$mo = [];   // indeks 0..11 => id bulan
$md = [];   // indeks => tanggal 1 bulan
for ($i = 0; $i < 12; $i++) {
    $d = date('Y-m-01', strtotime(date('Y-m-d', $start) . " +{$i} months"));
    $pdo->prepare('INSERT INTO period_months (period_id, month_date) VALUES (1, ?)')->execute([$d]);
    $mo[$i] = (int) $pdo->lastInsertId();
    $md[$i] = $d;
}
$now = 5;   // bulan berjalan = indeks 5
$pdo->exec("INSERT INTO members (member_no, name, active_from, status) VALUES ('AGT-001', 'Anggota X', '{$md[0]}', 'AKTIF'), ('AGT-002', 'Anggota Y', '{$md[0]}', 'AKTIF')");
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu X', 1)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1, 1, '{$md[0]}'), (2, 1, '{$md[0]}')");
foreach ([[1, 3000000], [2, 2000000]] as [$mem, $amt]) {
    $s = trx('SIMPANAN', $mem, $amt, $mo[1], $md[1]);
    $pdo->exec("INSERT INTO savings (transaction_id, kind) VALUES ({$s}, 'WAJIB')");
    approveRaw($s);
}
// dua pinjaman dicairkan pada bulan indeks 3: bunga 20.000 + 100.000 = 120.000; pokok 2.000.000 => kas 5.000.000 - 2.000.000 = 3.000.000
loan(1, 1000000, 1, 20000, $mo[3], $md[3], [$mo[4]], [1020000]);
loan(2, 1000000, 5, 100000, $mo[3], $md[3], [$mo[4], $mo[5], $mo[6], $mo[7], $mo[8]], [220000, 220000, 220000, 220000, 220000]);

$ids = [];
foreach ([['kepala', ['HEAD'], null], ['kepala2', ['HEAD'], null], ['ketua.x', ['KETUA_REGU'], 'AGT-001'], ['anggota.y', ['ANGGOTA'], 'AGT-002']] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$head = User::findActive($ids['kepala']);
$head2 = User::findActive($ids['kepala2']);
$ketua = User::findActive($ids['ketua.x']);
$anggota = User::findActive($ids['anggota.y']);
$kas = static fn (): int => (int) one('SELECT kas_tersedia FROM v_global_summary');
$biayaApproved = static fn (): int => (int) one('SELECT biaya FROM v_global_summary');
$today = date('Y-m-d');

check('fixture: kas awal 3.000.000 dan biaya 0', $kas() === 3000000 && $biayaApproved() === 0);

// ======================= parse =======================
[$e, $d0] = ExpenseService::parse(data(['category' => '  ', 'period_month_id' => $mo[$now], 'trx_date' => $today]));
check('parse: keperluan kosong ditolak', isset($e['category']));
check('parse: keperluan lebih dari 60 karakter ditolak', isset(ExpenseService::parse(data(['category' => str_repeat('a', 61), 'period_month_id' => $mo[$now], 'trx_date' => $today]))[0]['category']));
check('parse: nominal bukan angka, nol, dan terlalu besar ditolak; format titik ribuan diterima',
    isset(ExpenseService::parse(data(['amount' => '12,5', 'period_month_id' => $mo[$now], 'trx_date' => $today]))[0]['amount'])
    && isset(ExpenseService::parse(data(['amount' => '0', 'period_month_id' => $mo[$now], 'trx_date' => $today]))[0]['amount'])
    && isset(ExpenseService::parse(data(['amount' => '60.000.000', 'period_month_id' => $mo[$now], 'trx_date' => $today]))[0]['amount'])
    && ExpenseService::parse(data(['amount' => '45.000', 'period_month_id' => $mo[$now], 'trx_date' => $today]))[0] === []);
check('parse: tanggal tidak valid dan bulan kosong ditolak', isset(ExpenseService::parse(data(['trx_date' => '2026-13-45', 'period_month_id' => $mo[$now]]))[0]['trx_date']) && isset(ExpenseService::parse(data(['trx_date' => $today]))[0]['period_month_id']));

// ======================= hak mencatat =======================
[$e, $okData] = ExpenseService::parse(data(['period_month_id' => $mo[$now], 'trx_date' => $today]));
check('hak: Admin (ketua regu) dan Anggota tidak boleh mencatat biaya; tidak ada yang tersimpan', isset(violation(fn () => ExpenseService::create(req(), $ketua, $okData, false))['_form'])
    && isset(violation(fn () => ExpenseService::create(req(), $anggota, $okData, false))['_form']) && (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'BIAYA'") === 0);

// ======================= aturan bulan dan tanggal =======================
$mk = static fn (array $over): array => ExpenseService::parse(data($over + ['period_month_id' => $mo[$now], 'trx_date' => $today]))[1];
check('aturan: bulan yang belum berjalan ditolak', isset(violation(fn () => ExpenseService::create(req(), $head, $mk(['period_month_id' => $mo[$now + 2], 'trx_date' => $md[$now + 2]]), false))['period_month_id']));
check('aturan: tanggal di masa depan ditolak', isset(violation(fn () => ExpenseService::create(req(), $head, $mk(['trx_date' => date('Y-m-d', strtotime('+3 days'))]), false))['trx_date']) || date('Y-m', strtotime('+3 days')) !== date('Y-m'));
check('aturan: tanggal sebelum awal bulan yang dipilih ditolak', isset(violation(fn () => ExpenseService::create(req(), $head, $mk(['period_month_id' => $mo[$now], 'trx_date' => $md[$now - 1]]), false))['trx_date']));
check('aturan: bulan tak dikenal ditolak', isset(violation(fn () => ExpenseService::create(req(), $head, $mk(['period_month_id' => 99999]), false))['period_month_id']));
check('aturan: semua penolakan tidak meninggalkan baris', (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'BIAYA'") === 0);

// ======================= membuat draft =======================
$id1 = ExpenseService::create(req(), $head, $mk(['category' => 'Buku tabungan', 'amount' => '45.000', 'description' => 'Uji <b>x</b>']), false);
$t1 = Database::select("SELECT t.*, e.category, e.fund_source FROM transactions t JOIN expenses e ON e.transaction_id = t.id WHERE t.id = ?", [$id1])[0];
check('buat: tersimpan sebagai DRAFT, tanpa anggota dan regu, sumber dana SHU, nomor BYA, sumber APLIKASI', $t1['status'] === 'DRAFT' && $t1['type'] === 'BIAYA' && $t1['member_id'] === null && $t1['team_id'] === null && $t1['fund_source'] === 'SHU'
    && str_starts_with((string) $t1['doc_no'], 'BYA-' . date('Y')) && $t1['source'] === 'APLIKASI' && (int) $t1['amount'] === 45000 && $t1['category'] === 'Buku tabungan' && (int) $t1['created_by'] === $head['id']);
check('buat: tercatat di audit; draft belum memengaruhi kas atau biaya disetujui', (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'EXPENSE_CREATED' AND entity_id = ?", [$id1]) === 1 && $kas() === 3000000 && $biayaApproved() === 0);
check('buat: biaya kembar (bulan, keperluan, nominal sama) ditolak tanpa konfirmasi; dengan konfirmasi diterima', str_contains(violation(fn () => ExpenseService::create(req(), $head, $mk(['category' => 'Buku tabungan', 'amount' => '45.000', 'confirm_duplicate' => '']), false))['duplicate'] ?? '', 'sudah ada')
    && (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'BIAYA'") === 1);

// ======================= mengubah draft =======================
$v = ver($id1);
check('ubah: versi basi ditolak; yang lain bukan pembuat tidak boleh mengubah', isset(violation(fn () => ExpenseService::update(req(), $head, $id1, $mk(['amount' => '50.000']), '2000-01-01 00:00:00', false))['_form'])
    && str_contains(violation(fn () => ExpenseService::update(req(), $head2, $id1, $mk(['amount' => '50.000']), $v, false))['_form'] ?? '', 'Hanya pembuat'));
ExpenseService::update(req(), $head, $id1, $mk(['category' => 'Buku tabungan', 'amount' => '50.000', 'confirm_duplicate' => '1']), $v, false);
check('ubah: draft berubah (nominal 50.000) dan perubahan tercatat di audit (sebelum/sesudah)', (int) one('SELECT amount FROM transactions WHERE id = ?', [$id1]) === 50000 && (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'EXPENSE_UPDATED' AND entity_id = ?", [$id1]) === 1);

// ======================= mengajukan dan membatalkan =======================
ExpenseService::submit(req(), $head, $id1, ver($id1));
check('ajukan: status MENUNGGU_VALIDASI, riwayat dan audit tercatat, kas belum berubah', one('SELECT status FROM transactions WHERE id = ?', [$id1]) === 'MENUNGGU_VALIDASI' && (int) one('SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ?', [$id1]) === 1
    && (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'EXPENSE_SUBMITTED' AND entity_id = ?", [$id1]) === 1 && $kas() === 3000000);
check('ajukan: setelah diajukan tidak bisa diubah atau diajukan lagi', str_contains(violation(fn () => ExpenseService::update(req(), $head, $id1, $mk(['amount' => '60.000']), ver($id1), false))['_form'] ?? '', 'Hanya draft')
    && str_contains(violation(fn () => ExpenseService::submit(req(), $head, $id1, ver($id1)))['_form'] ?? '', 'tidak bisa diajukan'));
check('batal: alasan wajib; pembuat lain ditolak', isset(violation(fn () => ExpenseService::cancel(req(), $head, $id1, '  ', ver($id1)))['note']) && str_contains(violation(fn () => ExpenseService::cancel(req(), $head2, $id1, 'Salah', ver($id1)))['_form'] ?? '', 'Hanya pembuat'));

// Disetujui lewat validasi (Superadmin)
ValidationService::approve(req(), $head, $id1, ver($id1), null);
check('setujui: DISETUJUI; kas berkurang 50.000 (2.950.000); biaya disetujui 50.000; integritas selisih 0', one('SELECT status FROM transactions WHERE id = ?', [$id1]) === 'DISETUJUI' && $kas() === 2950000 && $biayaApproved() === 50000 && (int) one('SELECT selisih FROM v_global_summary') === 0);
check('setujui: biaya yang disetujui tidak bisa dibatalkan di sini (koreksi lewat pembalik)', str_contains(violation(fn () => ExpenseService::cancel(req(), $head, $id1, 'Salah catat', ver($id1)))['_form'] ?? '', 'tidak bisa dibatalkan'));

// batalkan satu draft
$id2 = ExpenseService::create(req(), $head, $mk(['category' => 'Alat tulis', 'amount' => '12.000']), false);
ExpenseService::cancel(req(), $head, $id2, 'Salah catat', ver($id2));
check('batal: draft menjadi DIBATALKAN dengan alasan di riwayat dan audit; nomor dokumen tetap; kas tetap', one('SELECT status FROM transactions WHERE id = ?', [$id2]) === 'DIBATALKAN' && (string) one('SELECT note FROM transaction_validations WHERE transaction_id = ? ORDER BY id DESC LIMIT 1', [$id2]) === 'Salah catat'
    && (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'EXPENSE_CANCELLED' AND entity_id = ?", [$id2]) === 1 && $kas() === 2950000);
check('batal: tidak bisa dibatalkan dua kali', str_contains(violation(fn () => ExpenseService::cancel(req(), $head, $id2, 'Lagi', ver($id2)))['_form'] ?? '', 'tidak bisa dibatalkan'));

// kas tidak cukup
$id3 = ExpenseService::create(req(), $head, $mk(['category' => 'Biaya besar', 'amount' => '4.000.000']), true);
check('kas: biaya melebihi kas tersedia tidak bisa disetujui (kas tetap, status tetap menunggu)', str_contains(violation(fn () => ValidationService::approve(req(), $head, $id3, ver($id3), null))['_form'] ?? '', 'Kas tersedia tidak cukup') && one('SELECT status FROM transactions WHERE id = ?', [$id3]) === 'MENUNGGU_VALIDASI' && $kas() === 2950000);
ValidationService::reject(req(), $head, $id3, ver($id3), 'Terlalu besar');

// ======================= daftar =======================
$list = ExpenseService::listAll();
check('daftar: memuat 3 biaya (disetujui, batal, ditolak) dengan keperluan dan pembuat; yang bukan biaya tidak ikut', count($list) === 3 && array_column($list, 'category', 'id')[$id1] === 'Buku tabungan' && array_column($list, 'creator_name', 'id')[$id1] === 'Kepala');
check('daftar: find hanya mengenali biaya (id transaksi lain = null)', ExpenseService::find($id1) !== null && ExpenseService::find((int) one("SELECT id FROM transactions WHERE type = 'SIMPANAN' LIMIT 1")) === null);

// ======================= Laporan Jasa Pinjaman + Kas SHU =======================
$f = ReportService::filters([], $head);
$rep = ShuReport::build($head, $f);
$rows = array_column($rep['rows'], null, 'month');
$mLoan = month_label($md[3]);
$mExp = month_label($md[$now]);
check('shu: 6 bulan berjalan tampil (periode aktif sampai bulan ini), kunci laporan terdaftar', count($rep['rows']) === 6 && $rep['key'] === 'shu' && in_array('shu', ReportService::KEYS, true));
check('shu: bulan pinjaman cair: 2 pinjaman, pokok 2.000.000, bunga 120.000 dibagi 48.000 penabung + 48.000 peminjam + 24.000 Kas SHU', $rows[$mLoan]['loans'] === 2 && $rows[$mLoan]['principal'] === 2000000 && $rows[$mLoan]['interest'] === 120000
    && $rows[$mLoan]['saver'] === 48000 && $rows[$mLoan]['borrower'] === 48000 && $rows[$mLoan]['shu_in'] === 24000);
check('shu: bunga kumulatif 120.000 sejak bulan cair; biaya 50.000 tercatat pada bulan biaya; saldo Kas SHU = 24.000 - 50.000 = -26.000 (pemasukan dikurangi biaya disetujui)',
    $rows[$mLoan]['cumulative'] === 120000 && $rows[$mExp]['expense'] === 50000 && $rep['totals']['balance'] === -26000 && $rep['totals']['expense'] === 50000 && $rep['totals']['shu_in'] === 24000);
check('shu: ringkasan: sisa Kas SHU -26.000 dibagi 60% anggota (-15.600) dan 40% pengelola (-10.400); kontrol bunga = penabung + peminjam + SHU seimbang', $rep['summary'][2]['value'] === -26000 && $rep['summary'][3]['value'] === -15600 && $rep['summary'][4]['value'] === -10400 && $rep['control']['ok'] === true);
check('shu: biaya yang masih draft, menunggu, dibatalkan, atau ditolak TIDAK dihitung (hanya yang disetujui)', $rep['totals']['expense'] === 50000);
// biaya kedua disetujui dan pembalik tidak dibuat di sini; pastikan angka mengikuti
$id4 = ExpenseService::create(req(), $head, $mk(['category' => 'Cetak', 'amount' => '4.000', 'period_month_id' => $mo[$now - 1], 'trx_date' => $md[$now - 1]]), true);
ValidationService::approve(req(), $head, $id4, ver($id4), null);
$rep2 = ShuReport::build($head, $f);
check('shu: biaya kedua yang disetujui menambah biaya (54.000) dan menggeser saldo (-30.000); bunga tak berubah', $rep2['totals']['expense'] === 54000 && $rep2['totals']['balance'] === -30000 && $rep2['totals']['interest'] === 120000);
check('shu: cakupan: Admin dan Anggota mendapat laporan kosong (tanpa angka koperasi); tamu juga', ShuReport::build($ketua, ReportService::filters([], $ketua))['rows'] === [] && ShuReport::build($anggota, ReportService::filters([], $anggota))['rows'] === [] && ShuReport::build(null, $f)['rows'] === []);
check('shu: Excel sah dari struktur yang sama', str_starts_with(ReportWorkbook::build($rep2), 'PK'));

echo "\n" . ($failed === [] ? "Semua {$passed} pemeriksaan lulus.\n" : count($failed) . " GAGAL dari " . ($passed + count($failed)) . ".\n");
exit($failed === [] ? 0 : 1);
