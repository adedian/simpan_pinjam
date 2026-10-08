<?php
declare(strict_types=1);

/**
 * Pekerja uji konkurensi (Phase 17): satu proses PHP terpisah yang menyetujui/menolak satu transaksi tepat pada
 * waktu mulai bersama. Dipanggil oleh tests/concurrency.php.
 *   php tests/workers/validate.php <setujui|tolak> <id transaksi> <username> <waktu mulai (microtime)>
 * Mencetak satu baris JSON: {"ok":bool,"error":?string}.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require dirname(__DIR__, 2) . '/core/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Models\User;
use App\Services\RuleViolation;
use App\Services\ValidationService;

[, $action, $id, $username, $startAt] = array_pad($argv, 5, '');
$testDb = (string) App\Core\Env::get('DB_TEST_NAME', 'simpan_pinjam_adem_ayem_test');
Database::configure(['name' => $testDb, 'user' => (string) Config::get('database.admin_user'), 'pass' => (string) Config::get('database.admin_pass')]);
$_SESSION = [];

$pdo   = Database::pdo();
$row   = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$row->execute([$username]);
$actor = User::findActive((int) $row->fetchColumn());
$ver   = $pdo->prepare('SELECT updated_at FROM transactions WHERE id = ?');
$ver->execute([(int) $id]);
$version = (string) $ver->fetchColumn();   // versi yang dilihat pekerja SEBELUM balapan, seperti peramban yang membuka halaman lebih dulu

// gerbang: semua pekerja menunggu waktu yang sama
while (microtime(true) < (float) $startAt) {
    usleep(500);
}

$req = new Request('POST', '/x', [], [], ['REMOTE_ADDR' => '10.3.3.3', 'HTTP_USER_AGENT' => 'pekerja']);
try {
    if ($action === 'setujui') {
        ValidationService::approve($req, $actor, (int) $id, $version, null);
    } else {
        ValidationService::reject($req, $actor, (int) $id, $version, 'ditolak (uji konkurensi)');
    }
    echo json_encode(['ok' => true, 'error' => null]), "\n";
} catch (RuleViolation $e) {
    echo json_encode(['ok' => false, 'error' => implode(' ', $e->errors)]), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'GALAT: ' . get_class($e) . ': ' . $e->getMessage()]), "\n";
}
