<?php
declare(strict_types=1);

/**
 * Tes database. MENGOSONGKAN database uji lalu membangunnya dari migrasi.
 *   C:\xampp\php\php.exe tests\db.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Migrator;
use App\Services\NumberSequence;

$testDb = (string) App\Core\Env::get('DB_TEST_NAME', 'simpan_pinjam_adem_ayem_test');
if ($testDb === Config::get('database.name') || !str_ends_with($testDb, '_test')) {
    fwrite(STDERR, "Database uji harus berakhiran _test dan berbeda dari database aplikasi.\n");
    exit(1);
}

$admin = [(string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass')];
Database::connect($admin[0], $admin[1], '')->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = Database::connect($admin[0], $admin[1], $testDb);
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

/** Jalankan SQL yang HARUS ditolak database; true bila ditolak dengan pesan berisi $needle. */
function rejects(PDO $pdo, string $sql, array $params, string $needle = ''): bool
{
    try {
        $pdo->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        return $needle === '' || stripos($e->getMessage(), $needle) !== false;
    }
    return false;
}

function one(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

$seq = 0;

/** Buat transaksi DRAFT + nomor. */
function mkTrx(PDO $pdo, string $type, ?int $memberId, int $amount, int $monthId, ?int $reverses = null): int
{
    global $seq;
    $seq++;
    $pdo->beginTransaction();
    $no = NumberSequence::forTransaction($pdo, $type, 2026);
    $pdo->prepare('INSERT INTO transactions (trx_no, doc_no, type, member_id, period_month_id, trx_date, amount, reverses_id) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$no['trx_no'], $no['doc_no'], $type, $memberId, $monthId, '2026-03-01', $amount, $reverses]);
    $id = (int) $pdo->lastInsertId();
    $pdo->commit();
    return $id;
}

function setStatus(PDO $pdo, int $id, string $to): void
{
    $from = (string) one($pdo, 'SELECT status FROM transactions WHERE id = ?', [$id]);
    $pdo->prepare('UPDATE transactions SET status = ?, status_changed_at = NOW() WHERE id = ?')->execute([$to, $id]);
    $pdo->prepare('INSERT INTO transaction_validations (transaction_id, from_status, to_status, note) VALUES (?,?,?,?)')->execute([$id, $from, $to, 'uji']);
}

function approve(PDO $pdo, int $id): void
{
    setStatus($pdo, $id, 'MENUNGGU_VALIDASI');
    setStatus($pdo, $id, 'DISETUJUI');
}

function summary(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM v_global_summary')->fetch();
}

/** Buat pinjaman DRAFT lengkap dengan jadwal; kembalikan [trxId, loanId, [installmentIds]]. */
function mkLoan(PDO $pdo, int $memberId, int $principal, int $tenor, array $monthIds): array
{
    $interest = intdiv($principal * 2 * $tenor, 100);
    $trx = mkTrx($pdo, 'PENCAIRAN_PINJAMAN', $memberId, $principal, $monthIds[0]);
    $pdo->prepare('INSERT INTO loans (transaction_id, member_id, principal, tenor_months, rate_pct_month, total_interest, disbursed_on) VALUES (?,?,?,?,?,?,?)')
        ->execute([$trx, $memberId, $principal, $tenor, '2.00', $interest, '2026-03-01']);
    $loanId = (int) $pdo->lastInsertId();
    $total = $principal + $interest;
    $base = intdiv($total, $tenor);
    $ids = [];
    for ($i = 1; $i <= $tenor; $i++) {
        $amount = $i === $tenor ? $total - $base * ($tenor - 1) : $base;
        $pdo->prepare('INSERT INTO loan_installments (loan_id, seq, due_month_id, amount_due) VALUES (?,?,?,?)')->execute([$loanId, $i, $monthIds[$i], $amount]);
        $ids[] = (int) $pdo->lastInsertId();
    }
    return [$trx, $loanId, $ids];
}

// ---------- fixture dasar ----------
$pdo->exec("INSERT INTO periods (name, start_date, end_date) VALUES ('Uji', '2026-03-01', '2027-02-28')");
$periodId = (int) $pdo->lastInsertId();
$monthIds = [];
foreach (['2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01'] as $d) {
    $pdo->prepare('INSERT INTO period_months (period_id, month_date) VALUES (?, ?)')->execute([$periodId, $d]);
    $monthIds[] = (int) $pdo->lastInsertId();
}
foreach ([['AGT-001', 'Ani'], ['AGT-002', 'Budi'], ['AGT-003', 'Citra']] as [$no, $name]) {
    $pdo->prepare("INSERT INTO members (member_no, name, active_from) VALUES (?, ?, '2026-03-01')")->execute([$no, $name]);
}
$ani = (int) one($pdo, "SELECT id FROM members WHERE member_no='AGT-001'");
$budi = (int) one($pdo, "SELECT id FROM members WHERE member_no='AGT-002'");
$citra = (int) one($pdo, "SELECT id FROM members WHERE member_no='AGT-003'");

// ---------- data acuan ----------
check('seed: 4 peran', (int) one($pdo, 'SELECT COUNT(*) FROM roles') === 4);
check('seed: bagi hasil 40+40+20 = 100', (int) one($pdo, "SELECT SUM(setting_value) FROM settings WHERE setting_key LIKE 'profit_share_%'") === 100);
check('seed: SHU 60+40 = 100', (int) one($pdo, "SELECT SUM(setting_value) FROM settings WHERE setting_key LIKE 'shu_%_pct'") === 100);
check('seed: penarikan default mati', one($pdo, "SELECT setting_value FROM settings WHERE setting_key='withdrawals_enabled'") === '0');
check('migrasi: ulang tidak menerapkan apa pun', $migrator->migrate() === []);

// ---------- penomoran ----------
check('nomor: wajib dalam transaksi', (function () use ($pdo): bool {
    try {
        NumberSequence::next($pdo, 'TRX', 2026);
    } catch (LogicException $e) {
        return true;
    }
    return false;
})());
$pdo->beginTransaction();
$n1 = NumberSequence::next($pdo, 'XYZ', 2026);
$n2 = NumberSequence::next($pdo, 'XYZ', 2026);
$pdo->rollBack();
$pdo->beginTransaction();
$n3 = NumberSequence::next($pdo, 'XYZ', 2026);
$pdo->commit();
check('nomor: format', $n1 === 'XYZ-2026-000001' && $n2 === 'XYZ-2026-000002');
check('nomor: rollback mengembalikan nomor (tanpa celah)', $n3 === 'XYZ-2026-000001');
check('nomor: tahun baru mulai dari 1', (function () use ($pdo): bool {
    $pdo->beginTransaction();
    $n = NumberSequence::next($pdo, 'XYZ', 2027);
    $pdo->rollBack();
    return $n === 'XYZ-2027-000001';
})());

// ---------- regu: satu anggota hanya satu regu aktif ----------
$pdo->prepare('INSERT INTO team_leaders (name, leader_member_id) VALUES (?, ?)')->execute(['Regu Ani', $ani]);
$teamA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO team_leaders (name, leader_member_id) VALUES (?, ?)')->execute(['Regu Budi', $budi]);
$teamB = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (?, ?, '2026-03-01')")->execute([$citra, $teamA]);
check('regu: anggota tidak boleh punya dua regu aktif', rejects($pdo, "INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (?, ?, '2026-04-01')", [$citra, $teamB], 'Duplicate'));
$pdo->prepare("UPDATE member_team_assignments SET valid_to='2026-03-31' WHERE member_id=? AND valid_to IS NULL")->execute([$citra]);
$pdo->prepare("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (?, ?, '2026-04-01')")->execute([$citra, $teamB]);
check('regu: pindah regu setelah menutup yang lama', (int) one($pdo, 'SELECT COUNT(*) FROM member_team_assignments WHERE member_id=?', [$citra]) === 2);
check('regu: satu orang tidak bisa jadi ketua dua regu', rejects($pdo, 'INSERT INTO team_leaders (name, leader_member_id) VALUES (?, ?)', ['Dobel', $ani], 'Duplicate'));

// ---------- constraint transaksi ----------
check('constraint: nominal 0 ditolak', rejects($pdo, "INSERT INTO transactions (trx_no, doc_no, type, member_id, period_month_id, trx_date, amount) VALUES ('T1','D1','SIMPANAN',?,?,'2026-03-01',0)", [$ani, $monthIds[0]]));
check('constraint: nominal negatif ditolak', rejects($pdo, "INSERT INTO transactions (trx_no, doc_no, type, member_id, period_month_id, trx_date, amount) VALUES ('T2','D2','SIMPANAN',?,?,'2026-03-01',-5)", [$ani, $monthIds[0]]));
check('constraint: simpanan tanpa anggota ditolak', rejects($pdo, "INSERT INTO transactions (trx_no, doc_no, type, member_id, period_month_id, trx_date, amount) VALUES ('T3','D3','SIMPANAN',NULL,?,'2026-03-01',100)", [$monthIds[0]]));
check('constraint: biaya boleh tanpa anggota', !rejects($pdo, "INSERT INTO transactions (trx_no, doc_no, type, member_id, period_month_id, trx_date, amount, status) VALUES ('T4','D4','BIAYA',NULL,?,'2026-03-01',100,'DRAFT')", [$monthIds[0]]));
$pdo->exec("DELETE FROM transactions WHERE trx_no='T4'");
check('constraint: trx_no unik', (function () use ($pdo, $ani, $monthIds): bool {
    $pdo->exec("INSERT INTO transactions (trx_no, doc_no, type, member_id, period_month_id, trx_date, amount) VALUES ('DUP','DUP1','SIMPANAN',{$ani},{$monthIds[0]},'2026-03-01',100)");
    $r = rejects($pdo, "INSERT INTO transactions (trx_no, doc_no, type, member_id, period_month_id, trx_date, amount) VALUES ('DUP','DUP2','SIMPANAN',?,?,'2026-03-01',100)", [$ani, $monthIds[0]], 'Duplicate');
    $pdo->exec("DELETE FROM transactions WHERE trx_no='DUP'");
    return $r;
})());

// ---------- siklus status & kekebalan setelah diajukan ----------
$t = mkTrx($pdo, 'SIMPANAN', $ani, 1000000, $monthIds[0]);
$pdo->prepare("INSERT INTO savings (transaction_id, kind) VALUES (?, 'WAJIB')")->execute([$t]);
check('status: DRAFT boleh diubah', !rejects($pdo, 'UPDATE transactions SET amount = 900000 WHERE id = ?', [$t]) && (int) one($pdo, 'SELECT amount FROM transactions WHERE id=?', [$t]) === 900000);
$pdo->prepare('UPDATE transactions SET amount = 1000000 WHERE id = ?')->execute([$t]);
check('status: DRAFT -> DISETUJUI langsung ditolak', rejects($pdo, "UPDATE transactions SET status='DISETUJUI' WHERE id = ?", [$t], 'status'));
check('saldo: DRAFT tidak masuk saldo', (int) summary($pdo)['saldo_tabungan'] === 0);
setStatus($pdo, $t, 'MENUNGGU_VALIDASI');
check('saldo: MENUNGGU_VALIDASI tidak masuk saldo', (int) summary($pdo)['saldo_tabungan'] === 0 && (int) summary($pdo)['kas_tersedia'] === 0);
check('status: nominal tidak boleh diubah setelah diajukan', rejects($pdo, 'UPDATE transactions SET amount = 1 WHERE id = ?', [$t], 'tidak boleh diubah'));
check('status: anggota tidak boleh diganti setelah diajukan', rejects($pdo, 'UPDATE transactions SET member_id = ? WHERE id = ?', [$budi, $t], 'tidak boleh diubah'));
check('status: rincian tidak boleh diubah setelah diajukan', rejects($pdo, "UPDATE savings SET kind='POKOK' WHERE transaction_id = ?", [$t], 'DRAFT'));
check('status: rincian tidak boleh dihapus setelah diajukan', rejects($pdo, 'DELETE FROM savings WHERE transaction_id = ?', [$t], 'DRAFT'));
check('status: transaksi diajukan tidak boleh dihapus', rejects($pdo, 'DELETE FROM transactions WHERE id = ?', [$t], 'tidak boleh dihapus'));
setStatus($pdo, $t, 'DISETUJUI');
check('saldo: DISETUJUI masuk saldo tabungan', (int) summary($pdo)['saldo_tabungan'] === 1000000);
check('saldo: DISETUJUI masuk kas', (int) summary($pdo)['kas_tersedia'] === 1000000);
check('saldo: saldo anggota benar', (int) one($pdo, 'SELECT savings_balance FROM v_member_savings WHERE member_id=?', [$ani]) === 1000000);
check('saldo: anggota lain tetap 0', (int) one($pdo, 'SELECT savings_balance FROM v_member_savings WHERE member_id=?', [$budi]) === 0);
check('status: DISETUJUI tidak bisa diedit (keterangan)', rejects($pdo, "UPDATE transactions SET description='x' WHERE id = ?", [$t], 'tidak boleh diubah'));
check('status: DISETUJUI tidak bisa dibatalkan', rejects($pdo, "UPDATE transactions SET status='DIBATALKAN' WHERE id = ?", [$t], 'status'));
check('status: DISETUJUI tidak bisa dihapus', rejects($pdo, 'DELETE FROM transactions WHERE id = ?', [$t], 'tidak boleh dihapus'));
check('status: DISETUJUI tidak bisa di-soft-delete', rejects($pdo, 'UPDATE transactions SET deleted_at = NOW() WHERE id = ?', [$t], 'tidak boleh diubah'));
check('status: rincian baru tidak bisa ditambah ke transaksi disetujui', rejects($pdo, "INSERT INTO expenses (transaction_id, category) VALUES (?, 'x')", [$t], 'DRAFT'));

$rej = mkTrx($pdo, 'SIMPANAN', $budi, 500000, $monthIds[0]);
setStatus($pdo, $rej, 'MENUNGGU_VALIDASI');
setStatus($pdo, $rej, 'DITOLAK');
check('saldo: DITOLAK tidak masuk saldo', (int) summary($pdo)['saldo_tabungan'] === 1000000);
check('status: DITOLAK bersifat final', rejects($pdo, "UPDATE transactions SET status='DISETUJUI' WHERE id = ?", [$rej], 'status'));

$can = mkTrx($pdo, 'SIMPANAN', $budi, 300000, $monthIds[0]);
setStatus($pdo, $can, 'DIBATALKAN');
check('saldo: DIBATALKAN tidak masuk saldo', (int) summary($pdo)['saldo_tabungan'] === 1000000);

$draft = mkTrx($pdo, 'SIMPANAN', $budi, 111, $monthIds[0]);
$pdo->prepare("INSERT INTO savings (transaction_id, kind) VALUES (?, 'WAJIB')")->execute([$draft]);
check('draft: boleh dihapus beserta rincian (cascade)', !rejects($pdo, 'DELETE FROM transactions WHERE id = ?', [$draft]) && (int) one($pdo, 'SELECT COUNT(*) FROM savings WHERE transaction_id=?', [$draft]) === 0);

// ---------- jejak append-only ----------
$pdo->exec("INSERT INTO audit_logs (action, entity_type) VALUES ('UJI', 'test')");
check('audit: tidak bisa diubah', rejects($pdo, "UPDATE audit_logs SET action='X'", [], 'append-only'));
check('audit: tidak bisa dihapus', rejects($pdo, 'DELETE FROM audit_logs', [], 'append-only'));
check('audit: JSON tidak valid ditolak', rejects($pdo, "INSERT INTO audit_logs (action, entity_type, after_data) VALUES ('UJI','t','{bukan json')", []));
check('validasi: riwayat tidak bisa diubah', rejects($pdo, "UPDATE transaction_validations SET note='x'", [], 'append-only'));
check('validasi: riwayat tidak bisa dihapus', rejects($pdo, 'DELETE FROM transaction_validations', [], 'append-only'));

// ---------- pinjaman, cicilan, alokasi, rekonsiliasi ----------
[$loanTrx, $loanId, $inst] = mkLoan($pdo, $citra, 5000000, 3, $monthIds); // bunga 300.000; total 5.300.000
check('cicilan: pembulatan, terakhir menyerap sisa', [1766666, 1766666, 1766668] === array_map(fn ($i) => (int) one($pdo, 'SELECT amount_due FROM loan_installments WHERE id=?', [$i]), $inst));
check('pinjaman: DRAFT belum menjadi piutang', (int) summary($pdo)['piutang_beredar'] === 0);
approve($pdo, $loanTrx);
$s = summary($pdo);
check('pinjaman: kas berkurang sebesar pokok', (int) $s['kas_tersedia'] === 1000000 - 5000000);
check('pinjaman: piutang = pokok + bunga', (int) $s['piutang_beredar'] === 5300000);
check('pinjaman: bunga dibukukan di muka', (int) $s['bunga_dibukukan'] === 300000);
check('rekonsiliasi: selisih 0 setelah pencairan', (int) $s['selisih'] === 0);
check('pinjaman: sisa pokok pro-rata sebelum bayar = pokok', (int) one($pdo, 'SELECT outstanding_principal FROM v_loan_balances WHERE loan_id=?', [$loanId]) === 5000000);
check('pinjaman: kas negatif terdeteksi pemeriksa', (int) one($pdo, "SELECT COUNT(*) FROM v_integrity_issues WHERE issue='KAS_NEGATIF'") === 1);

// angsuran 2.000.000: melunasi cicilan 1 (1.766.666) + sebagian cicilan 2 (233.334)
$pay = mkTrx($pdo, 'ANGSURAN', $citra, 2000000, $monthIds[1]);
$pdo->prepare('INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES (?,?,?)')->execute([$pay, $inst[0], 1766666]);
$pdo->prepare('INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES (?,?,?)')->execute([$pay, $inst[1], 233334]);
check('angsuran: DRAFT belum mengurangi piutang', (int) summary($pdo)['piutang_beredar'] === 5300000);
approve($pdo, $pay);
$s = summary($pdo);
check('angsuran: kas bertambah', (int) $s['kas_tersedia'] === -4000000 + 2000000);
check('angsuran: piutang berkurang', (int) $s['piutang_beredar'] === 3300000);
check('angsuran: selisih tetap 0', (int) $s['selisih'] === 0);
check('angsuran: cicilan 1 lunas', (int) one($pdo, 'SELECT remaining_amount FROM v_installment_status WHERE installment_id=?', [$inst[0]]) === 0);
check('angsuran: cicilan 2 sisa sebagian', (int) one($pdo, 'SELECT remaining_amount FROM v_installment_status WHERE installment_id=?', [$inst[1]]) === 1533332);
check('pinjaman: sisa pokok pro-rata mengecil', (int) one($pdo, 'SELECT outstanding_principal FROM v_loan_balances WHERE loan_id=?', [$loanId]) === (int) round(5000000 * 3300000 / 5300000));
check('pinjaman: status AKTIF', one($pdo, 'SELECT loan_status FROM v_loan_balances WHERE loan_id=?', [$loanId]) === 'AKTIF');
check('alokasi: alokasi tidak bisa diubah setelah disetujui', rejects($pdo, 'UPDATE installment_payments SET amount = 1 WHERE transaction_id = ?', [$pay], 'DRAFT'));
check('alokasi: alokasi baru tidak bisa ditambah setelah disetujui', rejects($pdo, 'INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES (?,?,1)', [$pay, $inst[2]], 'DRAFT'));
check('jadwal: tidak bisa diubah setelah pinjaman disetujui', rejects($pdo, 'UPDATE loan_installments SET amount_due = 1 WHERE id = ?', [$inst[2]], 'DRAFT'));
check('integritas: bersih selain kas negatif yang sengaja', (int) one($pdo, "SELECT COUNT(*) FROM v_integrity_issues WHERE issue <> 'KAS_NEGATIF'") === 0);

// angsuran yang TIDAK dialokasikan penuh harus terdeteksi (dasar invarian saldo)
$bad = mkTrx($pdo, 'ANGSURAN', $citra, 500000, $monthIds[2]);
$pdo->prepare('INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES (?,?,?)')->execute([$bad, $inst[1], 400000]);
approve($pdo, $bad);
check('integritas: alokasi kurang dari nominal terdeteksi', (int) one($pdo, "SELECT COUNT(*) FROM v_integrity_issues WHERE issue='ALOKASI_ANGSURAN_TIDAK_SAMA'") === 1);
check('integritas: selisih global = angsuran tak teralokasi', (int) summary($pdo)['selisih'] === 100000);

// angsuran melebihi tagihan cicilan terdeteksi
$over = mkTrx($pdo, 'ANGSURAN', $citra, 2000000, $monthIds[2]);
$pdo->prepare('INSERT INTO installment_payments (transaction_id, installment_id, amount) VALUES (?,?,?)')->execute([$over, $inst[0], 2000000]);
approve($pdo, $over);
check('integritas: kelebihan bayar cicilan terdeteksi', (int) one($pdo, "SELECT COUNT(*) FROM v_integrity_issues WHERE issue='CICILAN_KELEBIHAN_BAYAR'") >= 1);

// ---------- transaksi pembalik ----------
$before = (int) summary($pdo)['saldo_tabungan'];
$rev = mkTrx($pdo, 'SIMPANAN', $ani, 1000000, $monthIds[2], $t);
approve($pdo, $rev);
check('pembalik: saldo tabungan kembali', (int) summary($pdo)['saldo_tabungan'] === $before - 1000000);
check('pembalik: saldo anggota nol lagi', (int) one($pdo, 'SELECT savings_balance FROM v_member_savings WHERE member_id=?', [$ani]) === 0);
check('pembalik: satu transaksi hanya bisa dibalik sekali', rejects($pdo, "INSERT INTO transactions (trx_no, doc_no, type, member_id, period_month_id, trx_date, amount, reverses_id) VALUES ('REV2','REV2',?,?,?,'2026-03-01',1000000,?)", ['SIMPANAN', $ani, $monthIds[2], $t], 'Duplicate'));

// pembalik pencairan: pinjaman tidak lagi efektif
[$loan2Trx, $loan2Id] = mkLoan($pdo, $budi, 1000000, 2, $monthIds);
approve($pdo, $loan2Trx);
check('pembalik pinjaman: sebelum dibalik terhitung piutang', (int) one($pdo, 'SELECT COUNT(*) FROM v_loan_balances WHERE loan_id=?', [$loan2Id]) === 1);
$rev2 = mkTrx($pdo, 'PENCAIRAN_PINJAMAN', $budi, 1000000, $monthIds[2], $loan2Trx);
approve($pdo, $rev2);
check('pembalik pinjaman: pinjaman tidak lagi efektif', (int) one($pdo, 'SELECT COUNT(*) FROM v_loan_balances WHERE loan_id=?', [$loan2Id]) === 0);

// ---------- biaya ----------
$kasBefore = (int) summary($pdo)['kas_tersedia'];
$exp = mkTrx($pdo, 'BIAYA', null, 450500, $monthIds[0]);
$pdo->prepare("INSERT INTO expenses (transaction_id, category, fund_source) VALUES (?, 'Buku tabungan', 'SHU')")->execute([$exp]);
approve($pdo, $exp);
check('biaya: mengurangi kas', (int) summary($pdo)['kas_tersedia'] === $kasBefore - 450500);
check('biaya: tercatat di ringkasan', (int) summary($pdo)['biaya'] === 450500);

// ---------- hasil ----------
echo "\n" . $passed . ' lulus, ' . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
