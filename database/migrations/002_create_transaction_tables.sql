-- 002: tabel transaksi keuangan. Satu-satunya sumber angka uang adalah tabel `transactions`;
-- tabel lain hanya menyimpan RINCIAN. Tidak ada kolom saldo di mana pun.

CREATE TABLE transactions (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    trx_no            VARCHAR(20) NOT NULL COMMENT 'TRX-2026-000001',
    doc_no            VARCHAR(20) NOT NULL COMMENT 'SMP-/PJM-/ANG-/TRK-/BYA- + tahun + urut',
    type              ENUM('SIMPANAN','PENARIKAN','PENCAIRAN_PINJAMAN','ANGSURAN','BIAYA') NOT NULL,
    member_id         INT UNSIGNED NULL,
    team_id           INT UNSIGNED NULL COMMENT 'Regu saat transaksi dicatat (jejak audit), bukan dasar saldo regu',
    period_month_id   INT UNSIGNED NOT NULL,
    trx_date          DATE NOT NULL,
    amount            BIGINT NOT NULL COMMENT 'Selalu positif. Arah ditentukan oleh type dan reverses_id',
    status            ENUM('DRAFT','MENUNGGU_VALIDASI','DISETUJUI','DITOLAK','DIBATALKAN') NOT NULL DEFAULT 'DRAFT',
    reverses_id       BIGINT UNSIGNED NULL COMMENT 'Bila terisi: transaksi pembalik untuk transaksi DISETUJUI ini',
    description       VARCHAR(255) NULL,
    source            ENUM('APLIKASI','IMPOR_EXCEL') NOT NULL DEFAULT 'APLIKASI',
    import_ref        VARCHAR(80) NULL COMMENT 'Kunci idempotensi impor',
    created_by        INT UNSIGNED NULL,
    status_changed_at DATETIME NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        DATETIME NULL COMMENT 'Hanya untuk DRAFT',
    PRIMARY KEY (id),
    UNIQUE KEY uq_trx_no (trx_no),
    UNIQUE KEY uq_doc_no (doc_no),
    UNIQUE KEY uq_trx_import_ref (import_ref),
    UNIQUE KEY uq_trx_reverses (reverses_id) COMMENT 'Satu transaksi hanya bisa dibalik sekali',
    KEY ix_trx_status_type (status, type),
    KEY ix_trx_member (member_id, status),
    KEY ix_trx_month (period_month_id, status),
    KEY ix_trx_date (trx_date),
    CONSTRAINT fk_trx_member FOREIGN KEY (member_id) REFERENCES members (id),
    CONSTRAINT fk_trx_team FOREIGN KEY (team_id) REFERENCES team_leaders (id),
    CONSTRAINT fk_trx_month FOREIGN KEY (period_month_id) REFERENCES period_months (id),
    CONSTRAINT fk_trx_reverses FOREIGN KEY (reverses_id) REFERENCES transactions (id),
    CONSTRAINT fk_trx_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT ck_trx_amount CHECK (amount > 0),
    CONSTRAINT ck_trx_member CHECK (type = 'BIAYA' OR member_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

CREATE TABLE savings (
    transaction_id BIGINT UNSIGNED NOT NULL,
    kind           ENUM('POKOK','WAJIB','SUKARELA','CAMPURAN') NOT NULL
                   COMMENT 'CAMPURAN hanya untuk data impor Excel yang belum memisahkan pokok dan wajib',
    PRIMARY KEY (transaction_id),
    CONSTRAINT fk_savings_trx FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

CREATE TABLE expenses (
    transaction_id BIGINT UNSIGNED NOT NULL,
    category       VARCHAR(60) NOT NULL,
    fund_source    ENUM('SHU','KAS') NOT NULL DEFAULT 'SHU',
    PRIMARY KEY (transaction_id),
    CONSTRAINT fk_expenses_trx FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

-- Pinjaman. Tidak ada kolom status/sisa: keduanya dihitung di v_loan_balances dari transaksi DISETUJUI.
CREATE TABLE loans (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_id BIGINT UNSIGNED NOT NULL COMMENT 'Transaksi PENCAIRAN_PINJAMAN',
    member_id      INT UNSIGNED NOT NULL,
    principal      BIGINT NOT NULL,
    tenor_months   TINYINT UNSIGNED NOT NULL,
    rate_pct_month DECIMAL(5,2) NOT NULL COMMENT 'Salinan tarif saat pencairan; perubahan pengaturan tidak berlaku surut',
    total_interest BIGINT NOT NULL COMMENT 'Bunga flat dibukukan di muka = pokok x tarif x tenor',
    disbursed_on   DATE NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_loans_trx (transaction_id),
    KEY ix_loans_member (member_id),
    CONSTRAINT fk_loans_trx FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE,
    CONSTRAINT fk_loans_member FOREIGN KEY (member_id) REFERENCES members (id),
    CONSTRAINT ck_loans_principal CHECK (principal > 0),
    CONSTRAINT ck_loans_tenor CHECK (tenor_months BETWEEN 1 AND 12),
    CONSTRAINT ck_loans_interest CHECK (total_interest >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

-- Jadwal cicilan. Cicilan terakhir menyerap sisa pembulatan sehingga jumlahnya selalu = pokok + bunga.
CREATE TABLE loan_installments (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_id      BIGINT UNSIGNED NOT NULL,
    seq          TINYINT UNSIGNED NOT NULL,
    due_month_id INT UNSIGNED NOT NULL,
    amount_due   BIGINT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_installment_seq (loan_id, seq),
    KEY ix_installment_due (due_month_id),
    CONSTRAINT fk_installments_loan FOREIGN KEY (loan_id) REFERENCES loans (id) ON DELETE CASCADE,
    CONSTRAINT fk_installments_month FOREIGN KEY (due_month_id) REFERENCES period_months (id),
    CONSTRAINT ck_installments_amount CHECK (amount_due > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

-- Alokasi satu pembayaran (transaksi ANGSURAN) ke satu atau beberapa cicilan.
-- Menangani pembayaran sebagian, gabungan, dan dipercepat. Transaksi pembalik berisi alokasi NEGATIF.
CREATE TABLE installment_payments (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_id BIGINT UNSIGNED NOT NULL,
    installment_id BIGINT UNSIGNED NOT NULL,
    amount         BIGINT NOT NULL,
    PRIMARY KEY (id),
    KEY ix_alloc_trx (transaction_id),
    KEY ix_alloc_installment (installment_id),
    CONSTRAINT fk_alloc_trx FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE,
    CONSTRAINT fk_alloc_installment FOREIGN KEY (installment_id) REFERENCES loan_installments (id),
    CONSTRAINT ck_alloc_amount CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

-- Riwayat perpindahan status. Append-only (dijaga trigger).
CREATE TABLE transaction_validations (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_id BIGINT UNSIGNED NOT NULL,
    from_status    ENUM('DRAFT','MENUNGGU_VALIDASI','DISETUJUI','DITOLAK','DIBATALKAN') NOT NULL,
    to_status      ENUM('DRAFT','MENUNGGU_VALIDASI','DISETUJUI','DITOLAK','DIBATALKAN') NOT NULL,
    actor_user_id  INT UNSIGNED NULL COMMENT 'NULL hanya untuk impor historis',
    note           VARCHAR(255) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_validation_trx (transaction_id, created_at),
    KEY ix_validation_actor (actor_user_id),
    CONSTRAINT fk_validation_trx FOREIGN KEY (transaction_id) REFERENCES transactions (id),
    CONSTRAINT fk_validation_actor FOREIGN KEY (actor_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

-- Jejak audit. Append-only (dijaga trigger). Sengaja TANPA foreign key agar tidak pernah
-- menghalangi atau ikut terhapus bersama data lain.
CREATE TABLE audit_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NULL,
    username     VARCHAR(50) NULL,
    roles        VARCHAR(100) NULL,
    action       VARCHAR(50) NOT NULL,
    entity_type  VARCHAR(40) NOT NULL,
    entity_id    BIGINT UNSIGNED NULL,
    reference_no VARCHAR(30) NULL,
    before_data  LONGTEXT NULL,
    after_data   LONGTEXT NULL,
    ip_address   VARCHAR(45) NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_audit_entity (entity_type, entity_id),
    KEY ix_audit_reference (reference_no),
    KEY ix_audit_user (user_id, created_at),
    KEY ix_audit_created (created_at),
    CONSTRAINT ck_audit_before CHECK (before_data IS NULL OR JSON_VALID(before_data)),
    CONSTRAINT ck_audit_after CHECK (after_data IS NULL OR JSON_VALID(after_data))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
