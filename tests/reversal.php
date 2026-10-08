<?php
declare(strict_types=1);

/**
 * Tes transaksi pembalik (Phase 10): pengajuan koreksi oleh Ketua Regu, satu pembalik hidup per transaksi,
 * pemeriksaan keadaan saat diajukan DAN saat disetujui, pengaruh ke saldo untuk semua jenis, pinjaman/angsuran,
 * pemisahan tugas, atomisitas, dan pemeriksa integritas.
 * MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\reversal.php
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
use App\Services\ReversalService;
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
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.1', 'HTTP_USER_AGENT' => 'tes-pembalik']);
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
function cash(): int
{
    return (int) global_()['kas_tersedia'];
}
function savingsOf(int $member): int
{
    return (int) one('SELECT savings_balance FROM v_member_savings WHERE member_id = ?', [$member]);
}
function issues(): int
{
    return (int) one('SELECT COUNT(*) FROM v_integrity_issues');
}
function trxCount(): int
{
    return (int) one('SELECT COUNT(*) FROM transactions');
}
/** Sisipkan transaksi mentah (opsional pembalik) lalu pindahkan ke status yang diminta lewat SQL langsung. */
function rawTrx(string $type, ?int $memberId, ?int $teamId, ?int $createdBy, int $amount, ?int $reverses = null, ?callable $prep = null, string $status = 'MENUNGGU_VALIDASI'): int
{
    static $n = 0;
    global $mid0;
    $n++;
    $pdo = Database::pdo();
    $stmt = $pdo->prepare('INSERT INTO transactions (trx_no, doc_no, type, member_id, team_id, period_month_id, trx_date, amount, reverses_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute(["T-RAW-{$n}", "RAW-{$n}", $type, $memberId, $teamId, $mid0, date('Y-m-d'), $amount, $reverses, $createdBy]);
    $id = (int) $pdo->lastInsertId();
    if ($type === 'SIMPANAN') {
        $pdo->exec("INSERT INTO savings (transaction_id, kind) VALUES ({$id}, 'SUKARELA')");
    }
    if ($prep !== null) {
        $prep($id);
    }
    $pdo->exec("UPDATE transactions SET status = 'MENUNGGU_VALIDASI', status_changed_at = NOW() WHERE id = {$id}");
    if ($status === 'DISETUJUI') {
        $pdo->exec("UPDATE transactions SET status = 'DISETUJUI', status_changed_at = NOW() WHERE id = {$id}");
    }
    return $id;
}

// ---------------- fixture ----------------
$m1 = date('Y-m-01', strtotime('-1 month', strtotime(date('Y-m-01'))));
$m0 = date('Y-m-01');
$later = [date('Y-m-01', strtotime('+1 month', strtotime($m0))), date('Y-m-01', strtotime('+2 month', strtotime($m0)))];
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji', '{$m1}', '{$later[1]}', 'AKTIF')");
foreach ([$m1, $m0, ...$later] as $d) {
    $pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1, '{$d}')");
}
$mid1 = (int) one('SELECT id FROM period_months WHERE month_date = ?', [$m1]);
$mid0 = (int) one('SELECT id FROM period_months WHERE month_date = ?', [$m0]);
$today = date('Y-m-d');

foreach ([1 => 'Ketua Alfa (Head)', 2 => 'Anggota Alfa', 3 => 'Ketua Beta', 4 => 'Anggota Beta', 5 => 'Anggota Beta 2', 6 => 'Anggota Beta 3'] as $n => $name) {
    $pdo->prepare('INSERT INTO members (member_no, name, active_from, status) VALUES (?, ?, ?, ?)')->execute([sprintf('AGT-%03d', $n), $name, $m1, 'AKTIF']);
}
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Alfa', 1), ('Regu Beta', 3)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'{$m1}'),(2,1,'{$m1}'),(3,2,'{$m1}'),(4,2,'{$m1}'),(5,2,'{$m1}'),(6,2,'{$m1}')");

