<?php
declare(strict_types=1);

/**
 * Tes pembayaran angsuran (Phase 8): alokasi cicilan tertua dulu, pencadangan, kelebihan bayar, alur status,
 * audit, saldo, tagihan, cakupan. MENGOSONGKAN database uji; tidak menyentuh data sungguhan.
 *   C:\xampp\php\php.exe tests\payment.php
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
use App\Services\InstallmentService;
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
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.1.1.1', 'HTTP_USER_AGENT' => 'tes-angsuran']);
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
/** Alokasi tersimpan: [[doc pinjaman, seq, jumlah], ...] urut cicilan tertua dulu. */
function alloc(int $trxId): array
{
    return array_map(static fn (array $r): array => [$r['loan_no'], (int) $r['seq'], (int) $r['amount']], Transaction::allocations($trxId));
}
function outstanding(int $memberId): int
{
    return (int) one('SELECT COALESCE(SUM(outstanding), 0) FROM v_loan_balances WHERE member_id = ?', [$memberId]);
}

// ---------------- fixture: bulan relatif terhadap hari ini ----------------
$mm = [];   // indeks bulan: 0 = bulan ini, 1 = sebulan lalu, ...
for ($i = 6; $i >= 1; $i--) {
    $mm[$i] = date('Y-m-01', strtotime("-{$i} month", strtotime(date('Y-m-01'))));
}
$mm[0] = date('Y-m-01');
for ($i = 1; $i <= 3; $i++) {
    $mm[-$i] = date('Y-m-01', strtotime("+{$i} month", strtotime($mm[0])));
}
$pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji', '{$mm[6]}', '{$mm[-3]}', 'AKTIF')");
foreach ($mm as $d) {
    $pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1, '{$d}')");
}
$mid = [];
foreach ($pdo->query('SELECT id, month_date FROM period_months')->fetchAll() as $r) {
    $mid[$r['month_date']] = (int) $r['id'];
}
$today = date('Y-m-d');

foreach ([1 => 'Ketua Alfa', 2 => 'Anggota Alfa', 3 => 'Ketua Beta', 4 => 'Anggota Beta', 5 => 'Anggota Tanpa Pinjaman', 6 => 'Anggota Nonaktif'] as $n => $name) {
    $pdo->prepare('INSERT INTO members (member_no, name, active_from, status) VALUES (?, ?, ?, ?)')->execute([sprintf('AGT-%03d', $n), $name, $mm[6], $n === 6 ? 'NONAKTIF' : 'AKTIF']);
}
$pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu Alfa', 1), ('Regu Beta', 3)");
$pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'{$mm[6]}'),(2,1,'{$mm[6]}'),(3,2,'{$mm[6]}'),(4,2,'{$mm[6]}'),(5,1,'{$mm[6]}'),(6,1,'{$mm[6]}')");

$ids = [];
foreach ([['ketua.alfa', ['KETUA_REGU'], 'AGT-001'], ['ketua.beta', ['KETUA_REGU'], 'AGT-003'], ['kepala', ['HEAD'], null], ['periksa', ['PEMERIKSA'], null], ['anggota2', ['ANGGOTA'], 'AGT-002']] as [$u, $roles, $no]) {
    $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
}
$alfa = User::findActive($ids['ketua.alfa']);
$beta = User::findActive($ids['ketua.beta']);
$head = User::findActive($ids['kepala']);
$pemeriksa = User::findActive($ids['periksa']);
$anggota2  = User::findActive($ids['anggota2']);

