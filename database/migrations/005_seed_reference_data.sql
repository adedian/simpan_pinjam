-- 005: data acuan (bukan data dummy): peran dan pengaturan awal sesuai keputusan Phase 1.

INSERT INTO roles (code, name) VALUES
    ('HEAD',       'Head'),
    ('PEMERIKSA',  'Pemeriksa (validator kedua)'),
    ('KETUA_REGU', 'Ketua Regu'),
    ('ANGGOTA',    'Anggota');

-- @@

INSERT INTO settings (setting_key, setting_value, value_type, description) VALUES
    ('interest_rate_pct_month',     '2.00',     'DECIMAL', 'Bunga pinjaman flat per bulan (%), dikali tenor'),
    ('loan_tenor_min',              '1',        'INT',     'Tenor pinjaman minimum (bulan)'),
    ('loan_tenor_max',              '5',        'INT',     'Tenor pinjaman maksimum (bulan); tidak boleh melewati akhir periode'),
    ('loan_max_amount',             '15000000', 'INT',     'Plafon pinjaman per pencairan (Rp). Nilai awal = pinjaman terbesar di Excel; sesuaikan bila perlu'),
    ('loan_max_active_per_member',  '0',        'INT',     'Batas pinjaman aktif per anggota; 0 = tidak dibatasi'),
    ('saving_pokok_amount',         '50000',    'INT',     'Simpanan pokok (Rp). BELUM FINAL: Excel menghitung 50.000, pengumuman menyebut 25.000'),
    ('withdrawals_enabled',         '0',        'BOOL',    'Penarikan tabungan sebelum pencairan akhir (default mati)'),
    ('allow_negative_cash',         '0',        'BOOL',    'Izinkan pencairan melebihi kas tersedia (default tidak)'),
    ('profit_share_saver_pct',      '40',       'INT',     'Bagian bunga untuk penabung (%)'),
    ('profit_share_borrower_pct',   '40',       'INT',     'Bagian bunga untuk peminjam (%)'),
    ('profit_share_shu_pct',        '20',       'INT',     'Bagian bunga untuk Kas SHU (%)'),
    ('reserve_pct',                 '5.00',     'DECIMAL', 'Potongan cadangan dari bagi hasil penabung dan peminjam (%)'),
    ('shu_member_pct',              '60',       'INT',     'Bagian SHU untuk anggota (%)'),
    ('shu_manager_pct',             '40',       'INT',     'Bagian SHU untuk pengelola (%)');
