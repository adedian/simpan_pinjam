<?php
declare(strict_types=1);

use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\BiayaController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\LiveController;
use App\Controllers\MemberController;
use App\Controllers\MemberLookupController;
use App\Controllers\PayoutController;
use App\Controllers\ProfitConfigController;
use App\Controllers\ProfileController;
use App\Controllers\PwaController;
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

    // Master data: anggota. Daftar/detail dibatasi cakupan data; ubah-data hanya untuk pengelola (Superadmin).
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
    $r->get('/transaksi/angsuran/tunggakan', [TransactionController::class, 'overdue'], ['auth', $viewTrx]);
    $r->get('/transaksi/angsuran/baru', [TransactionController::class, 'createPayment'], ['auth', 'can:transaction.create']);
    $r->post('/transaksi/angsuran', [TransactionController::class, 'storePayment'], ['auth', 'can:transaction.create']);
    $r->get('/transaksi/angsuran/{id:\d+}/ubah', [TransactionController::class, 'editPayment'], ['auth', 'can:transaction.create']);
    $r->post('/transaksi/angsuran/{id:\d+}', [TransactionController::class, 'updatePayment'], ['auth', 'can:transaction.create']);
    $r->get('/transaksi/riwayat', [TransactionController::class, 'history'], ['auth', $viewTrx]);
    $r->get('/transaksi/{id:\d+}', [TransactionController::class, 'show'], ['auth', $viewTrx]);

    // Validasi transaksi (Superadmin). Kewenangan per transaksi ditegakkan lagi di ValidationService.
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
    // Report Pembagian Bunga per anggota (ProfitShare). Snapshot hanya-tambah, hanya Superadmin.
    $r->get('/laporan/bagihasil', [ReportController::class, 'bagihasil'], ['auth', $viewReport]);
    $r->get('/laporan/bagihasil/{id:\d+}', [ReportController::class, 'bagihasilMember'], ['auth', $viewReport]);
    $r->post('/laporan/bagihasil/snapshot', [ReportController::class, 'bagihasilSnapshot'], ['auth', 'can:profit.snapshot']);
    $r->post('/laporan/bagihasil/snapshot/batal', [ReportController::class, 'bagihasilSnapshotVoid'], ['auth', 'can:profit.snapshot']);
    $r->get('/laporan/regu', [ReportController::class, 'regu'], ['auth', 'can:report.view.global']);
    $r->get('/laporan/shu', [ReportController::class, 'shu'], ['auth', 'can:report.view.global']);
    $r->get('/laporan/anggota', [ReportController::class, 'members'], ['auth', 'can:report.view.global|report.view.team']);
    $r->get('/laporan/anggota/{id:\d+}', [ReportController::class, 'member'], ['auth', 'can:report.view.global|report.view.team']);
    // Formulir cetak per anggota (Rekap Pinjaman, Tabungan Hari Raya): satu anggota, atau massal per regu/pencarian.
    $r->get('/laporan/anggota/{id:\d+}/{kind:pinjaman|tabungan}', [ReportController::class, 'sheet'], ['auth', 'can:report.view.global|report.view.team']);
    $r->get('/laporan/anggota/cetak/{kind:pinjaman|tabungan}', [ReportController::class, 'sheets'], ['auth', 'can:report.view.global|report.view.team']);
    $r->get('/laporan/{key:[a-z]+}/unduh', [ReportController::class, 'download'], ['auth', 'can:report.export']);

    // Biaya operasional (Superadmin), dibebankan ke Kas SHU. Validasi tetap lewat /validasi.
    $r->get('/biaya', [BiayaController::class, 'index'], ['auth', 'can:expense.manage']);
    $r->get('/biaya/baru', [BiayaController::class, 'create'], ['auth', 'can:expense.manage']);
    $r->post('/biaya', [BiayaController::class, 'store'], ['auth', 'can:expense.manage']);
    $r->get('/biaya/{id:\d+}/ubah', [BiayaController::class, 'edit'], ['auth', 'can:expense.manage']);
    $r->post('/biaya/{id:\d+}', [BiayaController::class, 'update'], ['auth', 'can:expense.manage']);
    $r->post('/biaya/{id:\d+}/ajukan', [BiayaController::class, 'submit'], ['auth', 'can:expense.manage']);
    $r->post('/biaya/{id:\d+}/batal', [BiayaController::class, 'cancel'], ['auth', 'can:expense.manage']);

    // Pencairan Akhir (Superadmin). Disusun dari snapshot bagi hasil; hanya-tambah, pembatalan wajib beralasan.
    $r->get('/pencairan', [PayoutController::class, 'index'], ['auth', 'can:payout.manage']);
    $r->get('/pencairan/baru', [PayoutController::class, 'create'], ['auth', 'can:payout.manage']);
    $r->post('/pencairan', [PayoutController::class, 'store'], ['auth', 'can:payout.manage']);
    $r->get('/pencairan/{id:\d+}', [PayoutController::class, 'show'], ['auth', 'can:payout.manage']);
    $r->get('/pencairan/{id:\d+}/unduh', [PayoutController::class, 'download'], ['auth', 'can:payout.manage']);   // unduhan juga butuh report.export (dicek di controller)
    $r->post('/pencairan/{id:\d+}/terima', [PayoutController::class, 'receive'], ['auth', 'can:payout.manage']);
    $r->post('/pencairan/{id:\d+}/batal', [PayoutController::class, 'void'], ['auth', 'can:payout.manage']);

    // Audit log (Superadmin). Hanya baca; unduhan butuh report.export juga (dicek di controller) dan tercatat.
    $r->get('/sistem/audit', [AuditController::class, 'index'], ['auth', 'can:audit.view']);
    $r->get('/sistem/audit/unduh', [AuditController::class, 'download'], ['auth', 'can:audit.view']);
    $r->get('/sistem/audit/{id:\d+}', [AuditController::class, 'show'], ['auth', 'can:audit.view']);

    // Komponen pembagian bunga (Superadmin): versi baru berlaku mulai bulan tertentu, versi lama tidak berubah.
    $r->get('/sistem/pembagian-bunga', [ProfitConfigController::class, 'index'], ['auth', 'can:settings.manage']);
    $r->post('/sistem/pembagian-bunga', [ProfitConfigController::class, 'store'], ['auth', 'can:settings.manage']);

    // Pengaturan koperasi
    $r->get('/sistem/pengaturan', [SettingController::class, 'index'], ['auth', 'can:settings.manage']);
    $r->post('/sistem/pengaturan', [SettingController::class, 'update'], ['auth', 'can:settings.manage']);

    // Pencarian anggota untuk kotak pilih anggota. Hak akses per konteks ditegakkan di MemberLookup (SQL); izin di sini hanya pintu masuk.
    $r->get('/api/anggota', [MemberLookupController::class, 'search'], ['auth', 'can:transaction.create|member.view.all|member.view.team|member.view.self|team_leader.manage|user.manage']);

    // Denyut pembaruan langsung: hanya angka penanda perubahan, jadi cukup login (tanpa izin khusus).
    $r->get('/live/tick', [LiveController::class, 'tick'], ['auth']);

    $r->get('/health', [HealthController::class, 'index']);

    // PWA: manifest dan halaman offline. Publik dan tanpa data (diambil browser/service worker tanpa login).
    $r->get('/manifest.webmanifest', [PwaController::class, 'manifest']);
    $r->get('/offline', [PwaController::class, 'offline']);

    // Halaman acuan gaya. Hanya hidup bila APP_ENV=local (dicek di controller). Hapus sebelum produksi.
    $r->get('/styleguide', [StyleguideController::class, 'index']);
};