// modal kas agar invarian berarti, lalu pinjaman yang sudah DISETUJUI lewat layanan sungguhan
$sv = SavingService::create(req(), $alfa, SavingService::parse(['member_id' => 2, 'period_month_id' => $mid[$mm[6]], 'kind' => 'SUKARELA', 'amount' => '20.000.000', 'trx_date' => $mm[6]])[1], false);
approve($sv);
function loan(array $actor, int $memberId, string $monthDate, string $principal, string $tenor): int
{
    global $mid;
    [$e, $d] = LoanService::parse(['member_id' => $memberId, 'period_month_id' => $mid[$monthDate], 'principal' => $principal, 'tenor' => $tenor, 'trx_date' => $monthDate]);
    if ($e !== []) {
        throw new RuntimeException(json_encode($e));
    }
    $id = LoanService::create(req(), $actor, $d, false);
    approve($id);
    return $id;
}
$loanA = loan($alfa, 2, $mm[4], '3.000.000', '3');   // jatuh tempo m3, m2, m1: 1.060.000 x 3 (bunga 180.000)
$loanB = loan($alfa, 2, $mm[3], '1.000.000', '2');   // jatuh tempo m2, m1: 520.000 x 2 (bunga 40.000)
$loanC = loan($beta, 4, $mm[4], '1.000.000', '1');   // regu Beta: jatuh tempo m3: 1.020.000
$loanD = loan($beta, 4, $mm[1], '1.000.000', '2');   // regu Beta: sebulan lalu, cicilan 1 jatuh tempo BULAN INI (520.000), cicilan 2 bulan depan
$docA = trx($loanA)['doc_no'];
$docB = trx($loanB)['doc_no'];
$total2 = 3180000 + 1040000;   // sisa seluruh pinjaman anggota 2 = 4.220.000

function form(array $over = []): array
{
    global $mid, $mm, $today;
    return $over + ['member_id' => 2, 'period_month_id' => $mid[$mm[0]], 'amount' => '1.060.000', 'trx_date' => $today, 'description' => ''];
}
function pay(array $actor, array $over = [], bool $submit = false): int
{
    [$errors, $data] = InstallmentService::parse(form($over));
    if ($errors !== []) {
        throw new RuntimeException('fixture tidak sah: ' . json_encode($errors));
    }
    return InstallmentService::create(req(), $actor, $data, $submit);
}

check('fixture: pinjaman disetujui, sisa anggota 2 = 4.220.000', outstanding(2) === $total2 && outstanding(4) === 1020000 + 1040000 && (int) global_()['selisih'] === 0);

// ======================= parse =======================
[$e, $d] = InstallmentService::parse(form());
check('parse: isian sah -> 1.060.000, tanpa galat', $e === [] && $d['amount'] === 1060000 && $d['description'] === null);
foreach (['' => 'kosong', 'abc' => 'huruf', '0' => 'nol', '-1000' => 'negatif', '1.5' => 'desimal', '1.000.000.001' => 'di atas batas'] as $bad => $why) {
    check("parse: jumlah {$why} ditolak", isset(InstallmentService::parse(form(['amount' => (string) $bad]))[0]['amount']));
}
check('parse: anggota, bulan, tanggal wajib dan sah', isset(InstallmentService::parse(form(['member_id' => 0]))[0]['member_id']) && isset(InstallmentService::parse(form(['period_month_id' => 0]))[0]['period_month_id'])
    && isset(InstallmentService::parse(form(['trx_date' => '2026-02-30']))[0]['trx_date']) && isset(InstallmentService::parse(form(['trx_date' => '']))[0]['trx_date']));

// ======================= alokasi: cicilan tertua dulu =======================
$raw = fn (int $amount, int $member = 2, ?string $month = null) => InstallmentService::allocate($pdo, $member, $month ?? $mm[0], $amount, null);
$pairs = fn (array $a): array => array_map(static fn (array $x): array => [$x['loan_no'], $x['seq'], $x['amount']], $a);
check('alokasi: 1.060.000 = cicilan 1 pinjaman A persis (jatuh tempo paling tua)', $pairs($raw(1060000)) === [[$docA, 1, 1060000]]);
check('alokasi: 2.000.000 = A1 penuh + sebagian A2 (940.000), A2 lebih dulu dari B1 karena pinjaman A lebih lama', $pairs($raw(2000000)) === [[$docA, 1, 1060000], [$docA, 2, 940000]]);
check('alokasi: bulan jatuh tempo yang sama diurut per pinjaman lalu cicilan (A2 lalu B1)', $pairs($raw(1580000)) === [[$docA, 1, 1060000], [$docA, 2, 520000]] && $pairs($raw(2120000)) === [[$docA, 1, 1060000], [$docA, 2, 1060000]]);
check('alokasi: 2.700.000 -> A1, A2, B1 penuh (jatuh tempo bulan yang sama), lalu A3 sebagian', $pairs($raw(2700000)) === [[$docA, 1, 1060000], [$docA, 2, 1060000], [$docB, 1, 520000], [$docA, 3, 60000]]);
$full = $raw($total2);
check('alokasi: melunasi semua (4.220.000) = 5 cicilan penuh, jumlah persis', count($full) === 5 && array_sum(array_column($full, 'amount')) === $total2);
check('alokasi: jumlah hasil bagi SELALU = jumlah dibayar (200 nilai acak)', (function () use ($raw, $total2): bool {
    mt_srand(11);
    for ($i = 0; $i < 200; $i++) {
        $n = mt_rand(1, $total2);
        if (array_sum(array_column($raw($n), 'amount')) !== $n) {
            return false;
        }
    }
    return true;
})());
$over = violation(fn () => $raw($total2 + 1));
check('alokasi: melebihi sisa tagihan ditolak dengan angka sisa (tanpa kelebihan bayar)', isset($over['amount']) && str_contains($over['amount'], 'Rp 4.220.000'));
check('alokasi: anggota tanpa pinjaman ditolak', isset(violation(fn () => $raw(1000, 5))['amount']) && str_contains(violation(fn () => $raw(1000, 5))['amount'], 'tidak punya cicilan'));
check('alokasi: pinjaman yang dicairkan SETELAH/DI bulan pembayaran tidak bisa dibayar (bulan m3: hanya pinjaman A)', array_sum(array_column(InstallmentService::payableInstallments($pdo, 2, $mm[3], null), 'room')) === 3180000
    && array_unique(array_column(InstallmentService::payableInstallments($pdo, 2, $mm[3], null), 'loan_no')) === [$docA]);
