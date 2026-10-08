<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class HealthController extends BaseController
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $checks = [
            'php_version' => version_compare(PHP_VERSION, '8.0.0', '>='),
            'ext_pdo_mysql' => extension_loaded('pdo_mysql'),
            'ext_mbstring' => extension_loaded('mbstring'),
            'storage_writable' => is_writable(BASE_PATH . '/storage/logs') && is_writable(BASE_PATH . '/storage/sessions'),
        ];
        $ok = !in_array(false, $checks, true);

        // Rincian hanya untuk lingkungan lokal; produksi cukup status ringkas.
        if (Config::get('app.env') !== 'local') {
            return Response::json(['status' => $ok ? 'ok' : 'error'], $ok ? 200 : 503);
        }

        $migrations = null;
        try {
            $migrations = (int) Database::pdo()->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
            $database   = 'ok';
        } catch (\Throwable $e) {
            $database = 'tidak_terhubung';
        }

        return Response::json([
            'status'   => $ok ? 'ok' : 'error',
            'php'      => PHP_VERSION,
            'checks'   => $checks,
            'database' => $database,
            'migrasi'  => $migrations,
        ], $ok ? 200 : 503);
    }
}
