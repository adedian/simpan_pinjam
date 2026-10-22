<?php
declare(strict_types=1);

/**
 * Matriks peran -> izin. Satu pengguna boleh memiliki beberapa peran
 * (Purwati = HEAD + KETUA_REGU); izinnya digabung.
 *
 * Kode peran di database tetap HEAD, KETUA_REGU, dan ANGGOTA; yang tampil ke pengguna adalah labelnya:
 *   HEAD       = Superadmin   mengelola data, menyetujui/menolak SEMUA transaksi, melihat semua laporan dan audit
 *   KETUA_REGU = Admin        mencatat transaksi regunya dan melihat data regunya
 *   ANGGOTA    = Anggota      hanya MELIHAT data dirinya sendiri (tidak bisa mengubah apa pun)
 * Tidak ada peran Pemeriksa: Superadmin menyetujui semuanya, termasuk transaksi yang ia catat sendiri.
 *
 * Akhiran izin menunjukkan cakupan DATA yang boleh dibaca:
 *   .all  = seluruh koperasi   .team = hanya regu sendiri   .self = hanya diri sendiri
 * Cakupan ditegakkan di query model (Phase 4-5), bukan hanya di menu.
 */
return [
    'labels' => [
        'HEAD'       => 'Superadmin',
        'KETUA_REGU' => 'Admin',
        'ANGGOTA'    => 'Anggota',
    ],

    'roles' => [
        'HEAD' => [
            'dashboard.view', 'profile.view',
            'team_leader.manage', 'member.view.all', 'member.view.self', 'member.manage', 'user.manage',
            'transaction.view.all', 'transaction.validate',
            'report.view.global', 'report.export',
            'settings.manage', 'audit.view', 'profit.snapshot', 'payout.manage', 'expense.manage',
        ],
        'KETUA_REGU' => [
            'dashboard.view', 'profile.view',
            'member.view.team', 'member.view.self',
            'transaction.view.team', 'transaction.create',
            'report.view.team',
        ],
        'ANGGOTA' => [
            'dashboard.view', 'profile.view',
            'member.view.self',
            'transaction.view.self',
            'report.view.self',
        ],
    ],
];