check('alokasi: bulan pembayaran = bulan pencairan pertama tidak punya cicilan yang bisa dibayar', isset(violation(fn () => $raw(1000, 2, $mm[4]))['amount']));

// ======================= buat draft =======================
$g0 = global_();
$o0 = outstanding(2);
$idp1 = pay($alfa, ['amount' => '2.000.000', 'description' => 'Setoran pertemuan']);
$t1 = trx($idp1);
check('buat: ANGSURAN berstatus DRAFT, ANG-<tahun>-<urut>, atas nama pembuat dan regunya', $t1['type'] === 'ANGSURAN' && $t1['status'] === 'DRAFT' && str_starts_with($t1['doc_no'], 'ANG-' . date('Y') . '-') && (int) $t1['team_id'] === 1 && (int) $t1['created_by'] === $alfa['id'] && (int) $t1['amount'] === 2000000);
check('buat: alokasi tersimpan (A1 penuh + A2 940.000) dan berjumlah persis nominal', alloc($idp1) === [[$docA, 1, 1060000], [$docA, 2, 940000]] && (int) one('SELECT SUM(amount) FROM installment_payments WHERE transaction_id = ?', [$idp1]) === 2000000);
check('buat: audit PAYMENT_CREATED memuat alokasi', audits('PAYMENT_CREATED') === 1 && count(lastAudit('PAYMENT_CREATED')['after']['allocations']) === 2);
check('buat: draft tidak mengubah sisa pinjaman, kas, maupun status cicilan', outstanding(2) === $o0 && global_() === $g0 && (int) one('SELECT paid_amount FROM v_installment_status WHERE loan_id = (SELECT id FROM loans WHERE transaction_id = ?) AND seq = 1', [$loanA]) === 0);

