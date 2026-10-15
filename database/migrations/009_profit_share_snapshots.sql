-- 009: snapshot bagi hasil bunga (Report Pembagian Bunga).
--
-- Bagi hasil dihitung langsung oleh ProfitShare dari transaksi DISETUJUI, jadi angkanya bisa berubah saat pinjaman atau
-- simpanan baru masuk. Snapshot menyimpan hasil hitungan pada satu saat (per data bulan tertentu) supaya laporan yang
-- sudah ditetapkan tidak berubah diam-diam. Snapshot HANYA-TAMBAH: tidak ada UPDATE atau DELETE (dijaga trigger dan hak
-- akun aplikasi). Tidak mengunci transaksi atau periode, dan tidak mengubah saldo apa pun.
--
-- Satu snapshot per (periode, bulan data): mencegah penetapan ganda untuk keadaan data yang sama.

CREATE TABLE profit_share_snapshots (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_id        INT UNSIGNED NOT NULL,
    as_of_month      DATE NOT NULL COMMENT 'Bulan data terakhir yang dipakai (tanggal 1)',
    mode             VARCHAR(20) NOT NULL COMMENT 'EXCEL = meniru Excel koperasi; ATURAN = aturan Q8',
    saver_pct        INT NOT NULL,
    borrower_pct     INT NOT NULL,
    shu_pct          INT NOT NULL,
    reserve_pct      DECIMAL(5,2) NOT NULL,
    interest_pool    BIGINT NOT NULL COMMENT 'Pool penabung + peminjam (rupiah, dibulatkan)',
    undistributed    BIGINT NOT NULL COMMENT 'Bagian pool yang tidak terbagi (rupiah, dibulatkan)',
    gross_total      BIGINT NOT NULL COMMENT 'Jumlah bagi hasil bruto semua anggota',
    reserve_total    BIGINT NOT NULL COMMENT 'Jumlah potongan cadangan',
    saver_total      BIGINT NOT NULL COMMENT 'Jumlah bagi hasil penabung setelah cadangan (jumlah baris)',
    borrower_total   BIGINT NOT NULL COMMENT 'Jumlah bagi hasil peminjam setelah cadangan (jumlah baris)',
    cycles_json      MEDIUMTEXT NULL COMMENT 'Rincian per siklus (bunga bulan itu, pool, jumlah dasar) untuk penelusuran',
    note             VARCHAR(255) NULL,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_profit_snapshot (period_id, as_of_month),
    CONSTRAINT fk_profit_snapshot_period FOREIGN KEY (period_id) REFERENCES periods (id),
    CONSTRAINT fk_profit_snapshot_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

CREATE TABLE profit_share_lines (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    snapshot_id      BIGINT UNSIGNED NOT NULL,
    member_id        INT UNSIGNED NOT NULL,
    team_id          INT UNSIGNED NULL COMMENT 'Regu anggota saat snapshot dibuat',
    reserve_exempt   TINYINT(1) NOT NULL DEFAULT 0,
    saver_gross      DECIMAL(16,4) NOT NULL,
    borrower_gross   DECIMAL(16,4) NOT NULL,
    saver_net        BIGINT NOT NULL COMMENT 'Setelah cadangan, dibulatkan ke rupiah',
    borrower_net     BIGINT NOT NULL COMMENT 'Setelah cadangan, dibulatkan ke rupiah',
    savings_last     BIGINT NOT NULL DEFAULT 0 COMMENT 'Saldo tabungan akhir bulan data terakhir',
    outstanding_last BIGINT NOT NULL DEFAULT 0 COMMENT 'Sisa pinjaman akhir bulan data terakhir',
    detail_json      MEDIUMTEXT NULL COMMENT 'Rincian per siklus agar bisa ditelusuri tanpa menghitung ulang',
    PRIMARY KEY (id),
    UNIQUE KEY uq_profit_line (snapshot_id, member_id),
    CONSTRAINT fk_profit_line_snapshot FOREIGN KEY (snapshot_id) REFERENCES profit_share_snapshots (id),
    CONSTRAINT fk_profit_line_member FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

CREATE TRIGGER trg_profit_snapshots_bu BEFORE UPDATE ON profit_share_snapshots FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Snapshot bagi hasil tidak boleh diubah';
END

-- @@

CREATE TRIGGER trg_profit_snapshots_bd BEFORE DELETE ON profit_share_snapshots FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Snapshot bagi hasil tidak boleh dihapus';
END

-- @@

CREATE TRIGGER trg_profit_lines_bu BEFORE UPDATE ON profit_share_lines FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Baris snapshot bagi hasil tidak boleh diubah';
END

-- @@

CREATE TRIGGER trg_profit_lines_bd BEFORE DELETE ON profit_share_lines FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Baris snapshot bagi hasil tidak boleh dihapus';
END
