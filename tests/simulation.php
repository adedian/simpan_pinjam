<?php
declare(strict_types=1);

/**
 * Simulasi acak keuangan (Phase 17): "uang tidak boleh salah" dibuktikan dengan ribuan langkah acak, bukan contoh buatan tangan.
 *
 * Untuk beberapa seed tetap, menjalankan ratusan operasi ACAK lewat layanan sungguhan (simpanan, pinjaman, angsuran,
 * ajukan, setujui, tolak, batalkan, ubah draf, koreksi) oleh peran yang berbeda. Setelah SETIAP langkah, saldo yang dihitung
 * aplikasi (view database) dibandingkan dengan ORAKEL INDEPENDEN yang dihitung ulang dari tabel mentah oleh kode di berkas ini
 * (tanpa memakai view), dan semua invarian diperiksa. Penolakan aturan bisnis (RuleViolation) adalah hasil yang sah;
 * galat lain (SQL, tipe, dsb.) adalah kegagalan.
 *
 * MENGOSONGKAN database uji; tidak menyentuh data sungguhan. Deterministik: seed yang sama = urutan yang sama.
 *   C:\xampp\php\php.exe tests\simulation.php [--seeds=1,2,3] [--steps=150]
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Models\User;
use App\Services\InstallmentService;
use App\Services\LoanService;
use App\Services\Migrator;
use App\Services\ReportService;
use App\Services\ReversalService;
use App\Services\RuleViolation;
use App\Services\SavingService;
use App\Services\UserService;
use App\Services\ValidationService;

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    }
}
$seeds = array_map('intval', explode(',', (string) ($opts['seeds'] ?? '1,2,3,4,5,6')));
$steps = max(10, (int) ($opts['steps'] ?? 150));

$testDb = (string) App\Core\Env::get('DB_TEST_NAME', 'simpan_pinjam_adem_ayem_test');
$admin  = [(string) Config::get('database.admin_user'), (string) Config::get('database.admin_pass')];
Database::connect($admin[0], $admin[1], '')->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
Database::configure(['name' => $testDb, 'user' => $admin[0], 'pass' => $admin[1]]);
$pdo = Database::pdo();
$migrator = new Migrator($pdo, dirname(__DIR__) . '/database/migrations');

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
function rows(string $sql, array $params = []): array
{
    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
function req(): Request
{
    return new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.2.2.2', 'HTTP_USER_AGENT' => 'simulasi']);
}
function pick(array $a): mixed
{
    return $a[mt_rand(0, count($a) - 1)];
}

/**
 * ORAKEL: saldo dihitung ulang dari tabel mentah (transaksi DISETUJUI saja), tanpa view.
 * @return array{savings:int,kas:int,piutang:int,bunga:int,biaya:int,member:array<int,int>}
 */
function oracle(): array
{
    $kas = $savings = $biaya = $bunga = $piutang = 0;
    $member = [];
    $approved = rows("SELECT t.id, t.type, t.amount, t.reverses_id, t.member_id FROM transactions t WHERE t.status = 'DISETUJUI' AND t.deleted_at IS NULL");
    $reversed = [];
    foreach ($approved as $t) {
        if ($t['reverses_id'] !== null) {
            $reversed[(int) $t['reverses_id']] = true;
        }
    }
    foreach ($approved as $t) {
        $sign = $t['reverses_id'] === null ? 1 : -1;
        $amt  = (int) $t['amount'];
        switch ($t['type']) {
            case 'SIMPANAN':
                $kas += $sign * $amt;
                $savings += $sign * $amt;
                $member[(int) $t['member_id']] = ($member[(int) $t['member_id']] ?? 0) + $sign * $amt;
                break;
            case 'PENARIKAN':
                $kas -= $sign * $amt;
                $savings -= $sign * $amt;
                $member[(int) $t['member_id']] = ($member[(int) $t['member_id']] ?? 0) - $sign * $amt;
                break;
            case 'PENCAIRAN_PINJAMAN':
                $kas -= $sign * $amt;
                if ($t['reverses_id'] === null && !isset($reversed[(int) $t['id']])) {
                    $loan = rows('SELECT principal, total_interest FROM loans WHERE transaction_id = ?', [(int) $t['id']])[0];
                    $piutang += (int) $loan['principal'] + (int) $loan['total_interest'];
                    $bunga += (int) $loan['total_interest'];
                }
                break;
            case 'ANGSURAN':
                $kas += $sign * $amt;
                $piutang -= $sign * $amt;
                break;
            case 'BIAYA':
                $kas -= $sign * $amt;
                $biaya += $sign * $amt;
                break;
        }
    }
    return ['savings' => $savings, 'kas' => $kas, 'piutang' => $piutang, 'bunga' => $bunga, 'biaya' => $biaya, 'member' => $member];
}