$ids = [];
foreach ([['purwati', ['HEAD', 'KETUA_REGU'], 'AGT-001'], ['ketua.beta', ['KETUA_REGU'], 'AGT-003'], ['kepala2', ['HEAD'], null], ['periksa', ['PEMERIKSA'], null], ['anggota4', ['ANGGOTA'], 'AGT-004']] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$purwati  = User::findActive($ids['purwati']);
$beta     = User::findActive($ids['ketua.beta']);
$head     = User::findActive($ids['kepala2']);
$periksa  = User::findActive($ids['periksa']);
$anggota4 = User::findActive($ids['anggota4']);
setting('loan_max_amount', '0');

function saving(array $actor, int $member, string $amount, bool $submit = true): int
{
    global $mid0, $today;
    [$e, $d] = SavingService::parse(['member_id' => $member, 'period_month_id' => $mid0, 'kind' => 'SUKARELA', 'amount' => $amount, 'trx_date' => $today, 'confirm_duplicate' => '1']);
    if ($e !== []) {
        throw new RuntimeException(json_encode($e));
    }
    return SavingService::create(req(), $actor, $d, $submit);
}
/** Pinjaman dicairkan bulan LALU supaya bisa dibayar bulan ini. */
function loanLastMonth(array $actor, int $member, string $principal, string $tenor): int
{
    global $mid1, $m1;
    [$e, $d] = LoanService::parse(['member_id' => $member, 'period_month_id' => $mid1, 'principal' => $principal, 'tenor' => $tenor, 'trx_date' => $m1, 'confirm_duplicate' => '1']);
    if ($e !== []) {
        throw new RuntimeException(json_encode($e));
    }
    return LoanService::create(req(), $actor, $d, true);
}
function payment(array $actor, int $member, string $amount): int
{
    global $mid0, $today;
    [$e, $d] = InstallmentService::parse(['member_id' => $member, 'period_month_id' => $mid0, 'amount' => $amount, 'trx_date' => $today, 'confirm_duplicate' => '1']);
    if ($e !== []) {
        throw new RuntimeException(json_encode($e));
    }
    return InstallmentService::create(req(), $actor, $d, true);
}
function approved(array $validator, int $id): int
{
    ValidationService::approve(req(), $validator, $id, ver($id), null);
    return $id;
}

// ======================= dana awal =======================
$s0 = approved($head, saving($beta, 4, '20.000.000'));
check('awal: kas dan tabungan Rp 20.000.000, integritas bersih', cash() === 20000000 && savingsOf(4) === 20000000 && issues() === 0);

// ======================= simpanan: alur dasar =======================
$s1 = approved($head, saving($beta, 4, '1.000.000'));
$kas1 = cash();
$sav1 = savingsOf(4);
check('alasan: wajib diisi dan maksimal 200 karakter', isset(violation(fn () => ReversalService::request(req(), $beta, $s1, '   '))['note']) && isset(violation(fn () => ReversalService::request(req(), $beta, $s1, str_repeat('x', 201)))['note']) && (int) one('SELECT COUNT(*) FROM transactions WHERE reverses_id IS NOT NULL') === 0);

$r1 = ReversalService::request(req(), $beta, $s1, '  Salah   anggota ');
$rv = trx($r1);
check('ajukan: pembalik baru MENUNGGU_VALIDASI, jenis/anggota/nominal/bulan sama dengan asal', $rv['status'] === 'MENUNGGU_VALIDASI' && (int) $rv['reverses_id'] === $s1 && $rv['type'] === 'SIMPANAN' && (int) $rv['member_id'] === 4
    && (int) $rv['amount'] === 1000000 && (int) $rv['period_month_id'] === (int) trx($s1)['period_month_id'] && $rv['trx_date'] === $today);
check('ajukan: dicatat atas nama regu pengaju, pembuatnya Ketua Regu, asal Aplikasi, alasan dirapikan', (int) $rv['team_id'] === 2 && (int) $rv['created_by'] === $beta['id'] && $rv['source'] === 'APLIKASI' && $rv['description'] === 'Salah anggota');
check('ajukan: nomor dokumen SMP- sendiri, rincian jenis simpanan disalin', str_starts_with($rv['doc_no'], 'SMP-') && $rv['doc_no'] !== trx($s1)['doc_no'] && one('SELECT kind FROM savings WHERE transaction_id = ?', [$r1]) === 'SUKARELA');
$vh = Database::select('SELECT * FROM transaction_validations WHERE transaction_id = ? ORDER BY id', [$r1]);
check('ajukan: riwayat DRAFT -> MENUNGGU_VALIDASI memuat nomor asal dan alasan; audit REVERSAL_CREATED + REVERSAL_REQUESTED', count($vh) === 1 && $vh[0]['from_status'] === 'DRAFT' && $vh[0]['to_status'] === 'MENUNGGU_VALIDASI'
    && $vh[0]['note'] === 'Koreksi ' . trx($s1)['doc_no'] . ': Salah anggota' && lastAudit('REVERSAL_CREATED')['reference_no'] === $rv['doc_no'] && lastAudit('REVERSAL_CREATED')['after']['reason'] === 'Salah anggota'
    && lastAudit('REVERSAL_CREATED')['after']['reverses_doc_no'] === trx($s1)['doc_no'] && audits('REVERSAL_REQUESTED') === 1);
check('ajukan: selama menunggu, saldo dan transaksi asal tidak berubah', cash() === $kas1 && savingsOf(4) === $sav1 && trx($s1)['status'] === 'DISETUJUI' && (int) trx($s1)['amount'] === 1000000 && trx($s1)['reverses_id'] === null);
check('ajukan: dua pembalik hidup untuk transaksi yang sama ditolak (dengan nomor pembalik yang ada)', str_contains((string) violation(fn () => ReversalService::request(req(), $beta, $s1, 'Lagi'))['_form'], $rv['doc_no']) && (int) one('SELECT COUNT(*) FROM transactions WHERE reverses_id = ?', [$s1]) === 1);

$vl = ValidationService::assessFor($head, trx($r1));
check('validasi: pembalik biasa (regu Beta) bisa divalidasi Head; pembuat tidak', $vl['eligible'] === true && ValidationService::assessFor($beta, trx($r1))['eligible'] === false);
ValidationService::approve(req(), $head, $r1, ver($r1), 'Benar salah anggota');
check('setujui: tabungan anggota dan kas turun Rp 1.000.000; selisih 0; integritas bersih', savingsOf(4) === $sav1 - 1000000 && cash() === $kas1 - 1000000 && (int) global_()['selisih'] === 0 && issues() === 0);
check('setujui: transaksi asal tetap DISETUJUI dan tak berubah; ledger memuat keduanya (+ dan -)', trx($s1)['status'] === 'DISETUJUI' && (int) trx($s1)['amount'] === 1000000
    && (int) one('SELECT SUM(savings_delta) FROM v_ledger WHERE transaction_id IN (?, ?)', [$s1, $r1]) === 0 && (int) one('SELECT COUNT(*) FROM v_ledger WHERE transaction_id IN (?, ?)', [$s1, $r1]) === 2);
check('setujui: audit TRX_APPROVED untuk pembalik', lastAudit('TRX_APPROVED')['reference_no'] === $rv['doc_no']);
$show = Transaction::findScoped($beta, $r1);
check('detail: pembalik menyebut nomor transaksi yang dibalik', $show !== null && $show['reverses_doc_no'] === trx($s1)['doc_no'] && (int) $show['reverses_id'] === $s1);
check('setelah dibalik: tidak bisa dibalik lagi; pembalik tidak bisa dibalik', str_contains((string) violation(fn () => ReversalService::request(req(), $beta, $s1, 'Lagi'))['_form'], $rv['doc_no'])
    && str_contains((string) violation(fn () => ReversalService::request(req(), $beta, $r1, 'Balik pembalik'))['_form'], 'pembalik'));

// ======================= siapa yang boleh / transaksi mana yang bisa =======================
$s2 = approved($head, saving($beta, 4, '2.000.000'));
$cnt = trxCount();
check('kewenangan: Ketua Regu lain (Purwati) tidak bisa membalik transaksi regu Beta', isset(violation(fn () => ReversalService::request(req(), $purwati, $s2, 'Coba'))['_form']) && str_contains((string) violation(fn () => ReversalService::request(req(), $purwati, $s2, 'Coba'))['_form'], 'regu Anda'));
check('kewenangan: Anggota dan Head (bukan ketua regu) tidak bisa mengajukan', isset(violation(fn () => ReversalService::request(req(), $anggota4, $s2, 'Coba'))['_form']) && isset(violation(fn () => ReversalService::request(req(), $head, $s2, 'Coba'))['_form'])
    && isset(violation(fn () => ReversalService::request(req(), $periksa, $s2, 'Coba'))['_form']));
check('kewenangan: transaksi tidak ada = pesan yang sama dengan regu lain (tidak membocorkan)', violation(fn () => ReversalService::request(req(), $beta, 999999, 'x'))['_form'] === violation(fn () => ReversalService::request(req(), $purwati, $s2, 'x'))['_form']);
$pend = saving($beta, 4, '3.000');
$draft = saving($beta, 4, '4.000', false);
$rej = saving($beta, 4, '5.000');
ValidationService::reject(req(), $head, $rej, ver($rej), 'Salah');
$cnt = trxCount();
check('status: hanya DISETUJUI yang bisa dibalik (menunggu, draft, ditolak ditolak, dengan penjelasan)', str_contains((string) violation(fn () => ReversalService::request(req(), $beta, $pend, 'x'))['_form'], 'sudah disetujui')
    && isset(violation(fn () => ReversalService::request(req(), $beta, $draft, 'x'))['_form']) && isset(violation(fn () => ReversalService::request(req(), $beta, $rej, 'x'))['_form']) && trxCount() === $cnt);

// ======================= ditolak / dibatalkan boleh diganti =======================
$q1 = ReversalService::request(req(), $beta, $s2, 'Salah anggota');
ValidationService::reject(req(), $head, $q1, ver($q1), 'Tidak perlu dikoreksi');
check('ditolak: pembalik DITOLAK tidak mengubah saldo', trx($q1)['status'] === 'DITOLAK' && savingsOf(4) === $sav1 - 1000000 + 2000000 && one('SELECT open_reverses_id FROM transactions WHERE id = ?', [$q1]) === null);
$q2 = ReversalService::request(req(), $beta, $s2, 'Ajukan ulang');
check('ditolak: transaksi asal bisa dibalik lagi dengan pembalik baru', trx($q2)['status'] === 'MENUNGGU_VALIDASI' && (int) trx($q2)['reverses_id'] === $s2);
SavingService::cancel(req(), $beta, $q2, 'Salah tekan', ver($q2));
check('dibatalkan: pembuat membatalkan pembalik yang menunggu; transaksi asal bisa dibalik lagi', trx($q2)['status'] === 'DIBATALKAN');
$q3 = ReversalService::request(req(), $beta, $s2, 'Salah nominal');
check('batas basis data: pembalik hidup kedua ditolak walau lewat SQL langsung, sementara yang lama sudah mati boleh',
    (function () use ($s2, $beta, $mid0): bool {
        $pdo = Database::pdo();
        try {
            $pdo->prepare("INSERT INTO transactions (trx_no, doc_no, type, member_id, period_month_id, trx_date, amount, reverses_id, created_by) VALUES ('X-DUP','X-DUP','SIMPANAN',4,?,CURDATE(),2000000,?,?)")->execute([$mid0, $s2, $beta['id']]);
            $pdo->exec("DELETE FROM transactions WHERE doc_no = 'X-DUP'");
            return false;
        } catch (PDOException $e) {
            return str_contains($e->getMessage(), 'Duplicate');
        }
    })());
$at = ReversalService::attempts($s2);
check('jejak: semua percobaan tercatat (ditolak, dibatalkan, menunggu) dan satu yang hidup', count($at) === 3 && array_column($at, 'status') === ['DITOLAK', 'DIBATALKAN', 'MENUNGGU_VALIDASI']);
$offer = ReversalService::offer($beta, Transaction::findScoped($beta, $s2));
check('tawaran: transaksi dengan pembalik hidup tidak menawarkan formulir, tetapi menunjuk pembaliknya', $offer['can'] === false && $offer['blocked'] === null && (int) $offer['live']['id'] === $q3);
ValidationService::approve(req(), $periksa, $q3, ver($q3), null);
check('setujui oleh Pemeriksa: asal Rp 2.000.000 dibalik; selisih 0', savingsOf(4) === $sav1 - 1000000 && (int) global_()['selisih'] === 0 && issues() === 0);

// ======================= terkait Head: hanya Pemeriksa =======================
$pw = approved($periksa, saving($purwati, 2, '400.000'));
$pr = ReversalService::request(req(), $purwati, $pw, 'Salah catat');
$verdict = ValidationService::assessFor($head, trx($pr));
check('Head: pembalik buatan Purwati (Head + Ketua Regu) hanya bisa divalidasi Pemeriksa', $verdict['eligible'] === false && $verdict['head_related'] === true
    && isset(violation(fn () => ValidationService::approve(req(), $head, $pr, ver($pr), null))['_form']) && isset(violation(fn () => ValidationService::approve(req(), $purwati, $pr, ver($pr), null))['_form']) && trx($pr)['status'] === 'MENUNGGU_VALIDASI');
ValidationService::approve(req(), $periksa, $pr, ver($pr), null);
check('Pemeriksa: menyetujui pembalik Head; tabungan anggota Alfa kembali 0', trx($pr)['status'] === 'DISETUJUI' && savingsOf(2) === 0);

// ======================= keadaan berubah antara diajukan dan disetujui; saldo tabungan =======================
$sv5 = approved($head, saving($beta, 5, '100.000'));
$rv5 = ReversalService::request(req(), $beta, $sv5, 'Salah anggota');
$wd5 = rawTrx('PENARIKAN', 5, 2, $beta['id'], 60000, null, null, 'DISETUJUI');
$e = violation(fn () => ValidationService::approve(req(), $head, $rv5, ver($rv5), null));
check('saldo tabungan: setelah ada penarikan, pembalik simpanan yang sudah menunggu tidak bisa disetujui (saldo jadi negatif), tetap menunggu', isset($e['_form']) && str_contains($e['_form'], 'Saldo tabungan anggota sekarang Rp 40.000') && trx($rv5)['status'] === 'MENUNGGU_VALIDASI' && savingsOf(5) === 40000);
ValidationService::reject(req(), $head, $rv5, ver($rv5), 'Saldo sudah terpakai');
$sv6 = approved($head, saving($beta, 6, '100.000'));
$wd6 = rawTrx('PENARIKAN', 6, 2, $beta['id'], 70000, null, null, 'DISETUJUI');
$offer6 = ReversalService::offer($beta, Transaction::findScoped($beta, $sv6));
check('saldo tabungan: pengajuan ditolak dini dengan angka; tawaran menampilkan alasan terblokir', str_contains((string) violation(fn () => ReversalService::request(req(), $beta, $sv6, 'x'))['_form'], 'Saldo tabungan anggota sekarang Rp 30.000')
    && $offer6['can'] === false && str_contains((string) $offer6['blocked'], 'Rp 30.000'));

// penarikan dibalik: tabungan dan kas naik
$kasW = cash();
$rw6 = ReversalService::request(req(), $beta, $wd6, 'Penarikan salah anggota');
check('penarikan: pembalik berjenis PENARIKAN, nomor TRK-', trx($rw6)['type'] === 'PENARIKAN' && str_starts_with(trx($rw6)['doc_no'], 'TRK-') && (int) trx($rw6)['member_id'] === 6);
ValidationService::approve(req(), $head, $rw6, ver($rw6), null);
check('penarikan: setelah pembalik disetujui, tabungan anggota kembali 100.000 dan kas naik 70.000, selisih 0', savingsOf(6) === 100000 && cash() === $kasW + 70000 && (int) global_()['selisih'] === 0);

// biaya dibalik
$bya = rawTrx('BIAYA', null, 2, $beta['id'], 250000, null, static function (int $id): void {
    Database::pdo()->exec("INSERT INTO expenses (transaction_id, category, fund_source) VALUES ({$id}, 'Buku tabungan', 'SHU')");
}, 'DISETUJUI');
$kasB = cash();
$biayaB = (int) global_()['biaya'];
$rb = ReversalService::request(req(), $beta, $bya, 'Biaya dobel');
check('biaya: pembalik tanpa anggota, rincian biaya disalin, nomor BYA-', trx($rb)['member_id'] === null && str_starts_with(trx($rb)['doc_no'], 'BYA-') && one('SELECT category FROM expenses WHERE transaction_id = ?', [$rb]) === 'Buku tabungan');
ValidationService::approve(req(), $head, $rb, ver($rb), null);
check('biaya: setelah disetujui kas naik 250.000 dan total biaya turun 250.000, selisih 0', cash() === $kasB + 250000 && (int) global_()['biaya'] === $biayaB - 250000 && (int) global_()['selisih'] === 0 && issues() === 0);

// ======================= pinjaman dan angsuran =======================
$L1 = approved($head, loanLastMonth($beta, 4, '1.000.000', '2'));
$P1 = approved($head, payment($beta, 4, '520.000'));
$kasP = cash();
check('pinjaman: sisa tagihan 520.000 setelah satu angsuran', (int) one('SELECT outstanding FROM v_loan_balances WHERE transaction_id = ?', [$L1]) === 520000);
check('pinjaman: yang sudah dibayar tidak bisa dibalik; pesan menyebut jumlah dan cara', str_contains((string) violation(fn () => ReversalService::request(req(), $beta, $L1, 'x'))['_form'], 'sudah dibayar Rp 520.000')
    && str_contains((string) violation(fn () => ReversalService::request(req(), $beta, $L1, 'x'))['_form'], 'Balik dulu'));

$RP1 = ReversalService::request(req(), $beta, $P1, 'Pembayaran salah anggota');
$alloc = Database::select('SELECT installment_id, amount FROM installment_payments WHERE transaction_id = ?', [$RP1]);
$allocOrig = Database::select('SELECT installment_id, amount FROM installment_payments WHERE transaction_id = ?', [$P1]);
check('angsuran: alokasi pembalik persis kebalikan alokasi asal (negatif)', $alloc !== [] && count($alloc) === count($allocOrig) && (int) $alloc[0]['installment_id'] === (int) $allocOrig[0]['installment_id'] && (int) $alloc[0]['amount'] === -(int) $allocOrig[0]['amount']);
$room = array_sum(array_column(InstallmentService::payableInstallments($pdo, 4, $m0, null), 'room'));
check('angsuran: pembalik yang masih menunggu TIDAK melonggarkan ruang bayar (tetap 520.000)', $room === 520000);
$dueRow = array_values(array_filter(Transaction::dueByMember($head), static fn (array $r): bool => (int) $r['id'] === 4))[0] ?? null;
check('tagihan: pembalik yang menunggu tidak dihitung sebagai "pembayaran menunggu"', $dueRow !== null && (int) $dueRow['pending'] === 0);
check('ringkasan antrean: pembalik dihitung bernilai negatif', Validation::summary()['ANGSURAN']['sum'] === -520000 && Validation::summary()['ANGSURAN']['n'] === 1);
ValidationService::approve(req(), $head, $RP1, ver($RP1), null);
$ins1 = Database::select('SELECT seq, paid_amount, remaining_amount FROM v_installment_status WHERE loan_id = (SELECT id FROM loans WHERE transaction_id = ?) ORDER BY seq', [$L1]);
check('angsuran: setelah pembalik disetujui, cicilan 1 terbuka lagi, sisa pinjaman 1.040.000, kas turun 520.000, selisih 0',
    (int) one('SELECT outstanding FROM v_loan_balances WHERE transaction_id = ?', [$L1]) === 1040000 && cash() === $kasP - 520000 && (int) $ins1[0]['paid_amount'] === 0 && (int) $ins1[0]['remaining_amount'] === 520000 && (int) global_()['selisih'] === 0 && issues() === 0);
check('angsuran: setelah dibalik, anggota boleh membayar ulang cicilan itu', (function () use ($beta, $head): bool {
    $p = payment($beta, 4, '520.000');
    ValidationService::approve(req(), $head, $p, ver($p), null);
    $RP = ReversalService::request(req(), $beta, $p, 'Dibalik lagi untuk uji');
    ValidationService::approve(req(), $head, $RP, ver($RP), null);
    return (int) one('SELECT outstanding FROM v_loan_balances WHERE loan_id = (SELECT id FROM loans ORDER BY id LIMIT 1)') === 1040000 && (int) global_()['selisih'] === 0;
})());

// pembayaran yang menunggu menghalangi pembalikan pinjaman
$P2 = payment($beta, 4, '520.000');
check('pinjaman: pembayaran yang masih menunggu validasi menghalangi pembalikan pinjaman', str_contains((string) violation(fn () => ReversalService::request(req(), $beta, $L1, 'x'))['_form'], 'menunggu validasi'));
InstallmentService::cancel(req(), $beta, $P2, 'Batal', ver($P2));

$kasL = cash();
$RL1 = ReversalService::request(req(), $beta, $L1, 'Pinjaman salah anggota');
check('pinjaman: pembalik berjenis PENCAIRAN_PINJAMAN, nomor PJM-, tanpa baris pinjaman baru, pinjaman masih berlaku sampai disetujui',
    trx($RL1)['type'] === 'PENCAIRAN_PINJAMAN' && str_starts_with(trx($RL1)['doc_no'], 'PJM-') && (int) one('SELECT COUNT(*) FROM loans') === 1 && (int) one('SELECT COUNT(*) FROM v_loan_balances WHERE transaction_id = ?', [$L1]) === 1);
// pembayaran masuk setelah pembalik diajukan -> saat disetujui ditolak
$P3 = payment($beta, 4, '520.000');
$e = violation(fn () => ValidationService::approve(req(), $head, $RL1, ver($RL1), null));
check('pinjaman: pembayaran baru menunggu muncul setelah pembalik diajukan -> pembalik tidak bisa disetujui, tetap menunggu', isset($e['_form']) && str_contains($e['_form'], 'menunggu validasi') && trx($RL1)['status'] === 'MENUNGGU_VALIDASI');
InstallmentService::cancel(req(), $beta, $P3, 'Batal', ver($P3));
ValidationService::approve(req(), $head, $RL1, ver($RL1), null);
check('pinjaman: setelah disetujui pinjaman tidak berlaku; piutang dan bunga 0, kas naik Rp 1.000.000, selisih 0, integritas bersih',
    (int) one('SELECT COUNT(*) FROM v_loan_balances WHERE transaction_id = ?', [$L1]) === 0 && (int) global_()['piutang_beredar'] === 0 && (int) global_()['bunga_dibukukan'] === 0 && cash() === $kasL + 1000000 && (int) global_()['selisih'] === 0 && issues() === 0);
check('pinjaman: halaman detail pinjaman yang dibalik tidak lagi memuat saldo', Transaction::loanDetail($L1)['balance'] === null);
check('pinjaman: pinjaman yang dibalik tidak bisa dibayar lagi', isset(violation(fn () => payment($beta, 4, '100.000'))['amount']));
check('pinjaman: transaksi dicairkan yang sudah dibalik tidak bisa dibalik lagi', str_contains((string) violation(fn () => ReversalService::request(req(), $beta, $L1, 'x'))['_form'], 'sudah punya pembalik'));

// ======================= kas harus cukup (diperiksa saat diajukan DAN saat disetujui) =======================
$sg = approved($head, saving($beta, 4, '2.500.000'));
$drain = rawTrx('BIAYA', null, 2, $beta['id'], cash() - 100000);
ValidationService::approve(req(), $head, $drain, ver($drain), 'Menguras kas untuk uji');
check('kas: kas tersisa Rp 100.000', cash() === 100000);
$e = violation(fn () => ReversalService::request(req(), $beta, $sg, 'x'));
check('kas: pembalik simpanan ditolak dini bila kas kurang (uang sudah dipinjamkan), dengan angka', isset($e['_form']) && str_contains($e['_form'], 'Kas tersedia Rp 100.000') && str_contains($e['_form'], 'Rp 2.500.000'));
check('kas: tawaran menampilkan alasan terblokir', str_contains((string) ReversalService::offer($beta, Transaction::findScoped($beta, $sg))['blocked'], 'Kas tersedia'));
setting('allow_negative_cash', '1');
$rg = ReversalService::request(req(), $beta, $sg, 'Salah anggota');   // diajukan saat pelonggaran menyala
setting('allow_negative_cash', '0');
$e = violation(fn () => ValidationService::approve(req(), $head, $rg, ver($rg), null));
check('kas: saat disetujui diperiksa ulang (pelonggaran sudah mati) -> ditolak, tetap menunggu', isset($e['_form']) && str_contains($e['_form'], 'Kas tersedia') && trx($rg)['status'] === 'MENUNGGU_VALIDASI' && cash() === 100000);
setting('allow_negative_cash', '1');
ValidationService::approve(req(), $head, $rg, ver($rg), null);
setting('allow_negative_cash', '0');
check('kas: dengan "izinkan melebihi kas" pembalik disetujui; kas negatif terdeteksi oleh pemeriksa integritas', cash() === 100000 - 2500000 && (int) one("SELECT COUNT(*) FROM v_integrity_issues WHERE issue = 'KAS_NEGATIF'") === 1);
approved($periksa, saving($beta, 4, '30.000.000'));
check('kas: setelah simpanan baru kas pulih dan integritas bersih', cash() > 0 && issues() === 0 && (int) global_()['selisih'] === 0);

// ======================= pemeriksaan saat disetujui terhadap pembalik yang tidak sah =======================
$sx = approved($head, saving($beta, 4, '7.000'));
$badAmount = rawTrx('SIMPANAN', 4, 2, $beta['id'], 6000, $sx);
$e = violation(fn () => ValidationService::approve(req(), $head, $badAmount, ver($badAmount), null));
check('tidak sah: nominal pembalik berbeda dari asal -> tidak bisa disetujui', isset($e['_form']) && str_contains($e['_form'], 'tidak sama dengan transaksi yang dibalik') && trx($badAmount)['status'] === 'MENUNGGU_VALIDASI');
ValidationService::reject(req(), $head, $badAmount, ver($badAmount), 'Nominal beda');
$pendOrig = saving($beta, 4, '8.000');
$badOrig = rawTrx('SIMPANAN', 4, 2, $beta['id'], 8000, $pendOrig);
$e = violation(fn () => ValidationService::approve(req(), $head, $badOrig, ver($badOrig), null));
check('tidak sah: yang dibalik belum disetujui -> pembalik tidak bisa disetujui', isset($e['_form']) && str_contains($e['_form'], 'tidak lagi berstatus disetujui'));
ValidationService::reject(req(), $head, $badOrig, ver($badOrig), 'Asal belum disetujui');
$L3 = approved($head, loanLastMonth($beta, 5, '1.000.000', '2'));
$P4 = approved($head, payment($beta, 5, '520.000'));
$noAlloc = rawTrx('ANGSURAN', 5, 2, $beta['id'], 520000, $P4);
$e = violation(fn () => ValidationService::approve(req(), $head, $noAlloc, ver($noAlloc), null));
check('tidak sah: pembalik angsuran tanpa kebalikan alokasi asal -> tidak bisa disetujui', isset($e['_form']) && str_contains($e['_form'], 'kebalikan pembagian') && trx($noAlloc)['status'] === 'MENUNGGU_VALIDASI');
ValidationService::reject(req(), $head, $noAlloc, ver($noAlloc), 'Tanpa alokasi');

// periode ditutup
$sc = approved($head, saving($beta, 4, '9.000'));
$rc = ReversalService::request(req(), $beta, $sc, 'Sebelum periode ditutup');
$sd = approved($head, saving($beta, 4, '9.100'));
$pdo->exec("UPDATE periods SET status = 'TUTUP' WHERE id = 1");
check('periode ditutup: transaksi tidak bisa dikoreksi; tawaran kosong; pembalik yang sudah menunggu tidak bisa disetujui',
    str_contains((string) violation(fn () => ReversalService::request(req(), $beta, $sd, 'x'))['_form'], 'ditutup')
    && isset(violation(fn () => ValidationService::approve(req(), $head, $rc, ver($rc), null))['_form']) && trx($rc)['status'] === 'MENUNGGU_VALIDASI');
$pdo->exec("UPDATE periods SET status = 'AKTIF' WHERE id = 1");
ValidationService::approve(req(), $head, $rc, ver($rc), null);
check('periode dibuka lagi: pembalik tadi bisa disetujui', trx($rc)['status'] === 'DISETUJUI');

// ======================= atomik =======================
$sa = approved($head, saving($beta, 4, '11.000'));
$cntA = trxCount();
$seqBefore = (int) one("SELECT last_value FROM number_sequences WHERE seq_key LIKE 'SMP-%'");
$auditA = audits('REVERSAL_CREATED');
$pdo->exec("CREATE TRIGGER trg_tes_gagal BEFORE INSERT ON transaction_validations FOR EACH ROW BEGIN IF NEW.to_status = 'MENUNGGU_VALIDASI' AND NEW.note LIKE 'Koreksi%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'gagal sengaja'; END IF; END");
$boom = false;
try {
    ReversalService::request(req(), $beta, $sa, 'Akan gagal');
} catch (PDOException $e) {
    $boom = str_contains($e->getMessage(), 'gagal sengaja');
} finally {
    $pdo->exec('DROP TRIGGER trg_tes_gagal');
}
check('atomik: kegagalan di tengah pengajuan tidak meninggalkan transaksi, rincian, nomor terpakai, atau audit',
    $boom && trxCount() === $cntA && (int) one("SELECT last_value FROM number_sequences WHERE seq_key LIKE 'SMP-%'") === $seqBefore && audits('REVERSAL_CREATED') === $auditA && (int) one('SELECT COUNT(*) FROM transactions WHERE reverses_id = ?', [$sa]) === 0);
check('atomik: setelah gangguan hilang pengajuan berhasil', trx(ReversalService::request(req(), $beta, $sa, 'Berhasil'))['status'] === 'MENUNGGU_VALIDASI');

// ======================= tawaran ke tampilan =======================
$fresh = approved($head, saving($beta, 4, '12.000'));
$offerOk = ReversalService::offer($beta, Transaction::findScoped($beta, $fresh));
check('tawaran: Ketua Regu pemilik melihat formulir; Head/Anggota/regu lain tidak', $offerOk['can'] === true && $offerOk['blocked'] === null && $offerOk['live'] === null
    && ReversalService::offer($head, Transaction::findScoped($head, $fresh))['can'] === false && ReversalService::offer($purwati, Transaction::findScoped($head, $fresh))['can'] === false
    && ReversalService::offer($anggota4, Transaction::findScoped($anggota4, $fresh))['can'] === false);
check('tawaran: transaksi yang belum disetujui dan pembalik itu sendiri tidak menawarkan koreksi', ReversalService::offer($beta, Transaction::findScoped($beta, $pend))['can'] === false && ReversalService::offer($beta, Transaction::findScoped($beta, $r1))['can'] === false);
check('dampak: kas dan tabungan per jenis', ReversalService::impact('SIMPANAN', 100) === ['cash_delta' => -100, 'savings_delta' => -100] && ReversalService::impact('ANGSURAN', 100) === ['cash_delta' => -100, 'savings_delta' => 0]
    && ReversalService::impact('PENCAIRAN_PINJAMAN', 100) === ['cash_delta' => 100, 'savings_delta' => 0] && ReversalService::impact('PENARIKAN', 100) === ['cash_delta' => 100, 'savings_delta' => 100] && ReversalService::impact('BIAYA', 100) === ['cash_delta' => 100, 'savings_delta' => 0]);

// ======================= invarian akhir, lalu pemeriksa integritas bergigi =======================
check('akhir: invarian kas + piutang = tabungan + bunga - biaya tetap 0 dan integritas bersih', (int) global_()['selisih'] === 0 && issues() === 0);

$mismatch = rawTrx('SIMPANAN', 4, 2, $beta['id'], 1234, $fresh, null, 'DISETUJUI');
check('integritas: pembalik disetujui yang nominalnya beda dari asal terdeteksi (PEMBALIK_TIDAK_SESUAI)', (int) one("SELECT COUNT(*) FROM v_integrity_issues WHERE issue = 'PEMBALIK_TIDAK_SESUAI'") === 1);
$forced = rawTrx('PENCAIRAN_PINJAMAN', 5, 2, $beta['id'], 1000000, $L3, null, 'DISETUJUI');
check('integritas: pinjaman dibalik padahal masih ada pembayaran bersih terdeteksi (PINJAMAN_DIBALIK_MASIH_ADA_PEMBAYARAN) dan selisih global tidak nol',
    (int) one("SELECT COUNT(*) FROM v_integrity_issues WHERE issue = 'PINJAMAN_DIBALIK_MASIH_ADA_PEMBAYARAN'") === 1 && (int) global_()['selisih'] !== 0);

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
