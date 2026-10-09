# Sistem Simpan Pinjam Adem Ayem

*"yo nabung, yo ngutang"* — aplikasi web simpan pinjam untuk program THR Adem Ayem
(PKK RT 01 / RW 04). PHP native + PDO + MySQL/MariaDB, tanpa framework.

Alur utama: **Transaksi → Validasi → Saldo → Rekap → Laporan.**
Saldo tidak pernah disimpan sebagai angka: selalu dihitung dari transaksi berstatus
*DISETUJUI*. Transaksi menunggu atau ditolak tidak memengaruhi saldo, dan transaksi yang
sudah disetujui tidak diedit atau dihapus, hanya dikoreksi dengan transaksi pembalik.

## Persyaratan
- PHP 8.0+ (`pdo_mysql`, `mbstring`), MySQL 5.7+/MariaDB 10.4+
- Apache dengan `mod_rewrite` (XAMPP cukup) — semua permintaan diarahkan ke `public/`

## Pemasangan
1. Letakkan folder di `htdocs` (mis. `htdocs/simpan_pinjam`).
2. Buat berkas `.env` di akar proyek (tidak ikut repositori) berisi `APP_ENV`, `APP_DEBUG`, `APP_BASE_PATH`,
   `APP_URL`, `APP_FORCE_HTTPS`, `TRUSTED_PROXIES`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`,
   `DB_ADMIN_USER`, `DB_ADMIN_PASS`. Akun aplikasi sebaiknya berhak terbatas:
   `php database/tools/create_app_user.php`.
3. Pasang skema: `php database/tools/migrate.php`.
4. Buat akun pertama: `php database/tools/create_user.php`.
5. Periksa kesiapan: `php database/tools/preflight.php` (semua **GAGAL** harus beres sebelum
   dibuka untuk pengguna).

Impor data awal dari Excel (`database/tools/import_excel.php`) membutuhkan berkas sumber
milik koperasi dan tidak ikut repositori.

## Peran
| Peran | Ringkas |
|---|---|
| Head | Mengelola master data, pengguna, pengaturan; melihat semua; laporan dan audit |
| Pemeriksa | Memvalidasi transaksi (termasuk milik Head/regunya); laporan dan audit |
| Ketua Regu | Mencatat simpanan, pinjaman, angsuran, dan koreksi untuk regunya |
| Anggota | Hanya melihat data dan laporan dirinya |

Pencatat tidak boleh memvalidasi transaksinya sendiri. Matriks izin: `config/permissions.php`.

## Struktur
```
config/       rute, menu, izin, pengaturan        core/         inti (router, DB, sesi, CSRF, HTTPS)
controllers/  penanganan permintaan               middleware/   auth, izin, CSRF
models/       akses data dan cakupan              services/     aturan bisnis (validasi, pembalik, laporan, cadangan)
views/        tampilan                            public/       satu-satunya folder yang dapat diakses web
database/     migrasi + alat CLI                  storage/      sesi, log, cache, cadangan (tidak dilacak)
tests/        suite tes mandiri
```

## Tes
```
php tests/all.php          # semua suite berurutan (±5 menit)
php tests/all.php --fast   # tanpa simulasi, konkurensi, operasional (±3,5 menit)
php tests/all.php --perf   # tambah uji kinerja 33.600 transaksi
```
Tes memakai database terpisah `*_test` (dikosongkan setiap kali); **jangan pernah** mengarahkannya
ke database sungguhan. Butuh `.env.testing`.

## Cadangan
`php database/tools/backup.php` (tambahkan `--prove` untuk membuktikan cadangan bisa dipulihkan),
`php database/tools/restore.php --file=...` (ke database baru). Jadwalkan dan simpan salinan di luar komputer.

## Dokumentasi
