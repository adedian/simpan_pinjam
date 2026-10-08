<?php
declare(strict_types=1);

/**
 * Tes transaksi pinjaman (Phase 7): hitungan bunga & jadwal, aturan bisnis, alur status, audit, saldo, cakupan.
 * MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\loan.php
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
use App\Services\LoanService;
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
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.1', 'HTTP_USER_AGENT' => 'tes-pinjaman']);
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
    $stmt = Database::pdo()->prepare('SELECT * FROM transactions WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: [];
}
function loanOf(int $trxId): array
{
    $stmt = Database::pdo()->prepare('SELECT * FROM loans WHERE transaction_id = ?');
    $stmt->execute([$trxId]);
    return $stmt->fetch() ?: [];
}
/** @return array<int,array<string,mixed>> */
function schedule(int $trxId): array
{
    $stmt = Database::pdo()->prepare('SELECT li.seq, li.amount_due, pm.month_date FROM loan_installments li JOIN loans l ON l.id = li.loan_id JOIN period_months pm ON pm.id = li.due_month_id WHERE l.transaction_id = ? ORDER BY li.seq');
    $stmt->execute([$trxId]);
    return $stmt->fetchAll();
}
function setting(string $key, string $value): void
{
    Database::pdo()->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
}
function approve(int $id): void
{
    $pdo = Database::pdo();
    if (trx($id)['status'] === 'DRAFT') {
        $pdo->exec("UPDATE transactions SET status='MENUNGGU_VALIDASI' WHERE id={$id}");
    }
    $pdo->exec("UPDATE transactions SET status='DISETUJUI' WHERE id={$id}");
}
function global_(): array
{
    return Database::pdo()->query('SELECT * FROM v_global_summary')->fetch();
}

// ---------------- fixture ----------------
$m0 = date('Y-m-01');
$m1 = date('Y-m-01', strtotime('-1 month', strtotime($m0)));
$m2 = date('Y-m-01', strtotime('-2 month', strtotime($m0)));
$later = [];
for ($i = 1; $i <= 4; $i++) {   // hanya 4 bulan setelah bulan ini: tenor 5 dari bulan ini melewati akhir periode
    $later[$i] = date('Y-m-01', strtotime("+{$i} month", strtotime($m0)));
}
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji', '{$m2}', '{$later[4]}', 'AKTIF')");
$pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1,'{$m2}'),(1,'{$m1}'),(1,'{$m0}'),(1,'{$later[1]}'),(1,'{$later[2]}'),(1,'{$later[3]}'),(1,'{$later[4]}')");
$monthId = [];
foreach ($pdo->query('SELECT id, month_date FROM period_months')->fetchAll() as $r) {
    $monthId[$r['month_date']] = (int) $r['id'];
}
[$mo2, $mo1, $mo0] = [$monthId[$m2], $monthId[$m1], $monthId[$m0]];
$today = date('Y-m-d');

foreach ([1 => 'Ketua Alfa', 2 => 'Anggota Alfa', 3 => 'Ketua Beta', 4 => 'Anggota Beta', 5 => 'Anggota Alfa Tiga', 6 => 'Anggota Nonaktif'] as $n => $name) {
    $pdo->prepare('INSERT INTO members (member_no, name, active_from, status) VALUES (?, ?, ?, ?)')->execute([sprintf('AGT-%03d', $n), $name, $m2, $n === 6 ? 'NONAKTIF' : 'AKTIF']);
}
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Alfa', 1), ('Regu Beta', 3)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'{$m2}'),(2,1,'{$m2}'),(3,2,'{$m2}'),(4,2,'{$m2}'),(5,1,'{$m2}'),(6,1,'{$m2}')");

