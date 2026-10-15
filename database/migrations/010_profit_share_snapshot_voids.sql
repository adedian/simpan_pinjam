-- 010: pembatalan snapshot bagi hasil.
--
-- Snapshot (migrasi 009) tidak pernah diubah atau dihapus. Pembatalan dicatat sebagai baris TERPISAH yang hanya-tambah:
-- siapa, kapan, dan alasannya. Snapshot yang dibatalkan tetap tersimpan dan terbaca untuk riwayat, tetapi tidak lagi
-- dianggap sebagai penetapan yang berlaku. Tidak mengubah transaksi, saldo, atau kas.
--
-- Satu snapshot hanya bisa dibatalkan sekali (UNIQUE snapshot_id).

CREATE TABLE profit_share_snapshot_voids (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    snapshot_id BIGINT UNSIGNED NOT NULL,
    reason      VARCHAR(255) NOT NULL,
    voided_by   INT UNSIGNED NULL,
    voided_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_snapshot_void (snapshot_id),
    CONSTRAINT fk_snapshot_void_snapshot FOREIGN KEY (snapshot_id) REFERENCES profit_share_snapshots (id),
    CONSTRAINT fk_snapshot_void_user FOREIGN KEY (voided_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

-- Aturan "satu snapshot per (periode, bulan data)" kini hanya untuk snapshot yang MASIH BERLAKU (belum dibatalkan),
-- sehingga setelah pembatalan boleh ditetapkan ulang. Penjagaannya pindah ke aplikasi (ProfitReport::createSnapshot,
-- di bawah kunci baris periode). Indeks biasa menggantikan indeks unik; ia juga dipakai kunci asing period_id.
ALTER TABLE profit_share_snapshots
    ADD KEY ix_profit_snapshot_period (period_id, as_of_month),
    DROP INDEX uq_profit_snapshot;

-- @@

CREATE TRIGGER trg_snapshot_voids_bu BEFORE UPDATE ON profit_share_snapshot_voids FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Catatan pembatalan snapshot tidak boleh diubah';
END

-- @@

CREATE TRIGGER trg_snapshot_voids_bd BEFORE DELETE ON profit_share_snapshot_voids FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Catatan pembatalan snapshot tidak boleh dihapus';
END
