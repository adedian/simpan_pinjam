<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Models\User;

/**
 * Autentikasi. Prinsip:
 *  - Pesan galat login SELALU sama (tidak membocorkan apakah nama pengguna ada).
 *  - Waktu respons tidak membedakan "pengguna tidak ada" dari "kata sandi salah" (hash tiruan).
 *  - Pembatasan laju ganda: per akun (kunci sementara) dan per IP.
 *  - Pengguna dimuat ULANG dari database tiap request: penonaktifan akun, perubahan peran,
 *    dan penggantian kata sandi berlaku seketika, tidak menunggu sesi habis.
 */
final class Auth
{
    public const MAX_FAILURES     = 5;   // salah berturut-turut sebelum akun dikunci
    public const LOCK_MINUTES     = 15;
    public const IP_MAX_FAILURES  = 20;  // gagal per IP dalam jendela waktu
    public const IP_WINDOW_MIN    = 15;
    public const GENERIC_ERROR    = 'Nama pengguna atau kata sandi salah.';
    public const THROTTLED_ERROR  = 'Terlalu banyak percobaan. Coba lagi dalam beberapa menit.';

    /** Hash bcrypt cost 12 yang SAH (dari string acak yang dibuang) untuk menyamakan waktu bila pengguna tidak ada. */
    private const DUMMY_HASH = '$2y$12$5WB1l8PumWvBbDy5wwpwb.f/AmlzgOObQzEL1UY.LwL0xsy0.fqHC';

    /** @var array<string,mixed>|null */
    private static ?array $override = null;
    private static bool $overridden = false;
    /** @var array<string,mixed>|null */
    private static ?array $cache = null;
    private static bool $resolved = false;

    // ------------------------------------------------------------------ status sesi

    /** Pengguna yang sedang masuk (dimuat dari database sekali per request), atau null. */
    public static function user(): ?array
    {
        if (self::$overridden) {
            return self::$override;
        }
        if (self::$resolved) {
            return self::$cache;
        }
        self::$resolved = true;

        $id = Session::get('auth_id');
        if (!is_int($id)) {
            return self::$cache = null;
        }
        $user = User::findActive($id);
        if ($user === null) {
            self::forget();
            return self::$cache = null;
        }
        $absolute = (int) Config::get('app.session.absolute_timeout', 43200);
        if ($absolute > 0 && time() - (int) Session::get('auth_issued', 0) > $absolute) {
            self::forget();
            Session::flash('warning', 'Sesi berakhir karena sudah terlalu lama. Silakan masuk kembali.');
            return self::$cache = null;
        }
        if ($user['credentials_ts'] > (int) Session::get('auth_issued', 0)) {
            self::forget();
            Session::flash('warning', 'Kata sandi akun ini telah diganti. Silakan masuk kembali.');
            return self::$cache = null;
        }
        return self::$cache = $user;
    }

    /** Untuk tes: paksa pengguna tertentu tanpa sesi. null = tamu. */
    public static function actingAs(?array $user): void
    {
        self::$override   = $user;
        self::$overridden = true;
    }

    public static function reset(): void
    {
        self::$override = self::$cache = null;
        self::$overridden = self::$resolved = false;
    }

    private static function forget(): void
    {
        Session::forget('auth_id');
        Session::forget('auth_issued');
    }

    // ------------------------------------------------------------------ login / logout

    /** @return array{ok:bool,error:?string} */
    public static function attempt(Request $request, string $username, string $password): array
    {
        $username = mb_substr(trim($username), 0, 50);
        $ip       = $request->ip();

        if (self::ipThrottled($ip)) {
            self::logAttempt($username, $ip, false);
            AuditLog::record($request, null, 'LOGIN_THROTTLED', 'user', null, null, null, ['username' => $username]);
            return ['ok' => false, 'error' => self::THROTTLED_ERROR];
        }

        $row  = User::findForLogin($username);
        $hash = $row['password_hash'] ?? self::DUMMY_HASH;
        $valid = password_verify($password, $hash); // selalu dijalankan (waktu seragam)

        if ($row === null) {
            return self::fail($request, $username, $ip, null);
        }
        if ((int) $row['is_locked'] === 1) {
            self::logAttempt($username, $ip, false);
            AuditLog::record($request, ['id' => (int) $row['id'], 'username' => $username], 'LOGIN_BLOCKED_LOCKED', 'user', (int) $row['id']);
            return ['ok' => false, 'error' => self::THROTTLED_ERROR];
        }
        if (!$valid || (int) $row['is_active'] !== 1 || $row['deleted_at'] !== null) {
            return self::fail($request, $username, $ip, (int) $row['id']);
        }

        // sukses
        User::recordSuccess((int) $row['id'], PasswordPolicy::needsRehash($hash) ? PasswordPolicy::hash($password) : null);
        self::logAttempt($username, $ip, true);

        Session::regenerate();                  // cegah session fixation
        Session::forget('_csrf');               // token CSRF baru untuk sesi baru
        Session::set('auth_id', (int) $row['id']);
        Session::set('auth_issued', time());
        self::reset();

        $user = self::user();
        AuditLog::record($request, $user, 'LOGIN_SUCCESS', 'user', (int) $row['id']);
        Database::execute('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 30 DAY');
        return ['ok' => true, 'error' => null];
    }

