-- 013: komponen pembagian bunga yang dinamis (nama dan persentase dapat diubah, komponen dana dapat ditambah).
--
-- Sebelumnya pembagian bunga hanya tiga angka di tabel settings (penabung / peminjam / Kas SHU). Kini konfigurasinya
-- berupa DAFTAR KOMPONEN yang berlaku mulai bulan tertentu (versi), hanya-tambah:
--   * satu komponen PENABUNG dan satu PEMINJAM (dibagi ke anggota menurut saldo / dasar pinjaman, rumus tidak berubah),
--   * satu komponen SHU (Kas SHU: sumber laporan Jasa Pinjaman dan Kas SHU serta biaya operasional),
--   * komponen DANA sebanyak yang diperlukan (Dana Cadangan, Dana Operasional, dst.): hanya total, tanpa dibagi per anggota.
-- Persentase disimpan dalam seperseratus persen (2000 = 20,00%) dan WAJIB berjumlah 10000 per versi (dijaga aplikasi).
--
-- Versi yang sudah dipakai tidak pernah diubah: perubahan nama/persentase = versi BARU dengan bulan berlaku baru, jadi
-- bulan-bulan lama tetap dihitung dengan persentase lamanya. Nilai awal (berlaku sejak awal) disalin dari Pengaturan.
-- Nilai profit_share_*_pct di tabel settings tidak lagi dipakai (baris dibiarkan, tidak dihapus).

CREATE TABLE profit_share_configs (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    effective_from DATE NOT NULL COMMENT 'Bulan (tanggal 1) bunga diterima pertama yang memakai versi ini',
    note           VARCHAR(255) NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_profit_config_from (effective_from),
    CONSTRAINT fk_profit_config_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

CREATE TABLE profit_share_config_items (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    config_id      BIGINT UNSIGNED NOT NULL,
    position       TINYINT UNSIGNED NOT NULL COMMENT 'Urutan tampil',
    code           VARCHAR(20) NOT NULL COMMENT 'Identitas stabil komponen antar versi (nama boleh berubah)',
    kind           ENUM('PENABUNG','PEMINJAM','SHU','DANA') NOT NULL,
    name           VARCHAR(60) NOT NULL,
    pct_hundredths INT UNSIGNED NOT NULL COMMENT 'Seperseratus persen: 2000 = 20,00%',
    PRIMARY KEY (id),
    UNIQUE KEY uq_profit_item_code (config_id, code),
    UNIQUE KEY uq_profit_item_pos (config_id, position),
    CONSTRAINT fk_profit_item_config FOREIGN KEY (config_id) REFERENCES profit_share_configs (id),
    CONSTRAINT ck_profit_item_pct CHECK (pct_hundredths <= 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

CREATE TRIGGER trg_profit_configs_bu BEFORE UPDATE ON profit_share_configs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Versi konfigurasi pembagian bunga tidak boleh diubah; buat versi baru';
END

-- @@

CREATE TRIGGER trg_profit_configs_bd BEFORE DELETE ON profit_share_configs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Versi konfigurasi pembagian bunga tidak boleh dihapus';
END

-- @@

CREATE TRIGGER trg_profit_items_bu BEFORE UPDATE ON profit_share_config_items FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Komponen konfigurasi pembagian bunga tidak boleh diubah; buat versi baru';
END

-- @@

CREATE TRIGGER trg_profit_items_bd BEFORE DELETE ON profit_share_config_items FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Komponen konfigurasi pembagian bunga tidak boleh dihapus';
END

-- @@

-- Versi awal: berlaku sejak awal, disalin dari Pengaturan yang ada.
INSERT INTO profit_share_configs (effective_from, note)
VALUES ('2000-01-01', 'Nilai awal dari Pengaturan sebelum migrasi 013');

-- @@

INSERT INTO profit_share_config_items (config_id, position, code, kind, name, pct_hundredths)
SELECT (SELECT id FROM profit_share_configs WHERE effective_from = '2000-01-01'), 1, 'PENABUNG', 'PENABUNG', 'Penabung', CAST(setting_value AS UNSIGNED) * 100
FROM settings WHERE setting_key = 'profit_share_saver_pct';

-- @@

INSERT INTO profit_share_config_items (config_id, position, code, kind, name, pct_hundredths)
SELECT (SELECT id FROM profit_share_configs WHERE effective_from = '2000-01-01'), 2, 'PEMINJAM', 'PEMINJAM', 'Peminjam', CAST(setting_value AS UNSIGNED) * 100
FROM settings WHERE setting_key = 'profit_share_borrower_pct';

-- @@

INSERT INTO profit_share_config_items (config_id, position, code, kind, name, pct_hundredths)
SELECT (SELECT id FROM profit_share_configs WHERE effective_from = '2000-01-01'), 3, 'SHU', 'SHU', 'Kas SHU', CAST(setting_value AS UNSIGNED) * 100
FROM settings WHERE setting_key = 'profit_share_shu_pct';

-- @@

-- Snapshot menyimpan komponen yang dipakai (nama, persentase, total tiap komponen) agar laporan lama tidak berubah.
-- Kolom NULL untuk snapshot lama: tampilannya memakai saver_pct / borrower_pct / shu_pct seperti sebelumnya.
ALTER TABLE profit_share_snapshots
    ADD COLUMN components_json MEDIUMTEXT NULL COMMENT 'Komponen pembagian yang dipakai: versi, nama, persentase, total' AFTER shu_pct;
