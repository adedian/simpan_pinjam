<?php
declare(strict_types=1);

use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\LiveController;
use App\Controllers\MemberController;
use App\Controllers\ProfileController;
use App\Controllers\ReportController;
use App\Controllers\SettingController;
use App\Controllers\StyleguideController;
use App\Controllers\TeamController;
use App\Controllers\TransactionController;
use App\Controllers\UserController;
use App\Controllers\ValidationController;
use App\Core\Router;

/**
 * Daftar route. Konvensi middleware:
 *   'auth'        wajib login          'guest'  hanya tamu
 *   'can:a|b'     wajib punya salah satu izin   (CSRF otomatis untuk semua method pengubah-data)
 * Setiap route yang menampilkan atau mengubah data WAJIB memakai 'auth' dan 'can:...'.
 * Izin menjawab "boleh melakukan apa"; cakupan data ("milik siapa") dipaksa di query model (Scope).
 */
return static function (Router $r): void {
    $r->get('/', [HomeController::class, 'index'], ['auth', 'can:dashboard.view']);

    // Autentikasi
    $r->get('/login', [AuthController::class, 'showLogin'], ['guest']);
    $r->post('/login', [AuthController::class, 'login'], ['guest']);
    $r->post('/logout', [AuthController::class, 'logout'], ['auth']);

    // Profil dan kata sandi sendiri (tidak butuh izin khusus selain login)
    $r->get('/profil', [ProfileController::class, 'show'], ['auth', 'can:profile.view']);
    $r->get('/profil/password', [ProfileController::class, 'showPassword'], ['auth']);
    $r->post('/profil/password', [ProfileController::class, 'updatePassword'], ['auth']);

    // Master data: anggota. Daftar/detail dibatasi cakupan data; ubah-data hanya untuk pengelola (Head).
    $r->get('/master/anggota', [MemberController::class, 'index'], ['auth', 'can:member.view.all|member.view.team']);
    $r->get('/master/anggota/baru', [MemberController::class, 'create'], ['auth', 'can:member.manage']);
    $r->post('/master/anggota', [MemberController::class, 'store'], ['auth', 'can:member.manage']);
    $r->get('/master/anggota/{id:\d+}/ubah', [MemberController::class, 'edit'], ['auth', 'can:member.manage']);
    $r->post('/master/anggota/{id:\d+}', [MemberController::class, 'update'], ['auth', 'can:member.manage']);
    $r->get('/anggota/{id:\d+}', [MemberController::class, 'show'], ['auth', 'can:member.view.all|member.view.team|member.view.self']);

    // Master data: regu
    $r->get('/master/ketua-regu', [TeamController::class, 'index'], ['auth', 'can:team_leader.manage']);
    $r->get('/master/ketua-regu/baru', [TeamController::class, 'create'], ['auth', 'can:team_leader.manage']);
    $r->post('/master/ketua-regu', [TeamController::class, 'store'], ['auth', 'can:team_leader.manage']);
    $r->get('/master/ketua-regu/{id:\d+}/ubah', [TeamController::class, 'edit'], ['auth', 'can:team_leader.manage']);
    $r->post('/master/ketua-regu/{id:\d+}', [TeamController::class, 'update'], ['auth', 'can:team_leader.manage']);

    // Master data: pengguna
    $r->get('/master/pengguna', [UserController::class, 'index'], ['auth', 'can:user.manage']);
    $r->get('/master/pengguna/baru', [UserController::class, 'create'], ['auth', 'can:user.manage']);
    $r->post('/master/pengguna', [UserController::class, 'store'], ['auth', 'can:user.manage']);
    $r->get('/master/pengguna/{id:\d+}/ubah', [UserController::class, 'edit'], ['auth', 'can:user.manage']);
    $r->post('/master/pengguna/{id:\d+}', [UserController::class, 'update'], ['auth', 'can:user.manage']);
    $r->post('/master/pengguna/{id:\d+}/status', [UserController::class, 'setStatus'], ['auth', 'can:user.manage']);
    $r->post('/master/pengguna/{id:\d+}/reset-password', [UserController::class, 'resetPassword'], ['auth', 'can:user.manage']);
    $r->post('/master/pengguna/{id:\d+}/buka-kunci', [UserController::class, 'unlock'], ['auth', 'can:user.manage']);

    // Transaksi. Baca: dibatasi cakupan data (semua / regu / diri sendiri) di query. Tulis: hanya pembuat (Ketua Regu).
    $viewTrx = 'can:transaction.view.all|transaction.view.team|transaction.view.self';
    $r->get('/transaksi/simpanan', [TransactionController::class, 'savings'], ['auth', $viewTrx]);
    $r->get('/transaksi/simpanan/baru', [TransactionController::class, 'createSaving'], ['auth', 'can:transaction.create']);
    $r->post('/transaksi/simpanan', [TransactionController::class, 'storeSaving'], ['auth', 'can:transaction.create']);
    $r->get('/transaksi/simpanan/{id:\d+}/ubah', [TransactionController::class, 'editSaving'], ['auth', 'can:transaction.create']);
    $r->post('/transaksi/simpanan/{id:\d+}', [TransactionController::class, 'updateSaving'], ['auth', 'can:transaction.create']);
    $r->post('/transaksi/{id:\d+}/ajukan', [TransactionController::class, 'submit'], ['auth', 'can:transaction.create']);
    $r->post('/transaksi/{id:\d+}/batal', [TransactionController::class, 'cancel'], ['auth', 'can:transaction.create']);
    $r->post('/transaksi/{id:\d+}/koreksi', [TransactionController::class, 'reverse'], ['auth', 'can:transaction.create']);
    $r->get('/transaksi/pinjaman', [TransactionController::class, 'loans'], ['auth', $viewTrx]);
    $r->get('/transaksi/pinjaman/simulasi', [TransactionController::class, 'loanSimulation'], ['auth', $viewTrx]);
    $r->get('/transaksi/pinjaman/baru', [TransactionController::class, 'createLoan'], ['auth', 'can:transaction.create']);
    $r->post('/transaksi/pinjaman', [TransactionController::class, 'storeLoan'], ['auth', 'can:transaction.create']);
    $r->get('/transaksi/pinjaman/{id:\d+}/ubah', [TransactionController::class, 'editLoan'], ['auth', 'can:transaction.create']);
    $r->post('/transaksi/pinjaman/{id:\d+}', [TransactionController::class, 'updateLoan'], ['auth', 'can:transaction.create']);
    $r->get('/transaksi/angsuran', [TransactionController::class, 'payments'], ['auth', $viewTrx]);
    $r->get('/transaksi/angsuran/tagihan', [TransactionController::class, 'dues'], ['auth', $viewTrx]);
    $r->get('/transaksi/angsuran/baru', [TransactionController::class, 'createPayment'], ['auth', 'can:transaction.create']);
    $r->post('/transaksi/angsuran', [TransactionController::class, 'storePayment'], ['auth', 'can:transaction.create']);
    $r->get('/transaksi/angsuran/{id:\d+}/ubah', [TransactionController::class, 'editPayment'], ['auth', 'can:transaction.create']);
    $r->post('/transaksi/angsuran/{id:\d+}', [TransactionController::class, 'updatePayment'], ['auth', 'can:transaction.create']);
    $r->get('/transaksi/riwayat', [TransactionController::class, 'history'], ['auth', $viewTrx]);
    $r->get('/transaksi/{id:\d+}', [TransactionController::class, 'show'], ['auth', $viewTrx]);

    // Validasi transaksi (Head dan Pemeriksa). Kewenangan per transaksi ditegakkan lagi di ValidationService.
    $r->get('/validasi', [ValidationController::class, 'queue'], ['auth', 'can:transaction.validate']);
    $r->get('/validasi/riwayat', [ValidationController::class, 'history'], ['auth', 'can:transaction.validate']);
    $r->post('/validasi/{id:\d+}/setujui', [ValidationController::class, 'approve'], ['auth', 'can:transaction.validate']);
    $r->post('/validasi/{id:\d+}/tolak', [ValidationController::class, 'reject'], ['auth', 'can:transaction.validate']);

    // Laporan (Phase 12). Hanya baca; cakupan data dipaksa di ReportService. Unduhan CSV hanya untuk report.export.
    $viewReport = 'can:report.view.global|report.view.team|report.view.self';
    $r->get('/laporan/simpanan', [ReportController::class, 'simpanan'], ['auth', $viewReport]);
    $r->get('/laporan/pinjaman', [ReportController::class, 'pinjaman'], ['auth', $viewReport]);
    $r->get('/laporan/angsuran', [ReportController::class, 'angsuran'], ['auth', $viewReport]);
    $r->get('/laporan/saldo', [ReportController::class, 'saldo'], ['auth', $viewReport]);
    $r->get('/laporan/transaksi', [ReportController::class, 'transaksi'], ['auth', $viewReport]);
    $r->get('/laporan/regu', [ReportController::class, 'regu'], ['auth', 'can:report.view.global']);
    $r->get('/laporan/anggota', [ReportController::class, 'members'], ['auth', 'can:report.view.global|report.view.team']);
    $r->get('/laporan/anggota/{id:\d+}', [ReportController::class, 'member'], ['auth', 'can:report.view.global|report.view.team']);
    $r->get('/laporan/{key:[a-z]+}/unduh', [ReportController::class, 'download'], ['auth', 'can:report.export']);

    // Audit log (Head dan Pemeriksa). Hanya baca; unduhan butuh report.export juga (dicek di controller) dan tercatat.
    $r->get('/sistem/audit', [AuditController::class, 'index'], ['auth', 'can:audit.view']);
    $r->get('/sistem/audit/unduh', [AuditController::class, 'download'], ['auth', 'can:audit.view']);
    $r->get('/sistem/audit/{id:\d+}', [AuditController::class, 'show'], ['auth', 'can:audit.view']);

    // Pengaturan koperasi
    $r->get('/sistem/pengaturan', [SettingController::class, 'index'], ['auth', 'can:settings.manage']);
    $r->post('/sistem/pengaturan', [SettingController::class, 'update'], ['auth', 'can:settings.manage']);

    // Denyut pembaruan langsung: hanya angka penanda perubahan, jadi cukup login (tanpa izin khusus).
    $r->get('/live/tick', [LiveController::class, 'tick'], ['auth']);

    $r->get('/health', [HealthController::class, 'index']);

    // Halaman acuan gaya. Hanya hidup bila APP_ENV=local (dicek di controller). Hapus sebelum produksi.
    $r->get('/styleguide', [StyleguideController::class, 'index']);
};
