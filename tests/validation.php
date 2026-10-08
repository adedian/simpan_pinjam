<?php
declare(strict_types=1);

/**
 * Tes validasi transaksi (Phase 9): pemisahan tugas, transaksi Head hanya Pemeriksa, pemeriksaan kas atomik,
 * pemeriksaan keadaan terkini saat setuju, tolak, atomisitas, antrean dan riwayat.
 * MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\validation.php
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
use App\Models\Validation;
use App\Services\InstallmentService;
use App\Services\LoanService;
use App\Services\Migrator;
use App\Services\RuleViolation;
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
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.1', 'HTTP_USER_AGENT' => 'tes-validasi']);
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
function ver(int $id): string
{
    return (string) trx($id)['updated_at'];
}
function setting(string $key, string $value): void
{
    Database::pdo()->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
}
function global_(): array
{
    return Database::pdo()->query('SELECT * FROM v_global_summary')->fetch();
}
function approveSql(int $id): void
{
    $pdo = Database::pdo();
    if (trx($id)['status'] === 'DRAFT') {
        $pdo->exec("UPDATE transactions SET status='MENUNGGU_VALIDASI' WHERE id={$id}");
    }
    $pdo->exec("UPDATE transactions SET status='DISETUJUI' WHERE id={$id}");
}
/** Sisipkan transaksi mentah berstatus MENUNGGU_VALIDASI (untuk kasus yang tak punya layar pencatatan). */
function rawPending(string $type, ?int $memberId, ?int $teamId, ?int $createdBy, int $amount, ?callable $prep = null): int
{
    static $n = 0;
    global $mid0;
    $n++;
    $pdo = Database::pdo();
    $stmt = $pdo->prepare('INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute(["T-RAW-{$n}", "RAW-{$n}", $type, $memberId, $teamId, $mid0, date('Y-m-d'), $amount, $createdBy]);
    $id = (int) $pdo->lastInsertId();
    if ($type === 'SIMPANAN') {
        $pdo->exec("INSERT INTO savings (transaction_id, kind) VALUES ({$id}, 'SUKARELA')");
    }
    if ($prep !== null) {
        $prep($id);
    }
    $pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI', status_changed_at = NOW() WHERE id = {$id}");
    return $id;
}

// ---------------- fixture ----------------
$m1 = date('Y-m-01', strtotime('-1 month', strtotime(date('Y-m-01'))));
$m0 = date('Y-m-01');
$later = [date('Y-m-01', strtotime('+1 month', strtotime($m0))), date('Y-m-01', strtotime('+2 month', strtotime($m0))), date('Y-m-01', strtotime('+3 month', strtotime($m0)))];
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji', '{$m1}', '{$later[2]}', 'AKTIF')");
foreach ([$m1, $m0, ...$later] as $d) {
    $pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1, '{$d}')");
}
$mid1 = (int) one('SELECT id FROM period_months WHERE month_date = ?', [$m1]);
$mid0 = (int) one('SELECT id FROM period_months WHERE month_date = ?', [$m0]);
$today = date('Y-m-d');

foreach ([1 => 'Ketua Alfa (Head)', 2 => 'Anggota Alfa', 3 => 'Ketua Beta', 4 => 'Anggota Beta', 5 => 'Anggota Pemeriksa', 6 => 'Anggota Nonaktif'] as $n => $name) {
    $pdo->prepare('INSERT INTO members (member_no, name, active_from, status) VALUES (?, ?, ?, ?)')->execute([sprintf('AGT-%03d', $n), $name, $m1, 'AKTIF']);
}
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Alfa', 1), ('Regu Beta', 3)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'{$m1}'),(2,1,'{$m1}'),(3,2,'{$m1}'),(4,2,'{$m1}'),(5,2,'{$m1}'),(6,2,'{$m1}')");