// ======================= aturan pencatatan =======================
$try = fn (array $over, ?array $actor = null) => violation(fn () => pay($actor ?? $alfa, $over));
check('aturan: anggota regu lain ditolak tanpa menyebut nama', isset($try(['member_id' => 4])['member_id']) && !str_contains($try(['member_id' => 4])['member_id'], 'Beta'));
check('aturan: anggota nonaktif ditolak', isset($try(['member_id' => 6])['member_id']));
check('aturan: Pemeriksa, Head, Anggota tidak boleh mencatat', isset($try([], $pemeriksa)['_form']) && isset($try([], $head)['_form']) && isset($try([], $anggota2)['_form']));
check('aturan: akun tanpa regu aktif ditolak', isset($try([], ['id' => $alfa['id'], 'username' => 'x', 'roles' => ['KETUA_REGU'], 'team_id' => null])['_form']));
check('aturan: bulan di masa depan dan tanggal di masa depan ditolak', isset($try(['period_month_id' => $mid[$mm[-1]]])['period_month_id']) && isset($try(['trx_date' => date('Y-m-d', strtotime('+1 day'))])['trx_date']));
check('aturan: tanggal sebelum awal bulan siklus ditolak', isset($try(['trx_date' => date('Y-m-d', strtotime('-1 day', strtotime($mm[0])))])['trx_date']));
$dup = $try(['amount' => '2.000.000']);
check('duplikat: pembayaran identik diminta konfirmasi, menyebut nomor', isset($dup['duplicate']) && str_contains($dup['duplicate'], $t1['doc_no']));
check('duplikat: lolos bila dikonfirmasi; jumlah beda bukan duplikat', violation(fn () => pay($alfa, ['amount' => '2.000.000', 'confirm_duplicate' => '1'])) === null && violation(fn () => pay($alfa, ['amount' => '500.000'])) === null);
check('aturan: pelanggaran tidak menghabiskan nomor (nomor terakhir = jumlah transaksi)', (int) one("SELECT last_value FROM number_sequences WHERE seq_key = ?", ['ANG-' . date('Y')]) === (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'ANGSURAN'"));

// atomik: gagal saat alokasi ditulis
$seq = (int) one("SELECT last_value FROM number_sequences WHERE seq_key = ?", ['ANG-' . date('Y')]);
$cnt = (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'ANGSURAN'");
Database::pdo()->exec("CREATE TRIGGER trg_tes_gagal BEFORE INSERT ON installment_payments FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'gagal sengaja'; END");
$boom = false;
try {
    pay($alfa, ['amount' => '300.000']);
} catch (PDOException $e) {
    $boom = str_contains($e->getMessage(), 'gagal sengaja');
} finally {
    Database::pdo()->exec('DROP TRIGGER trg_tes_gagal');
}
check('atomik: kegagalan menulis alokasi membatalkan transaksi dan nomor', $boom && (int) one("SELECT last_value FROM number_sequences WHERE seq_key = ?", ['ANG-' . date('Y')]) === $seq && (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'ANGSURAN'") === $cnt);

// ======================= ubah draft =======================
$idu = pay($alfa, ['amount' => '1.000.000', 'description' => 'awal']);
$ver = trx($idu)['updated_at'];
sleep(1);   // updated_at berpresisi detik
[, $du] = InstallmentService::parse(form(['amount' => '1.600.000', 'description' => 'awal']));
InstallmentService::update(req(), $alfa, $idu, $du, $ver, false);
check('ubah: jumlah dan alokasi dihitung ulang (1.600.000 = A1 penuh + A2 540.000)', (int) trx($idu)['amount'] === 1600000 && alloc($idu) === [[$docA, 1, 1060000], [$docA, 2, 540000]]);
$au = lastAudit('PAYMENT_UPDATED');
check('ubah: audit hanya memuat field berubah (jumlah dan alokasi)', array_keys($au['after']) === ['amount', 'allocations'] && $au['before']['amount'] === 1000000 && $au['after']['amount'] === 1600000);
$n = audits('PAYMENT_UPDATED');
InstallmentService::update(req(), $alfa, $idu, $du, trx($idu)['updated_at'], false);
check('ubah: tanpa perubahan tidak menulis audit', audits('PAYMENT_UPDATED') === $n);
check('ubah: versi basi ditolak', isset(violation(fn () => InstallmentService::update(req(), $alfa, $idu, $du, $ver, false))['_form']));
check('ubah: bukan pembuat ditolak (ketua regu lain, Head)', isset(violation(fn () => InstallmentService::update(req(), $beta, $idu, $du, trx($idu)['updated_at'], false))['_form']) && isset(violation(fn () => InstallmentService::update(req(), $head, $idu, $du, trx($idu)['updated_at'], false))['_form']));
check('ubah: melebihi sisa tagihan ditolak dan alokasi lama utuh', isset(violation(fn () => InstallmentService::update(req(), $alfa, $idu, InstallmentService::parse(form(['amount' => '9.000.000']))[1], trx($idu)['updated_at'], false))['amount']) && alloc($idu) === [[$docA, 1, 1060000], [$docA, 2, 540000]]);

// ======================= ajukan + pencadangan =======================
// $idp1 (2.000.000) diajukan: mencadangkan A1 dan 940.000 dari A2
InstallmentService::submit(req(), $alfa, $idp1, trx($idp1)['updated_at']);
check('ajukan: DRAFT -> MENUNGGU_VALIDASI + riwayat + audit', trx($idp1)['status'] === 'MENUNGGU_VALIDASI' && (int) one('SELECT COUNT(*) FROM transaction_validations WHERE transaction_id = ?', [$idp1]) === 1 && audits('PAYMENT_SUBMITTED') === 1);
check('ajukan: yang menunggu belum mengubah sisa pinjaman atau kas', outstanding(2) === $total2 && (int) global_()['kas_tersedia'] === (int) $g0['kas_tersedia']);
$room = array_sum(array_column(InstallmentService::payableInstallments($pdo, 2, $mm[0], null), 'room'));
check('pencadangan: ruang bayar berkurang sebesar yang menunggu (4.220.000 - 2.000.000 = 2.220.000)', $room === $total2 - 2000000);
$fmt = fn (int $n): string => number_format($n, 0, ',', '.');
check('pencadangan: 1 rupiah di atas ruang ditolak (draft pun tidak bisa dibuat)', isset(violation(fn () => pay($alfa, ['amount' => $fmt($room + 1), 'confirm_duplicate' => '1']))['amount']));
$idp2 = pay($alfa, ['amount' => $fmt($room), 'confirm_duplicate' => '1'], true);
check('pencadangan: tepat sebesar ruang diterima dan diajukan (2.220.000)', trx($idp2)['status'] === 'MENUNGGU_VALIDASI' && array_sum(array_map('intval', array_column(Transaction::allocations($idp2), 'amount'))) === $room);
check('pencadangan: tak ada cicilan yang dicadangkan melebihi tagihannya (MENUNGGU + DISETUJUI)', (int) one("SELECT COUNT(*) FROM (SELECT li.id FROM installment_payments ip JOIN transactions t ON t.id = ip.transaction_id AND t.status IN ('MENUNGGU_VALIDASI','DISETUJUI') JOIN loan_installments li ON li.id = ip.installment_id GROUP BY li.id, li.amount_due HAVING SUM(ip.amount) > li.amount_due) x") === 0);
check('pencadangan: sesudahnya tidak ada ruang lagi, pembayaran baru ditolak', isset(violation(fn () => pay($alfa, ['amount' => '1.000', 'confirm_duplicate' => '1']))['amount']));
$e = violation(fn () => InstallmentService::submit(req(), $alfa, $idu, trx($idu)['updated_at']));
check('ajukan: draft yang ruangnya habis dicadangkan pembayaran lain ditolak, tetap DRAFT dan alokasi lama utuh', isset($e['amount']) && trx($idu)['status'] === 'DRAFT' && alloc($idu) === [[$docA, 1, 1060000], [$docA, 2, 540000]]);
InstallmentService::cancel(req(), $alfa, $idp2, 'dibatalkan untuk uji', trx($idp2)['updated_at']);
check('batal: pembayaran yang menunggu dibatalkan melepas cadangan (ruang kembali 2.220.000)', trx($idp2)['status'] === 'DIBATALKAN' && array_sum(array_column(InstallmentService::payableInstallments($pdo, 2, $mm[0], null), 'room')) === $room && lastAudit('PAYMENT_CANCELLED')['after']['note'] === 'dibatalkan untuk uji');

// hitung ulang saat diajukan: idu dialokasikan sebelum idp1 diajukan, jadi bertumpuk dengannya
InstallmentService::submit(req(), $alfa, $idu, trx($idu)['updated_at']);
check('ajukan: alokasi dihitung ulang dengan keadaan terbaru (A2 sisa 120.000, B1, A3), jumlah tetap 1.600.000', alloc($idu) === [[$docA, 2, 120000], [$docB, 1, 520000], [$docA, 3, 960000]] && trx($idu)['status'] === 'MENUNGGU_VALIDASI');
check('ajukan: perubahan alokasi tercatat di audit PAYMENT_REALLOCATED (sebelum/sesudah)', audits('PAYMENT_REALLOCATED') === 1 && count(lastAudit('PAYMENT_REALLOCATED')['before']['allocations']) === 2 && count(lastAudit('PAYMENT_REALLOCATED')['after']['allocations']) === 3);
check('ajukan: diajukan ulang, versi basi, dan bukan pembuat ditolak', isset(violation(fn () => InstallmentService::submit(req(), $alfa, $idu, trx($idu)['updated_at']))['_form']) && isset(violation(fn () => InstallmentService::submit(req(), $alfa, $idp1, '2000-01-01 00:00:00'))['_form']) && isset(violation(fn () => InstallmentService::submit(req(), $beta, $idp1, trx($idp1)['updated_at']))['_form']));
check('ubah: yang sudah diajukan tidak bisa diubah', isset(violation(fn () => InstallmentService::update(req(), $alfa, $idu, $du, trx($idu)['updated_at'], false))['_form']));
check('batal: alasan wajib, bukan pembuat ditolak; dibatalkan tidak bisa dibatalkan lagi', isset(violation(fn () => InstallmentService::cancel(req(), $alfa, $idp1, ' ', trx($idp1)['updated_at']))['note']) && isset(violation(fn () => InstallmentService::cancel(req(), $beta, $idp1, 'iseng', trx($idp1)['updated_at']))['_form'])
    && isset(violation(fn () => InstallmentService::cancel(req(), $alfa, $idp2, 'lagi', trx($idp2)['updated_at']))['_form']));
check('silang: layanan lain tidak bisa menyentuh angsuran, dan sebaliknya', isset(violation(fn () => SavingService::cancel(req(), $alfa, $idp1, 'x', trx($idp1)['updated_at']))['_form']) && isset(violation(fn () => LoanService::submit(req(), $alfa, $idp1, trx($idp1)['updated_at']))['_form'])
    && isset(violation(fn () => InstallmentService::submit(req(), $alfa, $sv, trx($sv)['updated_at']))['_form']) && isset(violation(fn () => InstallmentService::cancel(req(), $alfa, $loanA, 'x', trx($loanA)['updated_at']))['_form']));

// ======================= tagihan dan cakupan =======================
$dueRows = fn (array $u) => array_column(Transaction::dueByMember($u), null, 'member_no');
$h = $dueRows($head);
check('tagihan: Head melihat anggota 2 dan 4 saja (yang punya sisa pinjaman)', array_keys($h) === ['AGT-002', 'AGT-004'] || array_keys($h) === ['AGT-004', 'AGT-002']);
check('tagihan: anggota 2 = sisa 4.220.000, jatuh tempo 4.220.000, tunggakan 4.220.000, menunggu 3.600.000', (int) $h['AGT-002']['outstanding'] === $total2 && (int) $h['AGT-002']['due_now'] === $total2 && (int) $h['AGT-002']['overdue'] === $total2 && (int) $h['AGT-002']['pending'] === 3600000);
check('tagihan: anggota 4 = sisa 2.060.000, jatuh tempo sampai bulan ini 1.540.000, tunggakan HANYA yang sebelum bulan ini 1.020.000, tidak ada yang menunggu', (int) $h['AGT-004']['outstanding'] === 2060000 && (int) $h['AGT-004']['due_now'] === 1540000 && (int) $h['AGT-004']['overdue'] === 1020000 && (int) $h['AGT-004']['pending'] === 0);
check('tagihan: urut tunggakan terbesar dulu', array_column(Transaction::dueByMember($head), 'member_no')[0] === 'AGT-002');
check('tagihan: ketua Alfa hanya regunya, ketua Beta hanya regunya, Anggota hanya dirinya', array_keys($dueRows($alfa)) === ['AGT-002'] && array_keys($dueRows($beta)) === ['AGT-004'] && array_keys($dueRows($anggota2)) === ['AGT-002']);
check('tagihan: tanpa izin = kosong', Transaction::dueByMember(['roles' => [], 'id' => 1]) === [] && Transaction::dueByMember(null) === []);
check('cakupan: ketua Beta tidak melihat angsuran regu Alfa; Head melihat; Anggota 2 hanya miliknya', Transaction::search($beta, ['type' => 'ANGSURAN'], 1)['pager']['total'] === 0 && Transaction::search($head, ['type' => 'ANGSURAN'], 1)['pager']['total'] === (int) one("SELECT COUNT(*) FROM transactions WHERE type = 'ANGSURAN'")
    && Transaction::findScoped($beta, $idp1) === null && Transaction::findScoped($anggota2, $idp1) !== null);
check('cakupan: alokasi terbaca lengkap dengan nomor pinjaman, cicilan, dan jatuh tempo', (function () use ($idu, $docA): bool {
    $a = Transaction::allocations($idu);
    return count($a) === 3 && $a[0]['loan_no'] === $docA && (int) $a[0]['seq'] === 2 && $a[0]['due_month'] !== '';
})());

// ======================= setelah DISETUJUI =======================
$g1 = global_();
approve($idp1);
approve($idu);
$g2 = global_();
check('disetujui: sisa pinjaman anggota 2 turun 3.600.000 (4.220.000 -> 620.000)', outstanding(2) === $total2 - 3600000);
check('disetujui: kas naik 3.600.000, piutang turun 3.600.000, selisih invarian tetap 0', (int) $g2['kas_tersedia'] - (int) $g1['kas_tersedia'] === 3600000 && (int) $g1['piutang_beredar'] - (int) $g2['piutang_beredar'] === 3600000 && (int) $g2['selisih'] === 0);
check('disetujui: tabungan dan bunga dibukukan tidak berubah oleh angsuran', (int) $g2['saldo_tabungan'] === (int) $g1['saldo_tabungan'] && (int) $g2['bunga_dibukukan'] === (int) $g1['bunga_dibukukan']);
check('disetujui: pemeriksa integritas bersih (alokasi = nominal, tidak ada kelebihan bayar)', (int) one('SELECT COUNT(*) FROM v_integrity_issues') === 0);
check('disetujui: cicilan A1 dan A2 lunas (A2 = 940.000 + 120.000)', (int) one('SELECT remaining_amount FROM v_installment_status WHERE loan_id = (SELECT id FROM loans WHERE transaction_id = ?) AND seq = 1', [$loanA]) === 0
    && (int) one('SELECT remaining_amount FROM v_installment_status WHERE loan_id = (SELECT id FROM loans WHERE transaction_id = ?) AND seq = 2', [$loanA]) === 0);
check('disetujui: yang sudah disetujui tidak bisa dibatalkan atau diubah', isset(violation(fn () => InstallmentService::cancel(req(), $alfa, $idp1, 'oops', trx($idp1)['updated_at']))['_form']) && isset(violation(fn () => InstallmentService::update(req(), $alfa, $idp1, $du, trx($idp1)['updated_at'], false))['_form']));
$dd = $dueRows($head)['AGT-002'];
check('tagihan: setelah disetujui sisa = 620.000 dan menunggu validasi = 0', (int) $dd['outstanding'] === 620000 && (int) $dd['pending'] === 0);

// pelunasan
$idLast = pay($alfa, ['amount' => '620.000'], true);
check('pelunasan: 620.000 = sisa A3 (100.000) + B2 (520.000), tepat sisa tagihan', alloc($idLast) === [[$docA, 3, 100000], [$docB, 2, 520000]]);
approve($idLast);
check('pelunasan: kedua pinjaman LUNAS, sisa 0, invarian 0, integritas bersih', outstanding(2) === 0 && (int) one("SELECT COUNT(*) FROM v_loan_balances WHERE member_id = 2 AND loan_status = 'LUNAS'") === 2 && (int) global_()['selisih'] === 0 && (int) one('SELECT COUNT(*) FROM v_integrity_issues') === 0);
check('pelunasan: tidak bisa dibayar lagi setelah lunas (tanpa kelebihan bayar)', isset(violation(fn () => pay($alfa, ['amount' => '1.000']))['amount']));
check('tagihan: anggota lunas hilang dari daftar tagihan', array_keys($dueRows($head)) === ['AGT-004']);

// pinjaman anggota regu Beta (cicilan 1 bulan) dibayar sebagian lalu sisanya
$idB1 = pay($beta, ['member_id' => 4, 'amount' => '400.000'], true);
check('regu Beta: pembayaran sebagian 400.000 dialokasikan ke satu-satunya cicilan', alloc($idB1) === [[trx($loanC)['doc_no'], 1, 400000]] && (int) trx($idB1)['team_id'] === 2);
$idB2 = pay($beta, ['member_id' => 4, 'amount' => '620.000'], true);
check('regu Beta: pembayaran kedua melunasi sisa pinjaman C (620.000); kelebihan atas total sisa (1.040.000 pinjaman D) ditolak', alloc($idB2) === [[trx($loanC)['doc_no'], 1, 620000]] && isset(violation(fn () => pay($beta, ['member_id' => 4, 'amount' => '1.040.001']))['amount']));
check('regu Beta: pembayaran berikutnya mengisi pinjaman D: cicilan 1 (bulan ini) dulu, baru cicilan 2', alloc(pay($beta, ['member_id' => 4, 'amount' => '600.000'])) === [[trx($loanD)['doc_no'], 1, 520000], [trx($loanD)['doc_no'], 2, 80000]]);

echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