    public static function logout(Request $request): void
    {
        $user = self::user();
        if ($user !== null) {
            AuditLog::record($request, $user, 'LOGOUT', 'user', $user['id']);
        }
        Session::destroy();
        self::reset();
    }

    /** @return array{ok:bool,error:?string} */
    private static function fail(Request $request, string $username, string $ip, ?int $userId): array
    {
        self::logAttempt($username, $ip, false);
        // Nama yang tak ada diperlakukan sama: setelah MAX_FAILURES gagal, jawabannya juga "terlalu banyak percobaan".
        // Tanpa ini penyerang bisa membedakan akun yang ada (pesannya berubah saat terkunci) dari yang tidak ada.
        $locked = $userId !== null
            ? User::recordFailure($userId, self::MAX_FAILURES, self::LOCK_MINUTES)
            : self::unknownNameLocked($username);
        AuditLog::record($request, $userId === null ? null : ['id' => $userId, 'username' => $username],
            $userId !== null && $locked ? 'LOGIN_LOCKED' : 'LOGIN_FAILED', 'user', $userId, null, null, ['username' => $username]);
        return ['ok' => false, 'error' => $locked ? self::THROTTLED_ERROR : self::GENERIC_ERROR];
    }

    private static function unknownNameLocked(string $username): bool
    {
        $rows = Database::select(
            'SELECT COUNT(*) AS n FROM login_attempts WHERE LOWER(username) = LOWER(?) AND succeeded = 0 AND created_at > NOW() - INTERVAL ? MINUTE',
            [$username, self::LOCK_MINUTES]
        );
        return (int) $rows[0]['n'] >= self::MAX_FAILURES;
    }

    private static function logAttempt(string $username, string $ip, bool $ok): void
    {
        Database::execute('INSERT INTO login_attempts (username, ip_address, succeeded) VALUES (?,?,?)', [$username, $ip, (int) $ok]);
    }

    private static function ipThrottled(string $ip): bool
    {
        $rows = Database::select(
            'SELECT COUNT(*) AS n FROM login_attempts WHERE ip_address = ? AND succeeded = 0 AND created_at > NOW() - INTERVAL ? MINUTE',
            [$ip, self::IP_WINDOW_MIN]
        );
        return (int) $rows[0]['n'] >= self::IP_MAX_FAILURES;
    }

    // ------------------------------------------------------------------ ganti kata sandi

    /**
     * @param array{id:int,username:string,roles:array<int,string>} $user
     * @return array<string,string> galat per field (kosong = berhasil)
     */
    public static function changePassword(Request $request, array $user, string $current, string $new): array
    {
        $recent = Database::select(
            "SELECT COUNT(*) AS n FROM audit_logs WHERE user_id = ? AND action = 'PASSWORD_CHANGE_FAILED' AND created_at > NOW() - INTERVAL ? MINUTE",
            [$user['id'], self::LOCK_MINUTES]
        );
        if ((int) $recent[0]['n'] >= self::MAX_FAILURES) {
            return ['current_password' => 'Terlalu banyak percobaan. Coba lagi beberapa menit lagi.'];   // tebakan kata sandi lama dibatasi, walau sesi sudah masuk
        }
        $hash = User::passwordHashOf($user['id']) ?? self::DUMMY_HASH;
        if (!password_verify($current, $hash)) {
            AuditLog::record($request, $user, 'PASSWORD_CHANGE_FAILED', 'user', $user['id']);
            return ['current_password' => 'Kata sandi saat ini salah.'];
        }
        if (password_verify($new, $hash)) {
            return ['password' => 'Kata sandi baru harus berbeda dari yang lama.'];
        }
        $problems = PasswordPolicy::check($new, $user['username']);
        if ($problems !== []) {
            return ['password' => implode(' ', $problems)];
        }

        User::updatePassword($user['id'], PasswordPolicy::hash($new));
        Session::regenerate();
        Session::forget('_csrf');
        Session::set('auth_issued', time());       // sesi ini tetap sah; sesi lain (diterbitkan lebih awal) mati
        self::reset();
        AuditLog::record($request, self::user(), 'PASSWORD_CHANGED', 'user', $user['id']);
        return [];
    }

    /** Alamat tujuan setelah login: hanya path internal (cegah open redirect). */
    public static function safeRedirect(?string $path): string
    {
        if ($path === null || $path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f]/', $path) === 1) {
            return '/';
        }
        return $path;
    }
}
