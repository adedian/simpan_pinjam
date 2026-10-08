<?php
declare(strict_types=1);

/**
 * Struktur menu. Satu sumber untuk semua peran: item tampil bila pengguna punya SALAH SATU izin di 'can'.
 * 'phase'     = fase pengembangan yang mengaktifkan halamannya.
 * 'available' = false membuat item tampil nonaktif (belum dibangun). Ubah ke true saat halaman jadi.
 * 'badge'     = (opsional) kunci penanda jumlah di menu; angkanya dihitung LiveFeed::badges() dan diperbarui langsung.
 * Menyembunyikan menu BUKAN pengamanan; route tetap dilindungi middleware 'can:'.
 */
return [
    ['label' => 'Dashboard', 'items' => [
        ['label' => 'Dashboard', 'path' => '/', 'icon' => 'grid', 'can' => ['dashboard.view'], 'phase' => 11, 'available' => true],
    ]],

    ['label' => 'Master Data', 'items' => [
        ['label' => 'Data Ketua Regu', 'path' => '/master/ketua-regu', 'icon' => 'users', 'can' => ['team_leader.manage'], 'phase' => 5, 'available' => true],
        ['label' => 'Data Anggota', 'path' => '/master/anggota', 'icon' => 'user', 'can' => ['member.view.all', 'member.view.team'], 'phase' => 5, 'available' => true],
        ['label' => 'Data Pengguna', 'path' => '/master/pengguna', 'icon' => 'key', 'can' => ['user.manage'], 'phase' => 5, 'available' => true],
    ]],

    ['label' => 'Transaksi', 'items' => [
        ['label' => 'Simpanan', 'path' => '/transaksi/simpanan', 'icon' => 'download', 'can' => ['transaction.view.all', 'transaction.view.team', 'transaction.view.self'], 'phase' => 6, 'available' => true],
        ['label' => 'Pinjaman', 'path' => '/transaksi/pinjaman', 'icon' => 'upload', 'can' => ['transaction.view.all', 'transaction.view.team', 'transaction.view.self'], 'phase' => 7, 'available' => true],
        ['label' => 'Angsuran', 'path' => '/transaksi/angsuran', 'icon' => 'card', 'can' => ['transaction.view.all', 'transaction.view.team', 'transaction.view.self'], 'phase' => 8, 'available' => true],
        ['label' => 'Riwayat Transaksi', 'path' => '/transaksi/riwayat', 'icon' => 'clock', 'can' => ['transaction.view.all', 'transaction.view.team', 'transaction.view.self'], 'phase' => 6, 'available' => true],
    ]],

    ['label' => 'Validasi', 'items' => [
        ['label' => 'Menunggu Validasi', 'path' => '/validasi', 'icon' => 'check', 'can' => ['transaction.validate'], 'phase' => 9, 'available' => true, 'badge' => 'validasi'],
        ['label' => 'Riwayat Validasi', 'path' => '/validasi/riwayat', 'icon' => 'clock', 'can' => ['transaction.validate'], 'phase' => 9, 'available' => true],
    ]],

    ['label' => 'Laporan', 'items' => [
        ['label' => 'Rekap Simpanan', 'path' => '/laporan/simpanan', 'icon' => 'layers', 'can' => ['report.view.global', 'report.view.team', 'report.view.self'], 'phase' => 12, 'available' => true],
        ['label' => 'Rekap Pinjaman', 'path' => '/laporan/pinjaman', 'icon' => 'layers', 'can' => ['report.view.global', 'report.view.team', 'report.view.self'], 'phase' => 12, 'available' => true],
        ['label' => 'Rekap Angsuran', 'path' => '/laporan/angsuran', 'icon' => 'layers', 'can' => ['report.view.global', 'report.view.team', 'report.view.self'], 'phase' => 12, 'available' => true],
        ['label' => 'Rekap Saldo', 'path' => '/laporan/saldo', 'icon' => 'chart', 'can' => ['report.view.global', 'report.view.team', 'report.view.self'], 'phase' => 12, 'available' => true],
        ['label' => 'Laporan Transaksi', 'path' => '/laporan/transaksi', 'icon' => 'file', 'can' => ['report.view.global', 'report.view.team', 'report.view.self'], 'phase' => 12, 'available' => true],
        ['label' => 'Laporan Per Ketua Regu', 'path' => '/laporan/regu', 'icon' => 'file', 'can' => ['report.view.global'], 'phase' => 12, 'available' => true],
        ['label' => 'Laporan Per Anggota', 'path' => '/laporan/anggota', 'icon' => 'file', 'can' => ['report.view.global', 'report.view.team'], 'phase' => 12, 'available' => true],
    ]],

    ['label' => 'Sistem', 'items' => [
        ['label' => 'Profil', 'path' => '/profil', 'icon' => 'user', 'can' => ['profile.view'], 'phase' => 4, 'available' => true],
        ['label' => 'Pengaturan', 'path' => '/sistem/pengaturan', 'icon' => 'sliders', 'can' => ['settings.manage'], 'phase' => 5, 'available' => true],
        ['label' => 'Audit Log', 'path' => '/sistem/audit', 'icon' => 'shield', 'can' => ['audit.view'], 'phase' => 13, 'available' => true],
    ]],
];