$ids = [];
foreach ([['purwati', ['HEAD', 'KETUA_REGU'], 'AGT-001'], ['ketua.beta', ['KETUA_REGU'], 'AGT-003'], ['kepala2', ['HEAD'], null], ['periksa', ['PEMERIKSA'], null], ['anggota4', ['ANGGOTA'], 'AGT-004']] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$ids['periksa.m'] = UserService::create('periksa.m', 'Periksa Anggota', ['PEMERIKSA'], null, 'Contoh-Uji-2026')['id'];
$pdo->exec("UPDATE users SET member_id = 5 WHERE username = 'periksa.m'");   // Pemeriksa yang juga anggota (member 5)
$purwati = User::findActive($ids['purwati']);
$beta    = User::findActive($ids['ketua.beta']);
$head    = User::findActive($ids['kepala2']);
$periksa = User::findActive($ids['periksa']);
$periksaM = User::findActive($ids['periksa.m']);
$anggota4 = User::findActive($ids['anggota4']);

function saving(array $actor, int $member, string $amount, bool $submit = true): int
{
    global $mid0, $today;
    [$e, $d] = SavingService::parse(['member_id' => $member, 'period_month_id' => $mid0, 'kind' => 'SUKARELA', 'amount' => $amount, 'trx_date' => $today, 'confirm_duplicate' => '1']);
    if ($e !== []) {
        throw new RuntimeException(json_encode($e));
    }
    return SavingService::create(req(), $actor, $d, $submit);
}
function loanDraft(array $actor, int $member, string $principal, string $tenor, bool $submit = true): int
{
    global $mid0, $today;
    [$e, $d] = LoanService::parse(['member_id' => $member, 'period_month_id' => $mid0, 'principal' => $principal, 'tenor' => $tenor, 'trx_date' => $today, 'confirm_duplicate' => '1']);
    if ($e !== []) {
        throw new RuntimeException(json_encode($e));
    }
    return LoanService::create(req(), $actor, $d, $submit);
}

// ======================= kewenangan (murni) =======================
$t = fn (array $o = []) => $o + ['id' => 1, 'created_by' => 99, 'member_id' => 4, 'team_id' => 2];
$u = fn (array $roles, ?int $member = null, int $id = 50) => ['id' => $id, 'roles' => $roles, 'member_id' => $member];
check('assess: Head boleh memvalidasi transaksi biasa', ValidationService::assess($u(['HEAD']), $t(), false, 3)['eligible'] === true);
check('assess: Pemeriksa boleh memvalidasi transaksi biasa dan transaksi Head', ValidationService::assess($u(['PEMERIKSA']), $t(), false, 3)['eligible'] === true && ValidationService::assess($u(['PEMERIKSA']), $t(), true, 3)['eligible'] === true);
check('assess: Ketua Regu dan Anggota tidak berhak memvalidasi', ValidationService::assess($u(['KETUA_REGU']), $t(), false, null)['eligible'] === false && ValidationService::assess($u(['ANGGOTA'], 4), $t(), false, null)['eligible'] === false);
check('assess: pembuat tidak boleh memvalidasi transaksinya sendiri', ValidationService::assess($u(['HEAD'], null, 99), $t(), false, null)['eligible'] === false && str_contains((string) ValidationService::assess($u(['HEAD'], null, 99), $t(), false, null)['reason'], 'pembuat'));
check('assess: tidak boleh memvalidasi transaksi atas nama sendiri', ValidationService::assess($u(['PEMERIKSA'], 4), $t(), false, 3)['eligible'] === false && str_contains((string) ValidationService::assess($u(['PEMERIKSA'], 4), $t(), false, 3)['reason'], 'atas nama Anda'));
check('assess: tidak boleh memvalidasi transaksi regu yang dipimpinnya', ValidationService::assess($u(['PEMERIKSA'], 3), $t(), false, 3)['eligible'] === false && str_contains((string) ValidationService::assess($u(['PEMERIKSA'], 3), $t(), false, 3)['reason'], 'regu yang Anda pimpin'));
$headOnly = ValidationService::assess($u(['HEAD']), $t(), true, 3);
check('assess: transaksi terkait Head ditolak untuk Head, dengan alasan Pemeriksa', $headOnly['eligible'] === false && $headOnly['head_related'] === true && str_contains((string) $headOnly['reason'], 'Pemeriksa'));
check('assess: transaksi impor (tanpa pembuat) tidak dianggap dibuat siapa pun', ValidationService::assess($u(['HEAD']), $t(['created_by' => null]), false, null)['eligible'] === true);
check('assess: tanpa peran sama sekali ditolak', ValidationService::assess($u([]), $t(), false, null)['eligible'] === false);

// ======================= kas awal: simpanan Beta disetujui Head =======================
$sv1 = saving($beta, 4, '20.000.000');
check('antrean: simpanan yang diajukan berstatus MENUNGGU_VALIDASI dan belum masuk saldo', trx($sv1)['status'] === 'MENUNGGU_VALIDASI' && (int) global_()['kas_tersedia'] === 0);
check('setujui: tanpa hak validasi (Ketua Regu pembuat) ditolak', isset(violation(fn () => ValidationService::approve(req(), $beta, $sv1, ver($sv1), null))['_form']) && trx($sv1)['status'] === 'MENUNGGU_VALIDASI');
check('setujui: Anggota tidak berhak', isset(violation(fn () => ValidationService::approve(req(), $anggota4, $sv1, ver($sv1), null))['_form']) && trx($sv1)['status'] === 'MENUNGGU_VALIDASI');
check('setujui: versi basi ditolak', isset(violation(fn () => ValidationService::approve(req(), $head, $sv1, '2000-01-01 00:00:00', null))['_form']));
ValidationService::approve(req(), $head, $sv1, ver($sv1), '  Sudah dicek   dengan setoran ');
$tv = trx($sv1);
check('setujui: status DISETUJUI, saldo anggota dan kas naik 20.000.000', $tv['status'] === 'DISETUJUI' && (int) one('SELECT savings_balance FROM v_member_savings WHERE member_id = 4') === 20000000 && (int) global_()['kas_tersedia'] === 20000000 && (int) global_()['selisih'] === 0);
$vrow = Database::select('SELECT * FROM transaction_validations WHERE transaction_id = ? ORDER BY id DESC LIMIT 1', [$sv1])[0];
check('setujui: riwayat memuat validator, MENUNGGU -> DISETUJUI, catatan dirapikan', (int) $vrow['actor_user_id'] === $head['id'] && $vrow['from_status'] === 'MENUNGGU_VALIDASI' && $vrow['to_status'] === 'DISETUJUI' && $vrow['note'] === 'Sudah dicek dengan setoran');
check('setujui: audit TRX_APPROVED mencatat validator dan nomor dokumen', audits('TRX_APPROVED') === 1 && lastAudit('TRX_APPROVED')['username'] === 'kepala2' && lastAudit('TRX_APPROVED')['reference_no'] === $tv['doc_no']);
check('setujui: yang sudah disetujui tidak bisa disetujui/ditolak lagi, dan pembuat tidak bisa membatalkan', isset(violation(fn () => ValidationService::approve(req(), $periksa, $sv1, ver($sv1), null))['_form']) && isset(violation(fn () => ValidationService::reject(req(), $periksa, $sv1, ver($sv1), 'x'))['_form'])
    && isset(violation(fn () => SavingService::cancel(req(), $beta, $sv1, 'x', ver($sv1)))['_form']));
check('setujui: transaksi DRAFT atau tidak ada tidak bisa divalidasi', isset(violation(fn () => ValidationService::approve(req(), $head, saving($beta, 4, '1.000', false), '', null))['_form']) && isset(violation(fn () => ValidationService::approve(req(), $head, 99999, 'x', null))['_form']));
check('setujui: catatan lebih dari 200 karakter ditolak', isset(violation(fn () => ValidationService::approve(req(), $head, saving($beta, 4, '2.000'), '', str_repeat('x', 201)))['note']));

// ======================= transaksi terkait Head hanya Pemeriksa =======================
$pw = saving($purwati, 2, '300.000');   // dibuat Head (Purwati), regu Head, anggota Alfa
check('Head: transaksi buatan Head (regu Head) tidak bisa divalidasi Head lain', isset(violation(fn () => ValidationService::approve(req(), $head, $pw, ver($pw), null))['_form']) && str_contains((string) violation(fn () => ValidationService::approve(req(), $head, $pw, ver($pw), null))['_form'], 'Pemeriksa') && trx($pw)['status'] === 'MENUNGGU_VALIDASI');
check('Head: Purwati tidak bisa memvalidasi transaksinya sendiri walau Head', isset(violation(fn () => ValidationService::approve(req(), $purwati, $pw, ver($pw), null))['_form']));
check('Head: penolakan oleh Head lain juga ditolak (aturan sama untuk tolak)', isset(violation(fn () => ValidationService::reject(req(), $head, $pw, ver($pw), 'x'))['_form']));
check('Head: penilaian untuk tampilan menandai head_related dan alasan', ValidationService::assessFor($head, trx($pw))['eligible'] === false && ValidationService::assessFor($head, trx($pw))['head_related'] === true && ValidationService::assessFor($periksa, trx($pw))['eligible'] === true);
$byMember = rawPending('SIMPANAN', 1, 2, $beta['id'], 100000);    // atas nama anggota tertaut Head (member 1), dibuat orang lain
$byTeam   = rawPending('SIMPANAN', 2, 1, $beta['id'], 100000);    // regu yang dipimpin anggota tertaut Head (team 1), dibuat orang lain
$plain    = rawPending('SIMPANAN', 4, 2, $beta['id'], 100000);    // tidak ada kaitan Head
check('Head: atas nama anggota tertaut Head = hanya Pemeriksa', ValidationService::assessFor($head, trx($byMember))['head_related'] === true && ValidationService::assessFor($head, trx($byMember))['eligible'] === false);
check('Head: regu yang dipimpin Head = hanya Pemeriksa', ValidationService::assessFor($head, trx($byTeam))['head_related'] === true && ValidationService::assessFor($head, trx($byTeam))['eligible'] === false);
check('Head: transaksi tanpa kaitan Head bisa divalidasi Head', ValidationService::assessFor($head, trx($plain))['head_related'] === false && ValidationService::assessFor($head, trx($plain))['eligible'] === true);
ValidationService::approve(req(), $periksa, $pw, ver($pw), null);
ValidationService::approve(req(), $periksa, $byMember, ver($byMember), null);
ValidationService::approve(req(), $periksa, $byTeam, ver($byTeam), null);
check('Pemeriksa: boleh menyetujui ketiganya (dibuat Head, atas nama Head, regu Head)', trx($pw)['status'] === 'DISETUJUI' && trx($byMember)['status'] === 'DISETUJUI' && trx($byTeam)['status'] === 'DISETUJUI');
$ownPm = rawPending('SIMPANAN', 5, 2, $beta['id'], 50000);   // atas nama Pemeriksa-anggota (member 5)
check('Pemeriksa yang juga anggota: tidak boleh memvalidasi transaksi atas nama sendiri, tetapi Pemeriksa lain boleh', isset(violation(fn () => ValidationService::approve(req(), $periksaM, $ownPm, ver($ownPm), null))['_form']) && violation(fn () => ValidationService::approve(req(), $periksa, $ownPm, ver($ownPm), null)) === null);
check('Pemeriksa: ada akun Pemeriksa aktif; nonaktif semua -> tidak ada', ValidationService::hasActiveChecker() === true && (function () use ($pdo): bool {
    $pdo->exec("UPDATE users SET is_active = 0 WHERE username IN ('periksa','periksa.m')");
    $none = ValidationService::hasActiveChecker() === false;
    $pdo->exec("UPDATE users SET is_active = 1 WHERE username IN ('periksa','periksa.m')");
    return $none;
})());

// ======================= tolak =======================
$rj = saving($beta, 4, '777.000');
$kas = (int) global_()['kas_tersedia'];
check('tolak: alasan wajib dan maksimal 200 karakter', isset(violation(fn () => ValidationService::reject(req(), $head, $rj, ver($rj), '  '))['note']) && isset(violation(fn () => ValidationService::reject(req(), $head, $rj, ver($rj), str_repeat('x', 201)))['note']) && trx($rj)['status'] === 'MENUNGGU_VALIDASI');
ValidationService::reject(req(), $head, $rj, ver($rj), 'Nominal tidak sesuai bukti');
check('tolak: DITOLAK, riwayat dan audit TRX_REJECTED memuat alasan, saldo tidak berubah', trx($rj)['status'] === 'DITOLAK' && (int) global_()['kas_tersedia'] === $kas && lastAudit('TRX_REJECTED')['after']['note'] === 'Nominal tidak sesuai bukti'
    && one('SELECT note FROM transaction_validations WHERE transaction_id = ? ORDER BY id DESC LIMIT 1', [$rj]) === 'Nominal tidak sesuai bukti');
check('tolak: final (tidak bisa disetujui, diajukan lagi, dibatalkan, atau diubah pembuat)', isset(violation(fn () => ValidationService::approve(req(), $head, $rj, ver($rj), null))['_form']) && isset(violation(fn () => SavingService::submit(req(), $beta, $rj, ver($rj)))['_form'])
    && isset(violation(fn () => SavingService::cancel(req(), $beta, $rj, 'x', ver($rj)))['_form']));
$cx = saving($beta, 4, '88.000');
SavingService::cancel(req(), $beta, $cx, 'dibatalkan pembuat', ver($cx));
check('balapan: pembuat membatalkan lebih dulu -> validasi ditolak karena sudah dibatalkan', isset(violation(fn () => ValidationService::approve(req(), $head, $cx, '', null))['_form']) && str_contains((string) violation(fn () => ValidationService::approve(req(), $head, $cx, ver($cx), null))['_form'], 'dibatalkan'));

// ======================= keadaan terkini saat setuju =======================
$sNon = saving($beta, 6, '60.000');
$pdo->exec("UPDATE members SET status = 'NONAKTIF' WHERE id = 6");
$e = violation(fn () => ValidationService::approve(req(), $head, $sNon, ver($sNon), null));
check('setuju: anggota dinonaktifkan sejak diajukan -> tidak bisa disetujui, tetap menunggu', isset($e['_form']) && str_contains($e['_form'], 'tidak aktif') && trx($sNon)['status'] === 'MENUNGGU_VALIDASI');
ValidationService::reject(req(), $head, $sNon, ver($sNon), 'Anggota nonaktif');
check('setuju: tetapi masih bisa ditolak', trx($sNon)['status'] === 'DITOLAK');

// ======================= kas: pencairan <= kas tersedia (atomik) =======================
setting('loan_max_amount', '0');
$biaya = rawPending('BIAYA', null, null, $beta['id'], 5000000);
ValidationService::approve(req(), $head, $biaya, ver($biaya), 'Biaya operasional');
$kas1 = (int) global_()['kas_tersedia'];
check('kas: biaya 5.000.000 disetujui mengurangi kas (20.550.000 -> 15.550.000), selisih 0', $kas1 === 15550000 && (int) one("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='BIAYA' AND status='DISETUJUI'") === 5000000 && (int) global_()['selisih'] === 0);
$pad = max(0, $kas1 - 15000000);
if ($pad > 0) {   // jadikan kas tepat 15.000.000 dengan biaya penyeimbang
    $b2 = rawPending('BIAYA', null, null, $beta['id'], $pad);
    ValidationService::approve(req(), $head, $b2, ver($b2), 'penyeimbang');
}
check('kas: kas tepat 15.000.000 sebelum pencairan', (int) global_()['kas_tersedia'] === 15000000);
$loanA = loanDraft($beta, 4, '9.000.000', '3');
setting('allow_negative_cash', '1');   // pengajuan kedua akan ditolak dini (kas dicadangkan); longgarkan HANYA saat mengajukan, bukan saat menyetujui
$loanB = loanDraft($beta, 3, '9.000.000', '3');
setting('allow_negative_cash', '0');
check('kas: kedua pinjaman 9.000.000 sama-sama menunggu validasi', trx($loanA)['status'] === 'MENUNGGU_VALIDASI' && trx($loanB)['status'] === 'MENUNGGU_VALIDASI');
ValidationService::approve(req(), $head, $loanA, ver($loanA), null);
check('kas: pencairan pertama disetujui; kas 6.000.000, piutang = pokok + bunga, selisih 0', (int) global_()['kas_tersedia'] === 6000000 && (int) global_()['piutang_beredar'] === 9540000 && (int) global_()['selisih'] === 0 && trx($loanA)['status'] === 'DISETUJUI');
$e = violation(fn () => ValidationService::approve(req(), $head, $loanB, ver($loanB), null));
check('kas: pencairan kedua ditolak karena kas 6.000.000 < 9.000.000, dengan angka, tetap menunggu', isset($e['_form']) && str_contains($e['_form'], 'Kas tersedia tidak cukup') && str_contains($e['_form'], 'Rp 6.000.000') && trx($loanB)['status'] === 'MENUNGGU_VALIDASI' && (int) global_()['kas_tersedia'] === 6000000);
check('kas: tidak ada transaksi yang sempat tercatat setengah jalan (riwayat dan audit tidak bertambah)', (int) one('SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ? AND to_status = ?', [$loanB, 'DISETUJUI']) === 0);
// kas tepat sama dengan pencairan: boleh (<=)
setting('allow_negative_cash', '1');
$exactLoan = loanDraft($beta, 4, '6.000.000', '2');
setting('allow_negative_cash', '0');
ValidationService::approve(req(), $head, $exactLoan, ver($exactLoan), null);
check('kas: pencairan TEPAT sebesar kas (6.000.000) disetujui; kas jadi 0, tidak negatif', (int) global_()['kas_tersedia'] === 0 && (int) one('SELECT COUNT(*) FROM v_integrity_issues') === 0);
// pengaturan izinkan negatif
setting('allow_negative_cash', '1');
ValidationService::approve(req(), $head, $loanB, ver($loanB), null);
check('kas: pengaturan "izinkan melebihi kas" melonggarkan pemeriksaan (kas jadi negatif)', trx($loanB)['status'] === 'DISETUJUI' && (int) global_()['kas_tersedia'] === -9000000);
setting('allow_negative_cash', '0');
check('kas: kas negatif terdeteksi oleh pemeriksa integritas (KAS_NEGATIF)', (int) one("SELECT COUNT(*) FROM v_integrity_issues WHERE issue = 'KAS_NEGATIF'") === 1);
// pulihkan kas dengan simpanan agar sisa uji tidak terganggu
$restore = saving($beta, 4, '30.000.000');
ValidationService::approve(req(), $periksa, $restore, ver($restore), null);
check('kas: setelah simpanan 30.000.000 disetujui kas pulih 21.000.000, integritas bersih', (int) global_()['kas_tersedia'] === 21000000 && (int) one('SELECT COUNT(*) FROM v_integrity_issues') === 0);

// kunci kas: persetujuan serentak bergiliran
$locker = Database::connect($admin[0], $admin[1], $testDb);
$locker->beginTransaction();
$locker->query("SELECT setting_value FROM settings WHERE setting_key = 'allow_negative_cash' FOR UPDATE")->fetchAll();
$svLock = saving($beta, 4, '5.000');
Database::pdo()->exec('SET SESSION innodb_lock_wait_timeout = 2');
$timedOut = false;
$t0 = microtime(true);
try {
    ValidationService::approve(req(), $head, $svLock, ver($svLock), null);
} catch (PDOException $e) {
    $timedOut = str_contains($e->getMessage(), 'Lock wait timeout');
}
$locker->rollBack();
Database::pdo()->exec('SET SESSION innodb_lock_wait_timeout = 50');
check('kunci kas: persetujuan menunggu giliran selama kunci dipegang pihak lain (tidak menerobos), lalu gagal bersih', $timedOut && (microtime(true) - $t0) >= 1.5 && trx($svLock)['status'] === 'MENUNGGU_VALIDASI');
ValidationService::approve(req(), $head, $svLock, ver($svLock), null);
check('kunci kas: setelah kunci dilepas persetujuan berhasil', trx($svLock)['status'] === 'DISETUJUI');
check('kunci kas: penolakan tidak ikut antre pada kunci kas', (function () use ($admin, $testDb, $head, $beta): bool {
    $l = Database::connect($admin[0], $admin[1], $testDb);
    $l->beginTransaction();
    $l->query("SELECT setting_value FROM settings WHERE setting_key = 'allow_negative_cash' FOR UPDATE")->fetchAll();
    $x = saving($beta, 4, '6.000');
    Database::pdo()->exec('SET SESSION innodb_lock_wait_timeout = 2');
    try {
        ValidationService::reject(req(), $head, $x, ver($x), 'tolak saat kas dikunci');
        $ok = trx($x)['status'] === 'DITOLAK';
    } catch (PDOException $e) {
        $ok = false;
    }
    $l->rollBack();
    Database::pdo()->exec('SET SESSION innodb_lock_wait_timeout = 50');
    return $ok;
})());

// ======================= angsuran: pemeriksaan saat setuju =======================
// pinjaman bulan lalu (disetujui lewat layanan) agar bisa dibayar bulan ini: 1.000.000 tenor 2 = 2 x 520.000
[$e, $d] = LoanService::parse(['member_id' => 4, 'period_month_id' => $mid1, 'principal' => '1.000.000', 'tenor' => '2', 'trx_date' => $m1, 'confirm_duplicate' => '1']);
setting('allow_negative_cash', '1');
$oldLoan = LoanService::create(req(), $beta, $d, true);
setting('allow_negative_cash', '0');
ValidationService::approve(req(), $head, $oldLoan, ver($oldLoan), null);
$kasBefore = (int) global_()['kas_tersedia'];
[$e, $dp] = InstallmentService::parse(['member_id' => 4, 'period_month_id' => $mid0, 'amount' => '520.000', 'trx_date' => $today]);
$pay1 = InstallmentService::create(req(), $beta, $dp, true);
check('angsuran: pembayaran 520.000 diajukan, belum mengurangi sisa pinjaman', trx($pay1)['status'] === 'MENUNGGU_VALIDASI' && (int) one('SELECT outstanding FROM v_loan_balances WHERE transaction_id = ?', [$oldLoan]) === 1040000);
ValidationService::approve(req(), $head, $pay1, ver($pay1), null);
check('angsuran: setelah disetujui sisa pinjaman 520.000, kas naik 520.000, selisih 0, integritas bersih', (int) one('SELECT outstanding FROM v_loan_balances WHERE transaction_id = ?', [$oldLoan]) === 520000 && (int) global_()['kas_tersedia'] === $kasBefore + 520000 && (int) global_()['selisih'] === 0 && (int) one('SELECT COUNT(*) FROM v_integrity_issues') === 0);
$noAlloc = rawPending('ANGSURAN', 4, 2, $beta['id'], 100000);
$e = violation(fn () => ValidationService::approve(req(), $head, $noAlloc, ver($noAlloc), null));
check('angsuran: pembagian tidak sama dengan jumlah dibayar -> tidak bisa disetujui', isset($e['_form']) && str_contains($e['_form'], 'Pembagian pembayaran') && trx($noAlloc)['status'] === 'MENUNGGU_VALIDASI');
$seq2 = (int) one('SELECT li.id FROM loan_installments li JOIN loans l ON l.id = li.loan_id WHERE l.transaction_id = ? AND li.seq = 2', [$oldLoan]);
$overpay = rawPending('ANGSURAN', 4, 2, $beta['id'], 600000, static function (int $id) use ($seq2): void {
    Database::pdo()->exec("INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES ({$id}, {$seq2}, 600000)");
});
$e = violation(fn () => ValidationService::approve(req(), $head, $overpay, ver($overpay), null));
check('angsuran: pembayaran yang akan melebihi tagihan cicilan (kelebihan bayar) tidak bisa disetujui', isset($e['_form']) && str_contains($e['_form'], 'kelebihan bayar') && trx($overpay)['status'] === 'MENUNGGU_VALIDASI' && (int) one('SELECT COUNT(*) FROM v_integrity_issues') === 0);
ValidationService::reject(req(), $head, $overpay, ver($overpay), 'Salah hitung');
ValidationService::reject(req(), $head, $noAlloc, ver($noAlloc), 'Tidak ada pembagian');
check('angsuran: keduanya bisa ditolak dan tidak memengaruhi saldo', trx($overpay)['status'] === 'DITOLAK' && trx($noAlloc)['status'] === 'DITOLAK' && (int) one('SELECT outstanding FROM v_loan_balances WHERE transaction_id = ?', [$oldLoan]) === 520000);

// ======================= penarikan =======================
$wd1 = rawPending('PENARIKAN', 1, 1, $purwati['id'], 150000);
$e = violation(fn () => ValidationService::approve(req(), $periksa, $wd1, ver($wd1), null));
check('penarikan: dinonaktifkan di Pengaturan -> tidak bisa disetujui', isset($e['_form']) && str_contains($e['_form'], 'dinonaktifkan') && trx($wd1)['status'] === 'MENUNGGU_VALIDASI');
setting('withdrawals_enabled', '1');
$e = violation(fn () => ValidationService::approve(req(), $periksa, $wd1, ver($wd1), null));
check('penarikan: melebihi saldo tabungan anggota (saldo 100.000) ditolak', isset($e['_form']) && str_contains($e['_form'], 'Saldo tabungan') && trx($wd1)['status'] === 'MENUNGGU_VALIDASI');
$wd2 = rawPending('PENARIKAN', 1, 1, $purwati['id'], 100000);
$kasWd = (int) global_()['kas_tersedia'];
ValidationService::approve(req(), $periksa, $wd2, ver($wd2), null);
check('penarikan: sebesar saldo disetujui; saldo 0, kas turun, selisih 0', (int) one('SELECT savings_balance FROM v_member_savings WHERE member_id = 1') === 0 && (int) global_()['kas_tersedia'] === $kasWd - 100000 && (int) global_()['selisih'] === 0);
setting('withdrawals_enabled', '0');

// ======================= atomik =======================
$plain2 = saving($beta, 4, '4.321.000');
$kasAt = (int) global_()['kas_tersedia'];
$auditAt = audits('TRX_APPROVED');
$pdo->exec("CREATE TRIGGER trg_tes_gagal BEFORE INSERT ON transaction_validations FOR EACH ROW BEGIN IF NEW.to_status = 'DISETUJUI' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'gagal sengaja'; END IF; END");
$boom = false;
try {
    ValidationService::approve(req(), $head, $plain2, ver($plain2), null);
} catch (PDOException $e) {
    $boom = str_contains($e->getMessage(), 'gagal sengaja');
} finally {
    $pdo->exec('DROP TRIGGER trg_tes_gagal');
}
check('atomik: kegagalan menulis riwayat membatalkan perpindahan status dan audit (tetap MENUNGGU, kas tak berubah)', $boom && trx($plain2)['status'] === 'MENUNGGU_VALIDASI' && (int) global_()['kas_tersedia'] === $kasAt && audits('TRX_APPROVED') === $auditAt);
ValidationService::approve(req(), $head, $plain2, ver($plain2), null);
check('atomik: setelah gangguan hilang persetujuan berhasil', trx($plain2)['status'] === 'DISETUJUI');

// ======================= antrean dan riwayat =======================
$pwPending = saving($purwati, 2, '9.000');   // terkait Head
$q = Validation::queue($head, [], 1);
$qIds = array_map(fn ($r) => (int) $r['id'], $q['rows']);
check('antrean: hanya yang MENUNGGU_VALIDASI, terlama dulu', $qIds !== [] && array_unique(array_map(fn ($i) => trx($i)['status'], $qIds)) === ['MENUNGGU_VALIDASI'] && $qIds === array_values(array_unique($qIds)) && (int) $q['pager']['total'] === (int) one("SELECT COUNT(*) FROM transactions WHERE status = 'MENUNGGU_VALIDASI'"));
$rowPw = array_values(array_filter($q['rows'], fn ($r) => (int) $r['id'] === $pwPending))[0];
$rowPlain = array_values(array_filter($q['rows'], fn ($r) => (int) $r['id'] === $plain))[0];
check('antrean: transaksi terkait Head ditandai dan Head tidak bisa; yang biasa bisa', $rowPw['head_related'] == 1 && $rowPw['verdict']['eligible'] === false && $rowPlain['verdict']['eligible'] === true);
check('antrean: Pemeriksa bisa memvalidasi semuanya, termasuk transaksi terkait Head', array_values(array_unique(array_map(fn ($r) => $r['verdict']['eligible'], Validation::queue($periksa, [], 1)['rows']))) === [true]);
check('antrean: Pemeriksa yang juga anggota tidak bisa pada transaksi atas namanya sendiri', (function () use ($periksaM, $pdo): bool {
    $own = rawPending('SIMPANAN', 5, 2, 2, 1000);
    $r = array_values(array_filter(Validation::queue($periksaM, [], 1)['rows'], fn ($x) => (int) $x['id'] === $own))[0];
    return $r['verdict']['eligible'] === false;
})());
check('antrean: pembuat melihat alasan tidak bisa pada transaksinya sendiri', (function () use ($purwati, $pwPending): bool {
    $r = array_values(array_filter(Validation::queue($purwati, [], 1)['rows'], fn ($x) => (int) $x['id'] === $pwPending))[0];
    return $r['verdict']['eligible'] === false;
})());
check('antrean: filter jenis dan pencarian (nomor dokumen, nama anggota) dan wildcard harfiah', Validation::queue($head, ['type' => 'PENCAIRAN_PINJAMAN'], 1)['pager']['total'] === 0 && Validation::queue($head, ['type' => 'SIMPANAN'], 1)['pager']['total'] >= 2
    && Validation::queue($head, ['q' => trx($pwPending)['doc_no']], 1)['pager']['total'] === 1 && Validation::queue($head, ['q' => 'Anggota Alfa'], 1)['pager']['total'] >= 1 && Validation::queue($head, ['q' => '%'], 1)['pager']['total'] === 0 && Validation::queue($head, ['q' => "' OR '1'='1"], 1)['pager']['total'] === 0);
check('antrean: ringkasan per jenis memuat jumlah dan nilai', Validation::summary()['SIMPANAN']['n'] === (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'SIMPANAN' AND status = 'MENUNGGU_VALIDASI'") && Validation::summary()['SIMPANAN']['sum'] === (int) one("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type = 'SIMPANAN' AND status = 'MENUNGGU_VALIDASI'"));
$pdo->exec("INSERT INTO transaction_validations (transaction_id, from_status, to_status, actor_user_id, note) VALUES ({$sv1}, 'MENUNGGU_VALIDASI', 'DISETUJUI', NULL, 'impor historis')");
$dec = Validation::decisions([], 1);
check('riwayat: hanya keputusan lewat aplikasi (baris tanpa validator tidak ikut), terbaru dulu', $dec['pager']['total'] === (int) one("SELECT COUNT(*) FROM transaction_validations WHERE to_status IN ('DISETUJUI','DITOLAK') AND actor_user_id IS NOT NULL")
    && !in_array('impor historis', array_column($dec['rows'], 'note'), true) && (int) $dec['rows'][0]['id'] === max(array_map(fn ($r) => (int) $r['id'], $dec['rows'])));
check('riwayat: filter keputusan, jenis, dan pencarian (validator, dokumen)', Validation::decisions(['decision' => 'DITOLAK'], 1)['pager']['total'] === (int) one("SELECT COUNT(*) FROM transaction_validations WHERE to_status = 'DITOLAK' AND actor_user_id IS NOT NULL")
    && Validation::decisions(['decision' => 'DISETUJUI', 'type' => 'ANGSURAN'], 1)['pager']['total'] === 1 && Validation::decisions(['q' => 'kepala2'], 1)['pager']['total'] >= 3 && Validation::decisions(['q' => '%'], 1)['pager']['total'] === 0 && Validation::decisions(['decision' => 'x', 'type' => 'x'], 1)['pager']['total'] === $dec['pager']['total']);
check('riwayat: menyebut validator, pembuat, dan alasan penolakan', in_array('Nominal tidak sesuai bukti', array_column(Validation::decisions(['decision' => 'DITOLAK'], 1)['rows'], 'note'), true) && in_array('Kepala2', array_column($dec['rows'], 'actor_name'), true));

// ======================= invarian akhir =======================
check('akhir: invarian kas + piutang = tabungan + bunga - biaya tetap 0, integritas bersih', (int) global_()['selisih'] === 0 && (int) one('SELECT COUNT(*) FROM v_integrity_issues') === 0);

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