/** @return array<int,string> daftar pelanggaran invarian (kosong = sehat) */
function invariants(): array
{
    $bad = [];
    $o = oracle();
    $g = rows('SELECT saldo_tabungan, kas_tersedia, piutang_beredar, bunga_dibukukan, biaya, selisih FROM v_global_summary')[0];
    foreach (['savings' => 'saldo_tabungan', 'kas' => 'kas_tersedia', 'piutang' => 'piutang_beredar', 'bunga' => 'bunga_dibukukan', 'biaya' => 'biaya'] as $k => $col) {
        if ((int) $g[$col] !== $o[$k]) {
            $bad[] = "{$col}: aplikasi {$g[$col]} != orakel {$o[$k]}";
        }
    }
    if ((int) $g['selisih'] !== 0) {
        $bad[] = 'selisih invarian ' . $g['selisih'];
    }
    if ($o['kas'] + $o['piutang'] !== $o['savings'] + $o['bunga'] - $o['biaya']) {
        $bad[] = 'invarian orakel sendiri patah (kas + piutang != tabungan + bunga - biaya)';
    }
    if ($o['kas'] < 0) {
        $bad[] = 'kas negatif ' . $o['kas'];
    }
    $vm = [];
    foreach (rows('SELECT member_id, savings_balance FROM v_member_savings') as $r) {
        $vm[(int) $r['member_id']] = (int) $r['savings_balance'];
    }
    $om = array_filter($o['member'], static fn (int $v): bool => $v !== 0);
    $vmNonZero = array_filter($vm, static fn (int $v): bool => $v !== 0);
    ksort($om);
    ksort($vmNonZero);
    if ($om !== $vmNonZero) {
        $bad[] = 'saldo per anggota berbeda: aplikasi ' . json_encode($vmNonZero) . ' orakel ' . json_encode($om);
    }
    foreach ($vm as $mid => $bal) {
        if ($bal < 0) {
            $bad[] = "saldo anggota {$mid} negatif ({$bal})";
        }
    }
    if (array_sum($vm) !== $o['savings']) {
        $bad[] = 'jumlah saldo anggota != saldo global';
    }
    if ((int) one('SELECT COUNT(*) FROM v_integrity_issues') !== 0) {
        $bad[] = 'v_integrity_issues: ' . json_encode(rows('SELECT issue, reference FROM v_integrity_issues LIMIT 3'));
    }
    if ((int) one("SELECT COUNT(*) FROM v_ledger l JOIN transactions t ON t.id = l.transaction_id WHERE t.status <> 'DISETUJUI' OR t.deleted_at IS NOT NULL") !== 0) {
        $bad[] = 'ledger memuat transaksi yang bukan DISETUJUI';
    }
    if ((int) one('SELECT COUNT(*) FROM transactions') !== (int) one('SELECT COUNT(DISTINCT doc_no) FROM transactions')) {
        $bad[] = 'nomor dokumen ganda';
    }
    // pelunasan tidak boleh melebihi tagihan: jumlah alokasi angsuran disetujui per cicilan <= jumlah cicilan, per pinjaman <= total tagihan
    $over = rows("SELECT li.id FROM loan_installments li JOIN installment_payments ip ON ip.installment_id = li.id JOIN transactions t ON t.id = ip.transaction_id AND t.status = 'DISETUJUI' AND t.deleted_at IS NULL
                  GROUP BY li.id, li.amount_due HAVING SUM(ip.amount) > li.amount_due OR SUM(ip.amount) < 0");
    if ($over !== []) {
        $bad[] = 'cicilan terbayar melebihi tagihan atau negatif: ' . json_encode(array_column($over, 'id'));
    }
    // seluruh uang angsuran teralokasi ke cicilan (tidak ada uang angsuran yang menggantung tanpa cicilan)
    $alloc = (int) one("SELECT COALESCE(SUM(ip.amount), 0) FROM installment_payments ip JOIN transactions t ON t.id = ip.transaction_id AND t.status = 'DISETUJUI' AND t.deleted_at IS NULL");
    $paid  = (int) one("SELECT COALESCE(SUM(IF(reverses_id IS NULL, amount, -amount)), 0) FROM transactions WHERE type = 'ANGSURAN' AND status = 'DISETUJUI' AND deleted_at IS NULL");
    if ($alloc !== $paid) {
        $bad[] = "alokasi angsuran {$alloc} != jumlah angsuran disetujui {$paid}";
    }
    // setiap transaksi disetujui lewat aplikasi punya tepat satu catatan validasi DISETUJUI
    $noVal = (int) one("SELECT COUNT(*) FROM transactions t WHERE t.status = 'DISETUJUI' AND t.source = 'APLIKASI' AND (SELECT COUNT(*) FROM transaction_validations v WHERE v.transaction_id = t.id AND v.to_status = 'DISETUJUI') <> 1");
    if ($noVal !== 0) {
        $bad[] = "{$noVal} transaksi disetujui tanpa tepat satu catatan validasi";
    }
    return $bad;
}

/** Siapkan database kosong dengan periode, anggota, regu, dan pengguna. @return array<string,mixed> */
function fixture(): array
{
    global $migrator, $pdo;
    $migrator->dropEverything();
    $migrator->migrate();
    $m1 = date('Y-m-01', strtotime('-1 month', strtotime(date('Y-m-01'))));
    $m0 = date('Y-m-01');
    $later = [date('Y-m-01', strtotime('+1 month', strtotime($m0))), date('Y-m-01', strtotime('+2 month', strtotime($m0))), date('Y-m-01', strtotime('+3 month', strtotime($m0)))];
    $pdo->exec("INSERT INTO periods (name, start_date, end_date, status) VALUES ('Uji', '{$m1}', '{$later[2]}', 'AKTIF')");
    foreach ([$m1, $m0, ...$later] as $d) {
        $pdo->exec("INSERT INTO period_months (period_id, month_date) VALUES (1, '{$d}')");
    }
    for ($n = 1; $n <= 8; $n++) {
        $pdo->prepare('INSERT INTO members (member_no, name, active_from, status) VALUES (?, ?, ?, ?)')->execute([sprintf('AGT-%03d', $n), "Anggota {$n}", $m1, 'AKTIF']);
    }
    $pdo->exec("INSERT INTO team_leaders (name, leader_member_id) VALUES ('Regu A', 1), ('Regu B', 5)");
    $pdo->exec("INSERT INTO member_team_assignments (member_id, team_id, valid_from) VALUES (1,1,'{$m1}'),(2,1,'{$m1}'),(3,1,'{$m1}'),(4,1,'{$m1}'),(5,2,'{$m1}'),(6,2,'{$m1}'),(7,2,'{$m1}'),(8,2,'{$m1}')");
    $ids = [];
    foreach ([['purwati', ['HEAD', 'KETUA_REGU'], 'AGT-001'], ['beta', ['KETUA_REGU'], 'AGT-005'], ['kepala2', ['HEAD'], null], ['periksa', ['PEMERIKSA'], null]] as [$u, $roles, $no]) {
        $ids[$u] = UserService::create($u, ucfirst($u), $roles, $no, 'Contoh-Uji-2026')['id'];
    }
    $ids['periksa.m'] = UserService::create('periksa.m', 'Periksa M', ['PEMERIKSA'], null, 'Contoh-Uji-2026')['id'];
    $pdo->exec("UPDATE users SET member_id = 8 WHERE username = 'periksa.m'");
    $mid = [];
    foreach ([$m1, $m0] as $d) {
        $mid[$d] = (int) one('SELECT id FROM period_months WHERE month_date = ?', [$d]);
    }
    return ['m1' => $m1, 'm0' => $m0, 'mid' => $mid, 'users' => array_map(static fn (int $id): array => User::findActive($id), $ids)];
}

$total = ['saving' => 0, 'loan' => 0, 'payment' => 0, 'approve' => 0, 'reject' => 0, 'cancel' => 0, 'submit' => 0, 'reverse' => 0, 'edit' => 0, 'rule' => 0, 'steps' => 0];
$approvedByType = [];
$revApproved = 0;
$today = date('Y-m-d');
$t0 = microtime(true);

foreach ($seeds as $seed) {
    mt_srand($seed);
    $fx = fixture();
    $U = $fx['users'];
    $teamMembers = [1 => [1, 2, 3, 4], 2 => [5, 6, 7, 8]];
    $leaderOf = [1 => $U['purwati'], 2 => $U['beta']];
    $validators = [$U['purwati'], $U['kepala2'], $U['periksa'], $U['periksa.m']];
    $violation = null;

    for ($step = 1; $step <= $steps && $violation === null; $step++) {
        $roll = mt_rand(1, 100);
        $op = 'noop';
        try {
            if ($roll <= 24) {
                $op = 'saving';
                $team = mt_rand(1, 2);
                $inM1 = mt_rand(0, 1) === 1;
                [$e, $d] = SavingService::parse(['member_id' => pick($teamMembers[$team]), 'period_month_id' => $fx['mid'][$inM1 ? $fx['m1'] : $fx['m0']], 'kind' => pick(['SUKARELA', 'WAJIB', 'POKOK']),
                    'amount' => (string) (mt_rand(1, 60) * 10000), 'trx_date' => $inM1 ? $fx['m1'] : $today, 'confirm_duplicate' => '1']);
                if ($e === []) {
                    SavingService::create(req(), $leaderOf[$team], $d, mt_rand(1, 100) <= 85);
                    $total['saving']++;
                }
            } elseif ($roll <= 38) {
                $op = 'loan';
                $team = mt_rand(1, 2);
                [$e, $d] = LoanService::parse(['member_id' => pick($teamMembers[$team]), 'period_month_id' => $fx['mid'][$fx['m1']], 'principal' => (string) (mt_rand(2, 30) * 50000), 'tenor' => (string) mt_rand(1, 3),
                    'trx_date' => $fx['m1'], 'confirm_duplicate' => '1']);
                if ($e === []) {
                    LoanService::create(req(), $leaderOf[$team], $d, mt_rand(1, 100) <= 90);
                    $total['loan']++;
                }
            } elseif ($roll <= 56) {
                $op = 'payment';
                $loans = rows("SELECT DISTINCT t.member_id, t.team_id FROM transactions t WHERE t.type = 'PENCAIRAN_PINJAMAN' AND t.status = 'DISETUJUI' AND t.reverses_id IS NULL");
                if ($loans !== []) {
                    $l = pick($loans);
                    [$e, $d] = InstallmentService::parse(['member_id' => (int) $l['member_id'], 'period_month_id' => $fx['mid'][$fx['m0']], 'amount' => (string) (mt_rand(1, 40) * 10000), 'trx_date' => $today, 'confirm_duplicate' => '1']);
                    if ($e === []) {
                        InstallmentService::create(req(), $leaderOf[(int) $l['team_id']], $d, mt_rand(1, 100) <= 90);
                        $total['payment']++;
                    }
                }
            } elseif ($roll <= 80) {
                $op = 'approve';
                $pending = rows("SELECT id, updated_at FROM transactions WHERE status = 'MENUNGGU_VALIDASI' AND deleted_at IS NULL");
                if ($pending !== []) {
                    $p = pick($pending);
                    ValidationService::approve(req(), pick($validators), (int) $p['id'], (string) $p['updated_at'], null);
                    $total['approve']++;
                }
            } elseif ($roll <= 85) {
                $op = 'reject';
                $pending = rows("SELECT id, updated_at FROM transactions WHERE status = 'MENUNGGU_VALIDASI' AND deleted_at IS NULL");
                if ($pending !== []) {
                    $p = pick($pending);
                    ValidationService::reject(req(), pick($validators), (int) $p['id'], (string) $p['updated_at'], 'tidak sesuai (simulasi)');
                    $total['reject']++;
                }
            } elseif ($roll <= 89) {
                $op = 'cancel';
                $open = rows("SELECT t.id, t.type, t.updated_at, t.team_id FROM transactions t WHERE t.status IN ('DRAFT','MENUNGGU_VALIDASI') AND t.reverses_id IS NULL AND t.deleted_at IS NULL");
                if ($open !== []) {
                    $o = pick($open);
                    $svc = ['SIMPANAN' => SavingService::class, 'PENCAIRAN_PINJAMAN' => LoanService::class, 'ANGSURAN' => InstallmentService::class][$o['type']] ?? null;
                    if ($svc !== null) {
                        $svc::cancel(req(), $leaderOf[(int) $o['team_id']], (int) $o['id'], 'dibatalkan (simulasi)', (string) $o['updated_at']);
                        $total['cancel']++;
                    }
                }
            } elseif ($roll <= 92) {
                $op = 'submit';
                $drafts = rows("SELECT t.id, t.type, t.updated_at, t.team_id FROM transactions t WHERE t.status = 'DRAFT' AND t.reverses_id IS NULL AND t.deleted_at IS NULL");
                if ($drafts !== []) {
                    $o = pick($drafts);
                    $svc = ['SIMPANAN' => SavingService::class, 'PENCAIRAN_PINJAMAN' => LoanService::class, 'ANGSURAN' => InstallmentService::class][$o['type']] ?? null;
                    if ($svc !== null) {
                        $svc::submit(req(), $leaderOf[(int) $o['team_id']], (int) $o['id'], (string) $o['updated_at']);
                        $total['submit']++;
                    }
                }
            } elseif ($roll <= 97) {
                $op = 'reverse';
                $cands = rows("SELECT t.id, t.team_id FROM transactions t WHERE t.status = 'DISETUJUI' AND t.reverses_id IS NULL AND t.source = 'APLIKASI' AND t.deleted_at IS NULL");
                if ($cands !== []) {
                    $c = pick($cands);
                    ReversalService::request(req(), $leaderOf[(int) $c['team_id']], (int) $c['id'], 'koreksi (simulasi)');
                    $total['reverse']++;
                }
            } else {
                $op = 'edit';
                $drafts = rows("SELECT t.id, t.updated_at, t.team_id, t.member_id FROM transactions t JOIN savings s ON s.transaction_id = t.id WHERE t.status = 'DRAFT' AND t.deleted_at IS NULL");
                if ($drafts !== []) {
                    $o = pick($drafts);
                    [$e, $d] = SavingService::parse(['member_id' => (int) $o['member_id'], 'period_month_id' => $fx['mid'][$fx['m0']], 'kind' => 'SUKARELA', 'amount' => (string) (mt_rand(1, 60) * 10000), 'trx_date' => $today, 'confirm_duplicate' => '1']);
                    if ($e === []) {
                        SavingService::update(req(), $leaderOf[(int) $o['team_id']], (int) $o['id'], $d, (string) $o['updated_at'], false);
                        $total['edit']++;
                    }
                }
            }
        } catch (RuleViolation $e) {
            $total['rule']++;   // penolakan aturan bisnis adalah hasil yang sah
        } catch (Throwable $e) {
            $violation = ["seed {$seed} langkah {$step} ({$op}): galat tak terduga " . get_class($e) . ': ' . $e->getMessage()];
            break;
        }
        $total['steps']++;
        $bad = invariants();
        if ($bad !== []) {
            $violation = array_map(static fn (string $b): string => "seed {$seed} langkah {$step} ({$op}): {$b}", $bad);
        }
    }

    check("seed {$seed}: {$steps} langkah acak tanpa galat tak terduga dan semua invarian terjaga setelah SETIAP langkah" . ($violation ? ' -> ' . implode(' | ', array_slice($violation, 0, 3)) : ''), $violation === null);

    if ($violation === null) {
        // pemeriksaan akhir per seed
        $o = oracle();
        $head = $U['kepala2'];
        $rep = ReportService::build('simpanan', $head, ReportService::filters([], $head));
        check("seed {$seed}: laporan Rekap Simpanan (jumlah saldo) = orakel", (int) $rep['totals']['savings'] === $o['savings']);
        $approvedApp = (int) one("SELECT COUNT(*) FROM transactions WHERE status = 'DISETUJUI' AND source = 'APLIKASI'");
        check("seed {$seed}: audit TRX_APPROVED + REVERSAL tercatat = jumlah transaksi disetujui lewat aplikasi", (int) one("SELECT COUNT(*) FROM audit_logs WHERE action = 'TRX_APPROVED'") === $approvedApp);
        $someApproved = rows("SELECT id FROM transactions WHERE status = 'DISETUJUI' LIMIT 1");
        if ($someApproved !== []) {
            $id = (int) $someApproved[0]['id'];
            $blocked = static function (string $sql): bool {
                try {
                    Database::pdo()->exec($sql);
                    return false;
                } catch (PDOException $e) {
                    return true;
                }
            };
            check("seed {$seed}: transaksi disetujui tidak bisa diubah nominalnya atau dihapus lewat SQL (trigger)", $blocked("UPDATE transactions SET amount = amount + 1 WHERE id = {$id}") && $blocked("DELETE FROM transactions WHERE id = {$id}") && invariants() === []);
        }
        $revApproved += (int) one("SELECT COUNT(*) FROM transactions WHERE status = 'DISETUJUI' AND reverses_id IS NOT NULL");
        foreach (rows("SELECT type, COUNT(*) n FROM transactions WHERE status = 'DISETUJUI' GROUP BY type") as $r) {
            $approvedByType[$r['type']] = ($approvedByType[$r['type']] ?? 0) + (int) $r['n'];
        }
    }
}

// Simulasi harus sungguh menjelajahi jalur penting, bukan lolos karena hampir semua langkah ditolak.
check('sebaran: simulasi menyetujui simpanan, pencairan, angsuran, dan koreksi (jalur keuangan utama terjelajahi) ' . json_encode($approvedByType),
    ($approvedByType['SIMPANAN'] ?? 0) >= 40 && ($approvedByType['PENCAIRAN_PINJAMAN'] ?? 0) >= 6 && ($approvedByType['ANGSURAN'] ?? 0) >= 6);
check('sebaran: ada penolakan validator, pembatalan, pengajuan draf, ubah draf, koreksi, dan penolakan aturan (' . json_encode($total) . ')',
    $total['reject'] >= 5 && $total['cancel'] >= 5 && $total['submit'] >= 3 && $total['reverse'] >= 3 && $total['edit'] >= 2 && $total['rule'] >= 20);
check("sebaran: pembalik (koreksi) yang disetujui ada, jumlahnya {$revApproved}: saldo benar-benar dikurangi koreksi dan tetap cocok dengan orakel", $revApproved >= 3);

printf("\n%d langkah acak pada %d seed dalam %.1f detik.\n", $total['steps'], count($seeds), microtime(true) - $t0);
echo "\n{$passed} lulus, " . count($failed) . " gagal\n";
exit($failed === [] ? 0 : 1);
