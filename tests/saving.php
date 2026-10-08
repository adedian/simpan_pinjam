<?php
declare(strict_types=1);

/**
 * Tes transaksi simpanan (Phase 6): aturan bisnis, alur status, audit, cakupan data, token formulir.
 * MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\saving.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FormToken;
use App\Services\Migrator;
use App\Services\RuleViolation;
use App\Services\SavingService;
use App\Services\UserService;

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
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.1', 'HTTP_USER_AGENT' => 'tes-simpanan']);
}
function audits(string $action): int
{
    return (int) one('SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]);
}
function lastAudit(string $action): array
{
    $stmt = Database::pdo()->prepare('SELECT * FROM audit_logs WHERE action = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$action]);
    $r = $stmt->fetch() ?: [];
    $r['before'] = isset($r['before_data']) ? json_decode((string) $r['before_data'], true) : null;
    $r['after']  = isset($r['after_data']) ? json_decode((string) $r['after_data'], true) : null;
    return $r;
}
/** Jalankan aksi yang HARUS melanggar aturan; kembalikan galat per field atau null bila tidak melanggar. */
function violation(callable $fn): ?array
{
    try {
        $fn();
    } catch (RuleViolation $e) {
        return $e->errors;
    }
    return null;
}
function trx(int $id): array
{
    $stmt = Database::pdo()->prepare('SELECT t.*, s.kind FROM transactions t LEFT JOIN savings s ON s.transaction_id = t.id WHERE t.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: [];
}
function balance(int $memberId): int
{
    return (int) one('SELECT savings_balance FROM v_member_savings WHERE member_id = ?', [$memberId]);
}

// ---------------- fixture: bulan dibuat relatif terhadap hari ini agar tes tidak lapuk ----------------
$m0 = date('Y-m-01');
$m1 = date('Y-m-01', strtotime('-1 month', strtotime($m0)));
$m2 = date('Y-m-01', strtotime('-2 month', strtotime($m0)));
$today = date('Y-m-d');
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji', '{$m2}', '2099-12-31', 'AKTIF'), ('Lama', '2020-01-01', '2020-12-31', 'TUTUP')");
$pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1,'{$m2}'),(1,'{$m1}'),(1,'{$m0}'),(1,'2099-01-01'),(2,'2020-03-01')");
[$mo2, $mo1, $mo0, $moFuture, $moClosed] = array_map('intval', $pdo->query('SELECT id FROM period_months ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));

$members = [1 => 'Ketua Alfa', 2 => 'Anggota Alfa', 3 => 'Ketua Beta', 4 => 'Anggota Beta', 5 => 'Anggota Baru', 6 => 'Anggota Nonaktif'];
foreach ($members as $n => $name) {
    $from = $n === 5 ? $m0 : $m2;   // anggota 5 baru aktif bulan ini
    $pdo->prepare("INSERT INTO members (member_no, name, active_from, status) VALUES (?, ?, ?, ?)")->execute([sprintf('AGT-%03d', $n), $name, $from, $n === 6 ? 'NONAKTIF' : 'AKTIF']);
}
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Alfa', 1), ('Regu Beta', 3)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'{$m2}'),(2,1,'{$m2}'),(3,2,'{$m2}'),(4,2,'{$m2}'),(5,1,'{$m0}'),(6,1,'{$m2}')");

$ids = [];
foreach ([['ketua.alfa', ['KETUA_REGU'], 'AGT-001'], ['ketua.beta', ['KETUA_REGU'], 'AGT-003'], ['kepala', ['HEAD'], null], ['periksa', ['PEMERIKSA'], null], ['anggota2', ['ANGGOTA'], 'AGT-002']] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$alfa  = User::findActive($ids['ketua.alfa']);
$beta  = User::findActive($ids['ketua.beta']);
$head  = User::findActive($ids['kepala']);
$pemeriksa = User::findActive($ids['periksa']);
$anggota2  = User::findActive($ids['anggota2']);

/** Isian formulir yang sah; bisa ditimpa per tes. */
function form(array $over = []): array
{
    global $mo0, $today;
    return $over + ['member_id' => 2, 'period_month_id' => $mo0, 'kind' => 'WAJIB', 'amount' => '50.000', 'trx_date' => $today, 'description' => ''];
}
/** parse() + create() sekaligus; melempar bila isian tidak sah. */
function make(array $actor, array $over = [], bool $submit = false): int
{
    [$errors, $data] = SavingService::parse(form($over));
    if ($errors !== []) {
        throw new RuntimeException('fixture tidak sah: ' . json_encode($errors));
    }
    return SavingService::create(req(), $actor, $data, $submit);
}
function approve(int $id): void
{
    $pdo = Database::pdo();
    if (trx($id)['status'] === 'DRAFT') {
        $pdo->exec("UPDATE transactions SET status='MENUNGGU_VALIDASI' WHERE id={$id}");
    }
    $pdo->exec("UPDATE transactions SET status='DISETUJUI' WHERE id={$id}");
}

// ======================= parse =======================
[$e, $d] = SavingService::parse(form());
check('parse: isian sah menghasilkan nominal 50000 tanpa galat', $e === [] && $d['amount'] === 50000 && $d['kind'] === 'WAJIB' && $d['description'] === null);
foreach (['' => 'kosong', 'abc' => 'huruf', '0' => 'nol', '-5000' => 'negatif', '50.5' => 'desimal', '50,000.5' => 'campur', '100.000.001' => 'di atas batas'] as $bad => $why) {
    check("parse: nominal {$why} ditolak", isset(SavingService::parse(form(['amount' => (string) $bad]))[0]['amount']));
}
check('parse: nominal "Rp 1.000.000" dan "1000000" diterima', SavingService::parse(form(['amount' => 'Rp 1.000.000']))[1]['amount'] === 1000000 && SavingService::parse(form(['amount' => '1000000']))[1]['amount'] === 1000000);
check('parse: nominal tepat di batas diterima', SavingService::parse(form(['amount' => '100.000.000']))[0] === []);
check('parse: jenis CAMPURAN (khusus impor) ditolak', isset(SavingService::parse(form(['kind' => 'CAMPURAN']))[0]['kind']));
check('parse: jenis kosong dan ngawur ditolak', isset(SavingService::parse(form(['kind' => '']))[0]['kind']) && isset(SavingService::parse(form(['kind' => 'X']))[0]['kind']));
check('parse: anggota dan bulan wajib', isset(SavingService::parse(form(['member_id' => 0, 'period_month_id' => 0]))[0]['member_id'], SavingService::parse(form(['member_id' => 0, 'period_month_id' => 0]))[0]['period_month_id']));
check('parse: tanggal tidak ada di kalender ditolak', isset(SavingService::parse(form(['trx_date' => '2026-02-30']))[0]['trx_date']) && isset(SavingService::parse(form(['trx_date' => 'kemarin']))[0]['trx_date']) && isset(SavingService::parse(form(['trx_date' => '']))[0]['trx_date']));
check('parse: keterangan dirapikan dan dibatasi 255', SavingService::parse(form(['description' => "  a \t  b  "]))[1]['description'] === 'a b' && isset(SavingService::parse(form(['description' => str_repeat('x', 256)]))[0]['description']));

// ======================= buat draft =======================
$before = balance(2);
$id1 = make($alfa, ['description' => 'Setoran pertama']);
$t1  = trx($id1);
check('buat: berstatus DRAFT, jenis SIMPANAN, sumber APLIKASI', $t1['status'] === 'DRAFT' && $t1['type'] === 'SIMPANAN' && $t1['source'] === 'APLIKASI' && $t1['kind'] === 'WAJIB');
check('buat: nomor dokumen SMP-<tahun>-000001 dan TRX-<tahun>-000001', $t1['doc_no'] === 'SMP-' . date('Y') . '-000001' && $t1['trx_no'] === 'TRX-' . date('Y') . '-000001');
check('buat: regu, pembuat, nominal, tanggal tersimpan benar', (int) $t1['team_id'] === 1 && (int) $t1['created_by'] === $alfa['id'] && (int) $t1['amount'] === 50000 && $t1['trx_date'] === $today && $t1['description'] === 'Setoran pertama');
check('buat: audit SAVING_CREATED memuat isi transaksi', audits('SAVING_CREATED') === 1 && lastAudit('SAVING_CREATED')['after']['amount'] === 50000 && lastAudit('SAVING_CREATED')['reference_no'] === $t1['doc_no']);
check('buat: draft tidak menambah saldo anggota', balance(2) === $before);
check('buat: draft belum punya riwayat validasi', (int) one('SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ?', [$id1]) === 0);
$id2 = make($alfa, ['amount' => '75000', 'kind' => 'SUKARELA'], true);
$t2  = trx($id2);
check('buat+ajukan: langsung MENUNGGU_VALIDASI dan nomor berlanjut (000002)', $t2['status'] === 'MENUNGGU_VALIDASI' && $t2['doc_no'] === 'SMP-' . date('Y') . '-000002');
$v = Database::select('SELECT * FROM transaction_validations WHERE transaction_id = ?', [$id2]);
check('buat+ajukan: satu riwayat DRAFT -> MENUNGGU_VALIDASI oleh pembuat', count($v) === 1 && $v[0]['from_status'] === 'DRAFT' && $v[0]['to_status'] === 'MENUNGGU_VALIDASI' && (int) $v[0]['actor_user_id'] === $alfa['id']);
check('buat+ajukan: audit dibuat dan diajukan; status_changed_at terisi', audits('SAVING_SUBMITTED') === 1 && $t2['status_changed_at'] !== null);
check('buat+ajukan: belum memengaruhi saldo', balance(2) === $before);

// ======================= aturan pencatatan =======================
$seqBefore = (int) one("SELECT last_value FROM number_sequences WHERE seq_key = ?", ['SMP-' . date('Y')]);
$try = fn (array $over, ?array $actor = null) => violation(fn () => make($actor ?? $alfa, $over));
check('aturan: anggota regu lain ditolak', isset($try(['member_id' => 4])['member_id']));
check('aturan: anggota tidak ada ditolak', isset($try(['member_id' => 999])['member_id']));
check('aturan: pesan anggota regu lain tidak membocorkan nama', !str_contains($try(['member_id' => 4])['member_id'], 'Beta'));
check('aturan: anggota nonaktif ditolak', isset($try(['member_id' => 6])['member_id']) && str_contains($try(['member_id' => 6])['member_id'], 'nonaktif'));
check('aturan: bulan sebelum anggota aktif ditolak', isset($try(['member_id' => 5, 'period_month_id' => $mo1, 'trx_date' => $m1])['period_month_id']));
check('aturan: bulan yang belum berjalan ditolak', isset($try(['period_month_id' => $moFuture])['period_month_id']));
check('aturan: periode yang sudah ditutup ditolak', isset($try(['period_month_id' => $moClosed, 'trx_date' => '2020-03-05'])['period_month_id']));
check('aturan: bulan tidak ada ditolak', isset($try(['period_month_id' => 9999])['period_month_id']));
check('aturan: tanggal di masa depan ditolak', isset($try(['trx_date' => date('Y-m-d', strtotime('+1 day'))])['trx_date']));
check('aturan: tanggal sebelum awal bulan siklus ditolak', isset($try(['trx_date' => date('Y-m-d', strtotime('-1 day', strtotime($m0)))])['trx_date']));
check('aturan: bulan lalu dengan tanggal bulan lalu diterima', violation(fn () => make($alfa, ['period_month_id' => $mo1, 'trx_date' => $m1, 'amount' => '60000'])) === null);
check('aturan: Pemeriksa, Head, Anggota tidak boleh mencatat', isset($try([], $pemeriksa)['_form']) && isset($try([], $head)['_form']) && isset($try([], $anggota2)['_form']));
check('aturan: akun tanpa regu aktif ditolak', isset($try([], ['id' => $alfa['id'], 'username' => 'x', 'roles' => ['KETUA_REGU'], 'team_id' => null])['_form']));
check('aturan: ketua regu Beta tidak bisa mencatat untuk anggota Alfa', isset($try(['member_id' => 2], $beta)['member_id']));
check('aturan: pelanggaran tidak menghabiskan nomor urut (nomor terakhir = jumlah transaksi, tanpa celah)', (int) one("SELECT last_value FROM number_sequences WHERE seq_key = ?", ['SMP-' . date('Y')]) === (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'SIMPANAN'") && (int) one("SELECT last_value FROM number_sequences WHERE seq_key = ?", ['SMP-' . date('Y')]) > $seqBefore);

// duplikat
$dupErr = $try(['amount' => '50.000']);   // identik dengan $id1 (anggota 2, bulan ini, WAJIB, 50.000)
check('duplikat: setoran identik diminta konfirmasi', isset($dupErr['duplicate']) && str_contains($dupErr['duplicate'], $t1['doc_no']));
check('duplikat: lolos bila dikonfirmasi', violation(fn () => make($alfa, ['amount' => '50.000', 'confirm_duplicate' => '1'])) === null);
check('duplikat: nominal beda bukan duplikat', violation(fn () => make($alfa, ['amount' => '51.000'])) === null);
check('duplikat: jenis beda bukan duplikat', violation(fn () => make($alfa, ['amount' => '50.000', 'kind' => 'POKOK'])) === null);
$idC = make($alfa, ['member_id' => 2, 'amount' => '33.000']);
SavingService::cancel(req(), $alfa, $idC, 'salah catat', trx($idC)['updated_at']);
check('duplikat: transaksi yang sudah dibatalkan tidak dihitung', violation(fn () => make($alfa, ['amount' => '33.000'])) === null);

// rollback: gagal setelah nomor diambil harus mengembalikan nomor
$seqNow = (int) one("SELECT last_value FROM number_sequences WHERE seq_key = ?", ['SMP-' . date('Y')]);
$boom = false;
try {
    SavingService::create(req(), $alfa, ['member_id' => 2, 'period_month_id' => $mo0, 'kind' => 'WAJIB', 'amount' => 0, 'trx_date' => $today, 'description' => null], false);
} catch (PDOException $e) {
    $boom = true;
}
check('atomik: kegagalan database membatalkan seluruh pencatatan', $boom && (int) one("SELECT last_value FROM number_sequences WHERE seq_key = ?", ['SMP-' . date('Y')]) === $seqNow);
check('atomik: tidak ada baris yatim tertinggal', (int) one('SELECT COUNT(*) FROM transactions WHERE amount = 0') === 0 && (int) one('SELECT COUNT(*) FROM transactions t WHERE NOT EXISTS (SELECT 1 FROM savings s WHERE s.transaction_id = t.id)') === 0);

// ======================= ubah draft =======================
$id3 = make($alfa, ['amount' => '20.000', 'kind' => 'SUKARELA', 'description' => 'awal']);
$ver = trx($id3)['updated_at'];
[$e, $d] = SavingService::parse(form(['amount' => '25.000', 'kind' => 'SUKARELA', 'description' => 'awal']));
sleep(1);   // updated_at berpresisi detik: versi baru baru berbeda setelah detik berganti
SavingService::update(req(), $alfa, $id3, $d, $ver, false);
check('ubah: nominal berubah, status tetap DRAFT, nomor tetap', (int) trx($id3)['amount'] === 25000 && trx($id3)['status'] === 'DRAFT');
$au = lastAudit('SAVING_UPDATED');
check('ubah: audit hanya memuat field yang berubah (sebelum/sesudah)', $au['before'] === ['amount' => 20000] && $au['after'] === ['amount' => 25000]);
$n = audits('SAVING_UPDATED');
SavingService::update(req(), $alfa, $id3, $d, trx($id3)['updated_at'], false);
check('ubah: tanpa perubahan tidak menulis audit', audits('SAVING_UPDATED') === $n);
check('ubah: versi basi ditolak (edit bersamaan)', isset(violation(fn () => SavingService::update(req(), $alfa, $id3, $d, $ver, false))['_form']));
[, $dKind] = SavingService::parse(form(['amount' => '25.000', 'kind' => 'POKOK']));
SavingService::update(req(), $alfa, $id3, $dKind, trx($id3)['updated_at'], false);
check('ubah: jenis simpanan ikut berubah', trx($id3)['kind'] === 'POKOK');
check('ubah: aturan pencatatan berlaku juga saat ubah (anggota regu lain)', isset(violation(fn () => SavingService::update(req(), $alfa, $id3, SavingService::parse(form(['member_id' => 4]))[1], trx($id3)['updated_at'], false))['member_id']));
check('ubah: bukan pembuat ditolak (ketua regu lain)', isset(violation(fn () => SavingService::update(req(), $beta, $id3, $d, trx($id3)['updated_at'], false))['_form']));
check('ubah: bukan pembuat ditolak (Head)', isset(violation(fn () => SavingService::update(req(), $head, $id3, $d, trx($id3)['updated_at'], false))['_form']));
check('ubah: yang sudah diajukan tidak bisa diubah', isset(violation(fn () => SavingService::update(req(), $alfa, $id2, $d, trx($id2)['updated_at'], false))['_form']));
check('ubah: transaksi tidak ada ditolak', isset(violation(fn () => SavingService::update(req(), $alfa, 99999, $d, 'x', false))['_form']));
SavingService::update(req(), $alfa, $id3, SavingService::parse(form(['amount' => '26.000', 'kind' => 'POKOK']))[1], trx($id3)['updated_at'], true);
check('ubah+ajukan: berubah dan langsung MENUNGGU_VALIDASI', (int) trx($id3)['amount'] === 26000 && trx($id3)['status'] === 'MENUNGGU_VALIDASI');

// ======================= ajukan =======================
$id4 = make($alfa, ['amount' => '11.000', 'kind' => 'SUKARELA']);
check('ajukan: versi basi ditolak', isset(violation(fn () => SavingService::submit(req(), $alfa, $id4, '2000-01-01 00:00:00'))['_form']));
check('ajukan: bukan pembuat ditolak', isset(violation(fn () => SavingService::submit(req(), $beta, $id4, trx($id4)['updated_at']))['_form']) && isset(violation(fn () => SavingService::submit(req(), $head, $id4, trx($id4)['updated_at']))['_form']));
SavingService::submit(req(), $alfa, $id4, trx($id4)['updated_at']);
check('ajukan: DRAFT -> MENUNGGU_VALIDASI + riwayat + audit', trx($id4)['status'] === 'MENUNGGU_VALIDASI' && (int) one('SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ?', [$id4]) === 1);
check('ajukan: diajukan dua kali ditolak (tidak ada riwayat ganda)', isset(violation(fn () => SavingService::submit(req(), $alfa, $id4, trx($id4)['updated_at']))['_form']) && (int) one('SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ?', [$id4]) === 1);
$id5 = make($alfa, ['member_id' => 2, 'amount' => '12.000', 'kind' => 'SUKARELA']);
$pdo->exec('UPDATE members SET status = "NONAKTIF" WHERE id = 2');
check('ajukan: aturan diperiksa ulang (anggota dinonaktifkan setelah draft)', isset(violation(fn () => SavingService::submit(req(), $alfa, $id5, trx($id5)['updated_at']))['member_id']) && trx($id5)['status'] === 'DRAFT');
$pdo->exec('UPDATE members SET status = "AKTIF" WHERE id = 2');
$pdo->exec('UPDATE member_team_assignments SET valid_to = CURDATE() WHERE member_id = 2 AND valid_to IS NULL');
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (2, 2, CURDATE())");
check('ajukan: aturan diperiksa ulang (anggota pindah regu setelah draft)', isset(violation(fn () => SavingService::submit(req(), $alfa, $id5, trx($id5)['updated_at']))['member_id']));
$pdo->exec('DELETE FROM member_team_assignments WHERE member_id = 2 AND team_id = 2');
$pdo->exec('UPDATE member_team_assignments SET valid_to = NULL WHERE member_id = 2');

// ======================= batalkan =======================
$ver5 = trx($id5)['updated_at'];
check('batal: alasan wajib', isset(violation(fn () => SavingService::cancel(req(), $alfa, $id5, '   ', $ver5))['note']));
check('batal: alasan maksimal 200 karakter', isset(violation(fn () => SavingService::cancel(req(), $alfa, $id5, str_repeat('x', 201), $ver5))['note']));
check('batal: bukan pembuat ditolak', isset(violation(fn () => SavingService::cancel(req(), $beta, $id5, 'iseng', $ver5))['_form']) && trx($id5)['status'] === 'DRAFT');
SavingService::cancel(req(), $alfa, $id5, '  Salah   anggota ', $ver5);
$vv = Database::select('SELECT * FROM transaction_validations WHERE transaction_id = ?', [$id5]);
check('batal draft: DIBATALKAN, alasan dirapikan di riwayat, baris transaksi tetap ada', trx($id5)['status'] === 'DIBATALKAN' && $vv[0]['note'] === 'Salah anggota' && $vv[0]['to_status'] === 'DIBATALKAN' && (int) trx($id5)['id'] === $id5);
check('batal: audit SAVING_CANCELLED memuat alasan', lastAudit('SAVING_CANCELLED')['after']['note'] === 'Salah anggota');
SavingService::cancel(req(), $alfa, $id4, 'diajukan keliru', trx($id4)['updated_at']);
check('batal: MENUNGGU_VALIDASI bisa dibatalkan pembuatnya', trx($id4)['status'] === 'DIBATALKAN');
check('batal: yang sudah dibatalkan tidak bisa dibatalkan lagi / diajukan / diubah', isset(violation(fn () => SavingService::cancel(req(), $alfa, $id4, 'lagi', trx($id4)['updated_at']))['_form'])
    && isset(violation(fn () => SavingService::submit(req(), $alfa, $id4, trx($id4)['updated_at']))['_form']));
approve($id2);
check('batal: yang sudah DISETUJUI tidak bisa dibatalkan (koreksi lewat pembalik)', isset(violation(fn () => SavingService::cancel(req(), $alfa, $id2, 'oops', trx($id2)['updated_at']))['_form']) && trx($id2)['status'] === 'DISETUJUI');
check('batal: yang sudah DISETUJUI tidak bisa diubah', isset(violation(fn () => SavingService::update(req(), $alfa, $id2, $d, trx($id2)['updated_at'], false))['_form']));

// ======================= saldo hanya dari DISETUJUI =======================
check('saldo: baru bertambah setelah DISETUJUI (75.000 SUKARELA)', balance(2) === $before + 75000);
approve($id1);
check('saldo: persetujuan kedua menambah lagi (50.000)', balance(2) === $before + 125000);
check('saldo: kas global ikut dan integritas bersih', (int) one('SELECT kas_tersedia FROM v_global_summary') === 125000 && (int) one('SELECT COUNT(*) FROM v_integrity_issues') === 0);
check('saldo: draft/menunggu/dibatalkan tidak terhitung', (int) one('SELECT saldo_tabungan FROM v_global_summary') === 125000);

// ======================= cakupan data (Transaction model) =======================
// data regu Beta
$betaTrx = make($beta, ['member_id' => 4, 'amount' => '40.000']);
$allIds = fn (array $user, array $f = []) => array_map('intval', array_column(Transaction::search($user, $f, 1)['rows'], 'id'));
check('cakupan: Head melihat semua', count($allIds($head)) === (int) one('SELECT COUNT(*) FROM transactions'));
check('cakupan: Pemeriksa melihat semua', count($allIds($pemeriksa)) === (int) one('SELECT COUNT(*) FROM transactions'));
check('cakupan: ketua Alfa hanya melihat regunya', !in_array($betaTrx, $allIds($alfa), true) && in_array($id1, $allIds($alfa), true));
check('cakupan: ketua Beta hanya melihat regunya', $allIds($beta) === [$betaTrx]);
check('cakupan: Anggota hanya melihat miliknya (anggota 2)', $allIds($anggota2) === array_values(array_filter($allIds($head), fn ($i) => (int) trx($i)['member_id'] === 2)) && !in_array($betaTrx, $allIds($anggota2), true));
check('cakupan: tanpa izin = tidak ada hasil', Transaction::search(['roles' => [], 'id' => 1], [], 1)['pager']['total'] === 0 && Transaction::search(null, [], 1)['pager']['total'] === 0);
check('cakupan: findScoped di luar cakupan = null, sama seperti tidak ada', Transaction::findScoped($alfa, $betaTrx) === null && Transaction::findScoped($alfa, 999999) === null && Transaction::findScoped($head, $betaTrx) !== null);
check('cakupan: Transaction::exists membedakan untuk keperluan audit', Transaction::exists($betaTrx) && !Transaction::exists(999999));
check('cakupan: ketua regu tak bisa melompat regu lewat filter anggota', Transaction::search($alfa, ['member' => 4], 1)['pager']['total'] === 0);
check('cakupan: Anggota tidak bisa melihat anggota lain lewat filter', Transaction::search($anggota2, ['member' => 4], 1)['pager']['total'] === 0);
check('cakupan: Anggota tidak bisa mengintip transaksi orang lain via detail', Transaction::findScoped($anggota2, $betaTrx) === null);

// anggota pindah regu: transaksi lama tetap terlihat oleh pembuatnya, dan oleh ketua regu baru
$idMove = make($alfa, ['member_id' => 2, 'amount' => '13.000', 'kind' => 'SUKARELA']);
$pdo->exec('UPDATE member_team_assignments SET valid_to = CURDATE() WHERE member_id = 2 AND valid_to IS NULL');
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (2, 2, CURDATE())");
check('pindah regu: pembuat tetap melihat draft yang ia buat', in_array($idMove, $allIds($alfa), true));
check('pindah regu: ketua regu baru melihat transaksi anggotanya', in_array($idMove, $allIds($beta), true) && in_array($id1, $allIds($beta), true));
$pdo->exec('DELETE FROM member_team_assignments WHERE member_id = 2 AND team_id = 2');
$pdo->exec('UPDATE member_team_assignments SET valid_to = NULL WHERE member_id = 2');

// filter dan pencarian
check('filter: jenis simpanan', Transaction::search($head, ['kind' => 'SUKARELA'], 1)['pager']['total'] === (int) one("SELECT COUNT(*) FROM savings WHERE kind = 'SUKARELA'"));
check('filter: status DISETUJUI = 2 transaksi', Transaction::search($head, ['status' => 'DISETUJUI'], 1)['pager']['total'] === 2);
check('filter: bulan', Transaction::search($head, ['month' => $mo1], 1)['pager']['total'] === (int) one('SELECT COUNT(*) FROM transactions WHERE period_month_id = ?', [$mo1]));
check('filter: jenis transaksi PENCAIRAN_PINJAMAN kosong', Transaction::search($head, ['type' => 'PENCAIRAN_PINJAMAN'], 1)['pager']['total'] === 0);
check('filter: nilai status/jenis ngawur diabaikan, bukan galat', Transaction::search($head, ['status' => 'x', 'type' => 'x', 'kind' => 'x'], 1)['pager']['total'] === (int) one('SELECT COUNT(*) FROM transactions'));
check('cari: nomor dokumen', array_map('intval', array_column(Transaction::search($head, ['q' => $t1['doc_no']], 1)['rows'], 'id')) === [$id1]);
check('cari: nama anggota', Transaction::search($head, ['q' => 'anggota beta'], 1)['pager']['total'] === 1);
check('cari: "%" dan "_" dibaca harfiah', Transaction::search($head, ['q' => '%'], 1)['pager']['total'] === 0 && Transaction::search($head, ['q' => '_'], 1)['pager']['total'] === 0);
check('cari: injeksi SQL tidak berefek', Transaction::search($head, ['q' => "' OR '1'='1"], 1)['pager']['total'] === 0);
$totals = Transaction::search($head, [], 1)['totals'];
check('ringkasan: jumlah per status benar dan DISETUJUI = 125.000', $totals['DISETUJUI'] === ['n' => 2, 'sum' => 125000] && $totals['DIBATALKAN']['n'] === 3);
check('ringkasan: tidak ikut menyempit oleh filter status', Transaction::search($head, ['status' => 'DISETUJUI'], 1)['totals']['DRAFT']['n'] === $totals['DRAFT']['n']);
$bt = Transaction::search($beta, [], 1)['totals'];
check('ringkasan: ketua regu hanya menjumlah miliknya (1 draft 40.000, tidak ada yang disetujui)', $bt['DRAFT'] === ['n' => 1, 'sum' => 40000] && $bt['DISETUJUI']['n'] === 0 && $bt['MENUNGGU_VALIDASI']['n'] === 0);

// detail + riwayat
$detail = Transaction::findScoped($head, $id2);
check('detail: memuat anggota, regu, bulan, jenis, pembuat', $detail['member_name'] === 'Anggota Alfa' && $detail['team_name'] === 'Regu Alfa' && $detail['kind'] === 'SUKARELA' && $detail['creator_username'] === 'ketua.alfa' && $detail['month_date'] === $m0);
check('riwayat status: urut dan menyebut pelaku', array_column(Transaction::validations($id4), 'to_status') === ['MENUNGGU_VALIDASI', 'DIBATALKAN'] && Transaction::validations($id4)[1]['note'] === 'diajukan keliru');
check('bulan: pilihan pencatatan hanya sampai bulan berjalan & periode aktif', array_map(fn ($r) => $r['month_date'], Transaction::recordableMonths()) === [$m0, $m1, $m2]);
check('anggota: pilihan formulir hanya yang aktif di regu itu (urut nama)', array_column(Transaction::teamMembers(1), 'member_no') === ['AGT-002', 'AGT-005', 'AGT-001'] && array_column(Transaction::teamMembers(2), 'member_no') === ['AGT-004', 'AGT-003']);

// ======================= token formulir =======================
$tok = FormToken::issue();
check('token: format 32 heksa', preg_match('/^[a-f0-9]{32}$/', $tok) === 1);
check('token: dipakai sekali berhasil', FormToken::consume($tok) === true);
check('token: dipakai kedua kali ditolak', FormToken::consume($tok) === false);
check('token: kosong atau ngawur ditolak', FormToken::consume('') === false && FormToken::consume('bukan-token') === false);
$first = FormToken::issue();
for ($i = 0; $i < 35; $i++) {
    FormToken::issue();
}
check('token: jumlah tersimpan dibatasi (token paling lama gugur)', count($_SESSION['_form_tokens']) <= 30 && FormToken::consume($first) === false);

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
