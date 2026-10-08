<?php
declare(strict_types=1);

/**
 * Matriks peran -> izin. Satu pengguna boleh memiliki beberapa peran
 * (Purwati = HEAD + KETUA_REGU); izinnya digabung.
 *
 * Akhiran izin menunjukkan cakupan DATA yang boleh dibaca:
 *   .all  = seluruh koperasi   .team = hanya regu sendiri   .self = hanya diri sendiri
 * Cakupan ditegakkan di query model (Phase 4-5), bukan hanya di menu.
 *
 * Aturan pemisahan tugas (Q3), ditegakkan di layanan validasi (Phase 9):
 *   - pembuat transaksi tidak boleh memvalidasi transaksinya sendiri
 *   - transaksi milik Head / regu Head / pinjaman Head hanya boleh divalidasi PEMERIKSA
 */
return [
    'labels' => [
        'HEAD'       => 'Head',
        'PEMERIKSA'  => 'Pemeriksa',
        'KETUA_REGU' => 'Ketua Regu',
        'ANGGOTA'    => 'Anggota',
    ],

    'roles' => [
        'HEAD' => [
            'dashboard.view', 'profile.view',
            'team_leader.manage', 'member.view.all', 'member.view.self', 'member.manage', 'user.manage',
            'transaction.view.all', 'transaction.validate',
            'report.view.global', 'report.export',
            'settings.manage', 'audit.view',
        ],
        'PEMERIKSA' => [
            'dashboard.view', 'profile.view',
            'member.view.all', 'member.view.self',
            'transaction.view.all', 'transaction.validate',
            'report.view.global', 'report.export',
            'audit.view',
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
