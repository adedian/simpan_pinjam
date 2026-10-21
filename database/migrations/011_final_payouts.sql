-- 011: Pencairan Akhir (rekap pembagian akhir tabungan + bagi hasil).
--
-- Satu pencairan disusun dari SATU snapshot bagi hasil yang masih berlaku (tabel 009): tabungan akhir, bagi hasil penabung,
-- bagi hasil peminjam, dan sisa pinjaman (pokok + bunga) diambil dari snapshot itu, jadi tidak berubah bila transaksi baru masuk.
-- Ditambah dua isian manual per anggota (potongan arisan, potongan lain) yang ditulis Superadmin saat menyusun.
--
--   uang diterima = tabungan + bagi hasil penabung + bagi hasil peminjam - sisa pinjaman - arisan - lain
--   dibulatkan KE BAWAH ke kelipatan rounding_unit; bila hasilnya <= 0, diterima 0 dan kekurangannya dicatat (shortfall)
--
-- Semua tabel HANYA-TAMBAH (trigger + hak akun aplikasi). Pembatalan dan penerimaan uang dicatat sebagai baris terpisah.
-- Tidak mengubah transaksi, saldo, atau kas: pencairan akhir adalah lembar rekap pembagian, bukan pembukuan transaksi.

CREATE TABLE final_payouts (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_id      INT UNSIGNED NOT NULL,
    snapshot_id    BIGINT UNSIGNED NOT NULL,
    rounding_unit  INT UNSIGNED NOT NULL COMMENT 'Pembulatan ke bawah: 1, 100, atau 1000',
    members_count  INT NOT NULL,
    savings_total  BIGINT NOT NULL,
    saver_total    BIGINT NOT NULL,
    borrower_total BIGINT NOT NULL,
    loan_total     BIGINT NOT NULL COMMENT 'Jumlah sisa pinjaman (pokok + bunga) yang dipotong',
    arisan_total   BIGINT NOT NULL,
    other_total    BIGINT NOT NULL,
    rounding_total BIGINT NOT NULL COMMENT 'Jumlah sisa pembulatan (tidak dibayarkan)',
    shortfall_total BIGINT NOT NULL COMMENT 'Jumlah kekurangan anggota yang potongannya melebihi haknya',
    net_total      BIGINT NOT NULL COMMENT 'Jumlah uang yang dibayarkan tunai',
    note           VARCHAR(255) NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_final_payout_period (period_id, created_at),
    CONSTRAINT fk_final_payout_period FOREIGN KEY (period_id) REFERENCES periods (id),
    CONSTRAINT fk_final_payout_snapshot FOREIGN KEY (snapshot_id) REFERENCES profit_share_snapshots (id),
    CONSTRAINT fk_final_payout_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

CREATE TABLE final_payout_lines (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payout_id        BIGINT UNSIGNED NOT NULL,
    member_id        INT UNSIGNED NOT NULL,
    team_id          INT UNSIGNED NULL,
    savings          BIGINT NOT NULL,
    saver_share      BIGINT NOT NULL,
    borrower_share   BIGINT NOT NULL,
    loan_outstanding BIGINT NOT NULL,
    arisan           BIGINT NOT NULL DEFAULT 0,
    other            BIGINT NOT NULL DEFAULT 0,
    deduction_note   VARCHAR(255) NULL,
    subtotal         BIGINT NOT NULL COMMENT 'Sebelum pembulatan; boleh negatif',
    rounding         BIGINT NOT NULL DEFAULT 0,
    shortfall        BIGINT NOT NULL DEFAULT 0,
    net_paid         BIGINT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_final_payout_line (payout_id, member_id),
    CONSTRAINT fk_final_line_payout FOREIGN KEY (payout_id) REFERENCES final_payouts (id),
    CONSTRAINT fk_final_line_member FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

CREATE TABLE final_payout_voids (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payout_id BIGINT UNSIGNED NOT NULL,
    reason    VARCHAR(255) NOT NULL,
    voided_by INT UNSIGNED NULL,
    voided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_final_payout_void (payout_id),
    CONSTRAINT fk_final_void_payout FOREIGN KEY (payout_id) REFERENCES final_payouts (id),
    CONSTRAINT fk_final_void_user FOREIGN KEY (voided_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

CREATE TABLE final_payout_receipts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    line_id     BIGINT UNSIGNED NOT NULL,
    received_on DATE NOT NULL,
    note        VARCHAR(255) NULL,
    recorded_by INT UNSIGNED NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_final_receipt_line (line_id),
    CONSTRAINT fk_final_receipt_line FOREIGN KEY (line_id) REFERENCES final_payout_lines (id),
    CONSTRAINT fk_final_receipt_user FOREIGN KEY (recorded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

CREATE TRIGGER trg_final_payouts_bu BEFORE UPDATE ON final_payouts FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pencairan akhir tidak boleh diubah';
END

-- @@

CREATE TRIGGER trg_final_payouts_bd BEFORE DELETE ON final_payouts FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pencairan akhir tidak boleh dihapus';
END

-- @@

CREATE TRIGGER trg_final_lines_bu BEFORE UPDATE ON final_payout_lines FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Baris pencairan akhir tidak boleh diubah';
END

-- @@

CREATE TRIGGER trg_final_lines_bd BEFORE DELETE ON final_payout_lines FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Baris pencairan akhir tidak boleh dihapus';
END

-- @@

CREATE TRIGGER trg_final_voids_bu BEFORE UPDATE ON final_payout_voids FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Catatan pembatalan pencairan tidak boleh diubah';
END

-- @@

CREATE TRIGGER trg_final_voids_bd BEFORE DELETE ON final_payout_voids FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Catatan pembatalan pencairan tidak boleh dihapus';
END

-- @@

CREATE TRIGGER trg_final_receipts_bu BEFORE UPDATE ON final_payout_receipts FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Catatan penerimaan uang tidak boleh diubah';
END

-- @@

CREATE TRIGGER trg_final_receipts_bd BEFORE DELETE ON final_payout_receipts FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Catatan penerimaan uang tidak boleh dihapus';
END