$ids = [];
foreach ([['ketua.alfa', ['KETUA_REGU'], 'AGT-001'], ['ketua.beta', ['KETUA_REGU'], 'AGT-003'], ['kepala', ['HEAD'], null], ['periksa', ['PEMERIKSA'], null], ['anggota2', ['ANGGOTA'], 'AGT-002']] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$alfa = User::findActive($ids['ketua.alfa']);
$beta = User::findActive($ids['ketua.beta']);
$head = User::findActive($ids['kepala']);
$pemeriksa = User::findActive($ids['periksa']);
$anggota2  = User::findActive($ids['anggota2']);

function form(array $over = []): array
{
    global $mo0, $today;
    return $over + ['member_id' => 2, 'period_month_id' => $mo0, 'principal' => '5.000.000', 'tenor' => '3', 'trx_date' => $today, 'description' => ''];
}
function make(array $actor, array $over = [], bool $submit = false): int
{
    [$errors, $data] = LoanService::parse(form($over));
    if ($errors !== []) {
        throw new RuntimeException('fixture tidak sah: ' . json_encode($errors));
    }
    return LoanService::create(req(), $actor, $data, $submit);
}

// ======================= parse =======================
[$e, $d] = LoanService::parse(form());
check('parse: isian sah -> pokok 5.000.000, tenor 3, tanpa galat', $e === [] && $d['principal'] === 5000000 && $d['tenor'] === 3 && $d['description'] === null);
foreach (['' => 'kosong', 'abc' => 'huruf', '0' => 'nol', '-1000' => 'negatif', '1.5' => 'desimal', '1.000.000.001' => 'di atas batas'] as $bad => $why) {
    check("parse: pokok {$why} ditolak", isset(LoanService::parse(form(['principal' => (string) $bad]))[0]['principal']));
}
foreach (['' => 'kosong', '0' => 'nol', 'tiga' => 'huruf', '-2' => 'negatif', '2,5' => 'desimal', '123' => 'tiga digit'] as $bad => $why) {
    check("parse: tenor {$why} ditolak", isset(LoanService::parse(form(['tenor' => (string) $bad]))[0]['tenor']));
}
check('parse: anggota, bulan, tanggal wajib dan sah', isset(LoanService::parse(form(['member_id' => 0]))[0]['member_id']) && isset(LoanService::parse(form(['period_month_id' => 0]))[0]['period_month_id'])
    && isset(LoanService::parse(form(['trx_date' => '2026-02-30']))[0]['trx_date']) && isset(LoanService::parse(form(['trx_date' => '']))[0]['trx_date']));

// ======================= buat draft =======================
$glob0 = global_();
$id1 = make($alfa, ['description' => 'Modal usaha']);
$t1  = trx($id1);
$l1  = loanOf($id1);
check('buat: PENCAIRAN_PINJAMAN berstatus DRAFT, nomor PJM-<tahun>-000001', $t1['type'] === 'PENCAIRAN_PINJAMAN' && $t1['status'] === 'DRAFT' && $t1['doc_no'] === 'PJM-' . date('Y') . '-000001' && $t1['trx_no'] === 'TRX-' . date('Y') . '-000001');
check('buat: nominal transaksi = pokok; regu, pembuat, sumber benar', (int) $t1['amount'] === 5000000 && (int) $t1['team_id'] === 1 && (int) $t1['created_by'] === $alfa['id'] && $t1['source'] === 'APLIKASI');
check('buat: pinjaman memuat pokok, tenor, tarif 2.00, bunga 300.000 (5.000.000 x 2% x 3)', (int) $l1['principal'] === 5000000 && (int) $l1['tenor_months'] === 3 && $l1['rate_pct_month'] === '2.00' && (int) $l1['total_interest'] === 300000 && $l1['disbursed_on'] === $today);
$s1 = schedule($id1);
check('buat: 3 cicilan, jumlah persis pokok + bunga, terakhir menyerap sisa', count($s1) === 3 && array_sum(array_column($s1, 'amount_due')) === 5300000 && array_map('intval', array_column($s1, 'amount_due')) === [1766666, 1766666, 1766668]);
check('buat: jatuh tempo pertama = bulan SETELAH bulan pencairan, lalu berurutan', array_column($s1, 'month_date') === [$later[1], $later[2], $later[3]]);
check('buat: audit LOAN_CREATED memuat pokok, tenor, tarif, bunga', audits('LOAN_CREATED') === 1 && lastAudit('LOAN_CREATED')['after']['interest'] === 300000 && lastAudit('LOAN_CREATED')['after']['rate_hundredths'] === 200);
check('buat: draft tidak memengaruhi saldo, kas, maupun piutang', global_() === $glob0 && (int) one('SELECT COUNT(*) FROM v_loan_balances') === 0);
check('buat: draft belum punya riwayat validasi', (int) one('SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ?', [$id1]) === 0);

// ======================= aturan pencatatan =======================
$try = fn (array $over, ?array $actor = null) => violation(fn () => make($actor ?? $alfa, $over));
check('aturan: anggota regu lain ditolak, tanpa menyebut nama', isset($try(['member_id' => 4])['member_id']) && !str_contains($try(['member_id' => 4])['member_id'], 'Beta'));
check('aturan: anggota nonaktif ditolak', isset($try(['member_id' => 6])['member_id']));
check('aturan: Pemeriksa, Head, Anggota tidak boleh mencatat', isset($try([], $pemeriksa)['_form']) && isset($try([], $head)['_form']) && isset($try([], $anggota2)['_form']));
check('aturan: akun tanpa regu aktif ditolak', isset($try([], ['id' => $alfa['id'], 'username' => 'x', 'roles' => ['KETUA_REGU'], 'team_id' => null])['_form']));
check('aturan: plafon (15.000.000) ditegakkan, tepat di plafon diterima', isset($try(['principal' => '15.000.001'])['principal']) && violation(fn () => make($alfa, ['principal' => '15.000.000', 'tenor' => '1'])) === null);
check('aturan: tenor di atas maksimum (5) ditolak, di batas diterima', isset($try(['tenor' => '6'])['tenor']) && violation(fn () => make($alfa, ['principal' => '1.000.000', 'tenor' => '2', 'member_id' => 5])) === null);
check('aturan: tenor melewati akhir periode ditolak dengan sisa bulan disebut', str_contains((string) ($try(['tenor' => '5', 'member_id' => 5])['tenor'] ?? ''), 'melewati akhir periode') && str_contains($try(['tenor' => '5', 'member_id' => 5])['tenor'], 'tersisa 4 bulan'));
check('aturan: tenor tepat habis di akhir periode (4) diterima', violation(fn () => make($alfa, ['tenor' => '4', 'principal' => '2.000.000', 'member_id' => 5])) === null);
setting('loan_tenor_min', '2');
check('aturan: tenor minimum dari pengaturan ditegakkan', isset($try(['tenor' => '1', 'principal' => '700.000'])['tenor']));
setting('loan_tenor_min', '1');
setting('loan_max_amount', '0');
check('aturan: plafon 0 = tanpa batas plafon', violation(fn () => make($alfa, ['principal' => '40.000.000', 'tenor' => '1', 'member_id' => 5])) === null);
setting('loan_max_amount', '15000000');
check('aturan: bulan yang belum berjalan / sebelum anggota aktif ditolak', isset($try(['period_month_id' => $monthId[$later[1]]])['period_month_id']));
check('aturan: tanggal masa depan ditolak', isset($try(['trx_date' => date('Y-m-d', strtotime('+1 day'))])['trx_date']));
$dupErr = $try(['principal' => '5.000.000', 'tenor' => '3']);
check('duplikat: pinjaman identik diminta konfirmasi', isset($dupErr['duplicate']) && str_contains($dupErr['duplicate'], $t1['doc_no']));
check('duplikat: lolos bila dikonfirmasi; tenor beda bukan duplikat', violation(fn () => make($alfa, ['confirm_duplicate' => '1'])) === null && violation(fn () => make($alfa, ['tenor' => '2'])) === null);
check('aturan: pelanggaran tidak menghabiskan nomor (nomor terakhir = jumlah transaksi)', (int) one("SELECT last_value FROM number_sequences WHERE seq_key = ?", ['PJM-' . date('Y')]) === (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN'"));

// atomik: gagal setelah nomor + baris transaksi dibuat
$seq = (int) one("SELECT last_value FROM number_sequences WHERE seq_key = ?", ['PJM-' . date('Y')]);
$boom = false;
Database::pdo()->exec("CREATE TRIGGER trg_tes_gagal BEFORE INSERT ON loan_installments FOR EACH ROW BEGIN IF NEW.seq = 3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'gagal sengaja'; END IF; END");
try {
    $plan = ['member_id' => 2, 'period_month_id' => $mo0, 'principal' => 5000000, 'tenor' => 3, 'trx_date' => $today, 'description' => null, 'confirm_duplicate' => true];
    LoanService::create(req(), $alfa, $plan, false);
} catch (PDOException $e) {
    $boom = str_contains($e->getMessage(), 'gagal sengaja');
} finally {
    Database::pdo()->exec('DROP TRIGGER trg_tes_gagal');
}
check('atomik: kegagalan menulis jadwal membatalkan transaksi, pinjaman, dan nomor', $boom && (int) one("SELECT last_value FROM number_sequences WHERE seq_key = ?", ['PJM-' . date('Y')]) === $seq
    && (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN'") === $seq && (int) one('SELECT COUNT(*) FROM loans') === $seq);

// ======================= tarif disalin saat dibuat =======================
setting('interest_rate_pct_month', '2.50');
$idRate = make($alfa, ['member_id' => 5, 'principal' => '1.000.000', 'tenor' => '4']);
check('tarif: pinjaman baru memakai tarif pengaturan saat itu (2.50 -> bunga 100.000)', loanOf($idRate)['rate_pct_month'] === '2.50' && (int) loanOf($idRate)['total_interest'] === 100000);
check('tarif: pinjaman lama tidak berubah (2.00)', loanOf($id1)['rate_pct_month'] === '2.00' && (int) loanOf($id1)['total_interest'] === 300000);
setting('interest_rate_pct_month', '2.00');

// ======================= ubah draft =======================
$id3 = make($alfa, ['member_id' => 5, 'principal' => '2.000.000', 'tenor' => '2', 'description' => 'awal']);
$ver = trx($id3)['updated_at'];
sleep(1);   // updated_at berpresisi detik
[, $d3] = LoanService::parse(form(['member_id' => 5, 'principal' => '3.000.000', 'tenor' => '3', 'description' => 'awal']));
LoanService::update(req(), $alfa, $id3, $d3, $ver, false);
$l3 = loanOf($id3);
check('ubah: pokok, tenor, bunga, dan jadwal dihitung ulang (3 cicilan, jumlah 3.180.000)', (int) trx($id3)['amount'] === 3000000 && (int) $l3['total_interest'] === 180000 && count(schedule($id3)) === 3 && array_sum(array_column(schedule($id3), 'amount_due')) === 3180000);
check('ubah: tidak ada cicilan sisa dari jadwal lama', (int) one('SELECT COUNT(*) FROM loan_installments li JOIN loans l ON l.id = li.loan_id WHERE l.transaction_id = ?', [$id3]) === 3);
$au = lastAudit('LOAN_UPDATED');
check('ubah: audit memuat hanya field berubah (pokok, tenor, bunga)', $au['before'] === ['principal' => 2000000, 'tenor' => 2, 'interest' => 80000] && $au['after'] === ['principal' => 3000000, 'tenor' => 3, 'interest' => 180000]);
$n = audits('LOAN_UPDATED');
LoanService::update(req(), $alfa, $id3, $d3, trx($id3)['updated_at'], false);
check('ubah: tanpa perubahan tidak menulis audit', audits('LOAN_UPDATED') === $n);
check('ubah: versi basi ditolak', isset(violation(fn () => LoanService::update(req(), $alfa, $id3, $d3, $ver, false))['_form']));
check('ubah: bukan pembuat ditolak (ketua regu lain, Head)', isset(violation(fn () => LoanService::update(req(), $beta, $id3, $d3, trx($id3)['updated_at'], false))['_form']) && isset(violation(fn () => LoanService::update(req(), $head, $id3, $d3, trx($id3)['updated_at'], false))['_form']));
check('ubah: aturan berlaku saat ubah (plafon, anggota regu lain)', isset(violation(fn () => LoanService::update(req(), $alfa, $id3, LoanService::parse(form(['member_id' => 5, 'principal' => '99.000.000']))[1], trx($id3)['updated_at'], false))['principal'])
    && isset(violation(fn () => LoanService::update(req(), $alfa, $id3, LoanService::parse(form(['member_id' => 4]))[1], trx($id3)['updated_at'], false))['member_id']));
check('ubah: gagal tidak merusak jadwal lama (tetap 3 cicilan, 3.180.000)', count(schedule($id3)) === 3 && array_sum(array_column(schedule($id3), 'amount_due')) === 3180000);

// ======================= ajukan: kas dan batas =======================
check('ajukan: kas kosong -> ditolak dengan angka kas, status tetap DRAFT', (function () use ($alfa, $id1): bool {
    $e = violation(fn () => LoanService::submit(req(), $alfa, $id1, trx($id1)['updated_at']));
    return isset($e['principal']) && str_contains($e['principal'], 'Kas tersedia tidak cukup') && str_contains($e['principal'], 'Rp 0') && trx($id1)['status'] === 'DRAFT';
})());
// modal kas: simpanan 10.000.000 disetujui
$sv = SavingService::create(req(), $alfa, SavingService::parse(['member_id' => 2, 'period_month_id' => $mo0, 'kind' => 'SUKARELA', 'amount' => '10.000.000', 'trx_date' => $today])[1], false);
approve($sv);
check('ajukan: kas tersedia kini 10.000.000 (dari simpanan disetujui)', (int) global_()['kas_tersedia'] === 10000000);
check('ajukan: pokok > kas ditolak walau di bawah plafon (12.000.000 > 10.000.000)', (function () use ($alfa, $mo0, $today): bool {
    $big = make($alfa, ['member_id' => 5, 'principal' => '12.000.000', 'tenor' => '1']);
    $e = violation(fn () => LoanService::submit(req(), $alfa, $big, trx($big)['updated_at']));
    return isset($e['principal']) && trx($big)['status'] === 'DRAFT';
})());
$ver1 = trx($id1)['updated_at'];
LoanService::submit(req(), $alfa, $id1, $ver1);
check('ajukan: 5.000.000 <= kas -> MENUNGGU_VALIDASI + riwayat + audit', trx($id1)['status'] === 'MENUNGGU_VALIDASI' && (int) one('SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ?', [$id1]) === 1 && audits('LOAN_SUBMITTED') === 1);
check('ajukan: yang menunggu belum mengubah kas, piutang, atau saldo', (int) global_()['kas_tersedia'] === 10000000 && (int) global_()['piutang_beredar'] === 0);
check('ajukan: diajukan ulang ditolak, tanpa riwayat ganda', isset(violation(fn () => LoanService::submit(req(), $alfa, $id1, trx($id1)['updated_at']))['_form']) && (int) one('SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ?', [$id1]) === 1);
$e = violation(fn () => LoanService::submit(req(), $alfa, $id3, trx($id3)['updated_at']));   // 3.000.000: kas 10jt - dicadangkan 5jt = 5jt -> cukup
check('ajukan: pinjaman kedua 3.000.000 masih muat (kas 10jt - cadangan 5jt)', $e === null && trx($id3)['status'] === 'MENUNGGU_VALIDASI');
$idBig = make($alfa, ['member_id' => 5, 'principal' => '3.000.000', 'tenor' => '1']);
$e = violation(fn () => LoanService::submit(req(), $alfa, $idBig, trx($idBig)['updated_at']));
check('ajukan: pinjaman ketiga ditolak karena kas dicadangkan pinjaman lain (10 - 5 - 3 = 2jt)', isset($e['principal']) && str_contains($e['principal'], 'dicadangkan'));
setting('allow_negative_cash', '1');
check('ajukan: pengaturan "izinkan melebihi kas" melonggarkan pemeriksaan', violation(fn () => LoanService::submit(req(), $alfa, $idBig, trx($idBig)['updated_at'])) === null);
setting('allow_negative_cash', '0');
LoanService::cancel(req(), $alfa, $idBig, 'dilepas agar kas dicadangkan tidak berlebih', trx($idBig)['updated_at']);   // lepas 3jt cadangan
check('ajukan: versi basi dan bukan pembuat ditolak', isset(violation(fn () => LoanService::submit(req(), $alfa, $idRate, '2000-01-01 00:00:00'))['_form']) && isset(violation(fn () => LoanService::submit(req(), $beta, $idRate, trx($idRate)['updated_at']))['_form']) && isset(violation(fn () => LoanService::submit(req(), $head, $idRate, trx($idRate)['updated_at']))['_form']));

// tarif berubah sejak draft
setting('interest_rate_pct_month', '3.00');
$e = violation(fn () => LoanService::submit(req(), $alfa, $idRate, trx($idRate)['updated_at']));
check('ajukan: tarif berubah sejak draft -> ditolak dengan penjelasan, draft utuh', isset($e['_form']) && str_contains($e['_form'], 'Tarif bunga berubah dari 2,50% menjadi 3,00%') && trx($idRate)['status'] === 'DRAFT' && loanOf($idRate)['rate_pct_month'] === '2.50');
[, $dRate] = LoanService::parse(form(['member_id' => 5, 'principal' => '1.000.000', 'tenor' => '4']));
LoanService::update(req(), $alfa, $idRate, $dRate, trx($idRate)['updated_at'], false);
check('ubah: simpan ulang menghitung dengan tarif baru (3.00 -> bunga 120.000)', loanOf($idRate)['rate_pct_month'] === '3.00' && (int) loanOf($idRate)['total_interest'] === 120000 && array_sum(array_column(schedule($idRate), 'amount_due')) === 1120000);
LoanService::submit(req(), $alfa, $idRate, trx($idRate)['updated_at']);
check('ajukan: setelah dihitung ulang berhasil', trx($idRate)['status'] === 'MENUNGGU_VALIDASI');
setting('interest_rate_pct_month', '2.00');

// ======================= batas pinjaman aktif =======================
setting('loan_max_active_per_member', '1');
$idA = make($alfa, ['member_id' => 2, 'principal' => '500.000', 'tenor' => '1']);   // anggota 2 sudah punya $id1 (menunggu) dan $id3? (anggota 5)
$e = violation(fn () => LoanService::submit(req(), $alfa, $idA, trx($idA)['updated_at']));
check('batas aktif: anggota dengan pinjaman menunggu validasi tidak bisa mengajukan lagi (batas 1)', isset($e['_form']) && str_contains($e['_form'], 'batas 1 pinjaman aktif'));
check('batas aktif: anggota lain tidak terpengaruh... (anggota 1 belum punya)', violation(fn () => LoanService::submit(req(), $alfa, make($alfa, ['member_id' => 1, 'principal' => '500.000', 'tenor' => '1']), trx((int) one('SELECT MAX(id) FROM transactions'))['updated_at'])) === null);
setting('loan_max_active_per_member', '0');
check('batas aktif: 0 = tanpa batas', violation(fn () => LoanService::submit(req(), $alfa, $idA, trx($idA)['updated_at'])) === null);

// ======================= batalkan =======================
$idC = make($alfa, ['member_id' => 5, 'principal' => '600.000', 'tenor' => '1']);
check('batal: alasan wajib dan bukan pembuat ditolak', isset(violation(fn () => LoanService::cancel(req(), $alfa, $idC, ' ', trx($idC)['updated_at']))['note']) && isset(violation(fn () => LoanService::cancel(req(), $beta, $idC, 'iseng', trx($idC)['updated_at']))['_form']));
LoanService::cancel(req(), $alfa, $idC, 'Batal dipinjam', trx($idC)['updated_at']);
check('batal draft: DIBATALKAN, alasan di riwayat dan audit, baris dan jadwal tetap ada', trx($idC)['status'] === 'DIBATALKAN' && one('SELECT note FROM transaction_validations WHERE transaction_id = ?', [$idC]) === 'Batal dipinjam' && count(schedule($idC)) === 1 && lastAudit('LOAN_CANCELLED')['after']['note'] === 'Batal dipinjam');
LoanService::cancel(req(), $alfa, $id3, 'diajukan keliru', trx($id3)['updated_at']);
check('batal: pinjaman MENUNGGU_VALIDASI bisa dibatalkan pembuatnya', trx($id3)['status'] === 'DIBATALKAN');
check('batal: yang dibatalkan tidak bisa diubah, diajukan, atau dibatalkan lagi', isset(violation(fn () => LoanService::submit(req(), $alfa, $id3, trx($id3)['updated_at']))['_form']) && isset(violation(fn () => LoanService::cancel(req(), $alfa, $id3, 'lagi', trx($id3)['updated_at']))['_form'])
    && isset(violation(fn () => LoanService::update(req(), $alfa, $id3, $d3, trx($id3)['updated_at'], false))['_form']));
$reserved = (int) one("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN' AND status = 'MENUNGGU_VALIDASI'");
$avail    = (int) global_()['kas_tersedia'] - $reserved;
$fmt      = fn (int $n): string => number_format($n, 0, ',', '.');
$over     = make($alfa, ['member_id' => 5, 'principal' => $fmt($avail + 1), 'tenor' => '1', 'confirm_duplicate' => '1']);
$exact    = make($alfa, ['member_id' => 5, 'principal' => $fmt($avail), 'tenor' => '1', 'confirm_duplicate' => '1']);
check('kas: batas cadangan TEPAT sampai rupiah terakhir (kas - cadangan = ' . $avail . ')', $avail > 0 && isset(violation(fn () => LoanService::submit(req(), $alfa, $over, trx($over)['updated_at']))['principal'])
    && violation(fn () => LoanService::submit(req(), $alfa, $exact, trx($exact)['updated_at'])) === null);
check('kas: pinjaman yang dibatalkan/ditolak tidak lagi mencadangkan kas', (int) one("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN' AND status = 'MENUNGGU_VALIDASI'") === $reserved + $avail);

// ======================= silang jenis transaksi =======================
check('silang: SavingService tidak bisa menyentuh pinjaman, LoanService tidak bisa menyentuh simpanan', isset(violation(fn () => SavingService::submit(req(), $alfa, $id1, trx($id1)['updated_at']))['_form'])
    && isset(violation(fn () => SavingService::cancel(req(), $alfa, $id1, 'x', trx($id1)['updated_at']))['_form'])
    && isset(violation(fn () => LoanService::cancel(req(), $alfa, $sv, 'x', trx($sv)['updated_at']))['_form'])
    && isset(violation(fn () => LoanService::submit(req(), $alfa, $sv, trx($sv)['updated_at']))['_form']));

// ======================= setelah DISETUJUI: saldo, piutang, integritas =======================
$glob = global_();
approve($id1);   // 5.000.000 tenor 3 -> bunga 300.000
$g = global_();
check('disetujui: kas turun sebesar pokok (10.000.000 -> 5.000.000)', (int) $g['kas_tersedia'] === 5000000);
check('disetujui: piutang = pokok + bunga (5.300.000), bunga dibukukan 300.000', (int) $g['piutang_beredar'] === 5300000 && (int) $g['bunga_dibukukan'] === 300000);
check('disetujui: invarian kas + piutang = tabungan + bunga - biaya (selisih 0)', (int) $g['selisih'] === 0 && (int) $g['saldo_tabungan'] === (int) $glob['saldo_tabungan']);
check('disetujui: pemeriksa integritas (jadwal = pokok+bunga, bunga = rumus) bersih', (int) one('SELECT COUNT(*) FROM v_integrity_issues') === 0);
$vb = $pdo->query("SELECT * FROM v_loan_balances WHERE transaction_id = {$id1}")->fetch();
check('disetujui: v_loan_balances AKTIF, sisa 5.300.000, loan_no = nomor dokumen', $vb['loan_status'] === 'AKTIF' && (int) $vb['outstanding'] === 5300000 && $vb['loan_no'] === $t1['doc_no']);
$detail = Transaction::loanDetail($id1);
check('loanDetail: pinjaman, 3 cicilan dengan sisa, dan saldo setelah disetujui', (int) $detail['loan']['total_interest'] === 300000 && count($detail['installments']) === 3 && (int) $detail['installments'][0]['remaining_amount'] === 1766666 && (int) $detail['balance']['outstanding'] === 5300000);
check('loanDetail: draft tidak punya saldo, jadwal tetap ada', Transaction::loanDetail($idRate)['balance'] === null && count(Transaction::loanDetail($idRate)['installments']) === 4);
check('loanDetail: transaksi non-pinjaman -> kosong', Transaction::loanDetail($sv)['loan'] === null);

// lunas tidak dihitung sebagai pinjaman aktif (singkirkan dulu pinjaman anggota 2 yang masih menunggu agar yang diuji hanya status pinjaman yang sudah disetujui)
LoanService::cancel(req(), $alfa, $idA, 'dibersihkan untuk uji batas', trx($idA)['updated_at']);
setting('loan_max_active_per_member', '1');
$e = violation(fn () => LoanService::submit(req(), $alfa, make($alfa, ['member_id' => 2, 'principal' => '400.000', 'tenor' => '1', 'trx_date' => $today]), (string) one('SELECT updated_at FROM transactions WHERE id = (SELECT MAX(id) FROM transactions)')));
check('batas aktif: pinjaman AKTIF (disetujui, belum lunas) dihitung', isset($e['_form']) && str_contains($e['_form'], 'batas 1 pinjaman aktif'));
// lunasi: angsuran 5.300.000 teralokasi ke 3 cicilan
$pdo->exec("INSERT INTO transactions (trx_no, doc_no, type, member_id, period_month_id, trx_date, amount) VALUES ('T-A1','A-1','ANGSURAN',2,{$mo0},'{$today}',5300000)");
$angId = (int) $pdo->lastInsertId();
foreach (Transaction::loanDetail($id1)['installments'] as $in) {
    $iid = (int) one('SELECT li.id FROM loan_installments li JOIN loans l ON l.id = li.loan_id WHERE l.transaction_id = ? AND li.seq = ?', [$id1, $in['seq']]);
    $pdo->exec("INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES ({$angId}, {$iid}, {$in['amount_due']})");
}
approve($angId);
check('lunas: status LUNAS, sisa 0, invarian tetap 0', one("SELECT loan_status FROM v_loan_balances WHERE transaction_id = ?", [$id1]) === 'LUNAS' && (int) global_()['selisih'] === 0 && (int) one('SELECT COUNT(*) FROM v_integrity_issues') === 0);
$idAfter = make($alfa, ['member_id' => 2, 'principal' => '700.000', 'tenor' => '1', 'confirm_duplicate' => '1']);
check('batas aktif: pinjaman LUNAS tidak dihitung lagi', violation(fn () => LoanService::submit(req(), $alfa, $idAfter, trx($idAfter)['updated_at'])) === null);
setting('loan_max_active_per_member', '0');

// ======================= cakupan data =======================
$betaLoan = make($beta, ['member_id' => 4, 'principal' => '800.000', 'tenor' => '1']);
$loanIds = fn (array $user, array $f = []) => array_map('intval', array_column(Transaction::search($user, $f + ['type' => 'PENCAIRAN_PINJAMAN'], 1)['rows'], 'id'));
check('cakupan: ketua Beta hanya melihat pinjaman regunya', $loanIds($beta) === [$betaLoan]);
check('cakupan: ketua Alfa tidak melihat pinjaman regu Beta; Head melihat semua', !in_array($betaLoan, $loanIds($alfa), true) && in_array($betaLoan, $loanIds($head), true) && in_array($id1, $loanIds($head), true));
check('cakupan: Anggota hanya pinjaman miliknya', $loanIds($anggota2) !== [] && count(array_filter($loanIds($anggota2), fn ($i) => (int) trx($i)['member_id'] !== 2)) === 0);
$row = array_values(array_filter(Transaction::search($head, ['type' => 'PENCAIRAN_PINJAMAN'], 1)['rows'], fn ($r) => (int) $r['id'] === $id1))[0];
check('daftar: baris pinjaman membawa tenor, tarif, sisa, status pinjaman', (int) $row['tenor_months'] === 3 && $row['rate_pct_month'] === '2.00' && (int) $row['outstanding'] === 0 && $row['loan_status'] === 'LUNAS');
$rowDraft = array_values(array_filter(Transaction::search($head, ['type' => 'PENCAIRAN_PINJAMAN'], 1)['rows'], fn ($r) => (int) $r['id'] === $betaLoan))[0];
check('daftar: pinjaman yang belum disetujui tidak punya sisa tagihan', $rowDraft['outstanding'] === null);
check('daftar: jumlah baris tidak berlipat oleh gabungan pinjaman/saldo', Transaction::search($head, ['type' => 'PENCAIRAN_PINJAMAN'], 1)['pager']['total'] === count($loanIds($head)) && count($loanIds($head)) === (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'PENCAIRAN_PINJAMAN'"));

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
