-- 001: tabel inti (pengguna, anggota, regu, periode, pengaturan).
-- Konvensi file migrasi: pernyataan dipisahkan baris "-- @@". Semua uang BIGINT rupiah (tanpa desimal).

CREATE TABLE roles (
    id   TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(60) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

-- Orang yang tercatat sebagai anggota koperasi. Regu TIDAK disimpan di sini (lihat member_team_assignments).
CREATE TABLE members (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_no      VARCHAR(12) NOT NULL COMMENT 'Nomor tampil, mis. AGT-001',
    name           VARCHAR(100) NOT NULL,
    address_block  VARCHAR(40) NULL COMMENT 'Blok / alamat, mis. E - 08A',
    active_from    DATE NOT NULL COMMENT 'Bulan mulai aktif (tanggal 1)',
    status         ENUM('AKTIF','NONAKTIF') NOT NULL DEFAULT 'AKTIF',
    is_manager     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Pengelola penerima 40% SHU',
    reserve_exempt TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Dikecualikan dari potongan cadangan 5%',
    notes          VARCHAR(255) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at     DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_members_no (member_no),
    KEY ix_members_name (name),
    CONSTRAINT ck_members_active_from CHECK (DAY(active_from) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

CREATE TABLE users (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username             VARCHAR(50) NOT NULL,
    name                 VARCHAR(100) NOT NULL,
    email                VARCHAR(150) NULL,
    password_hash        VARCHAR(255) NOT NULL COMMENT 'password_hash(); tidak pernah plaintext',
    member_id            INT UNSIGNED NULL COMMENT 'Pengguna yang juga anggota (mis. Purwati)',
    is_active            TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    failed_logins        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until         DATETIME NULL,
    last_login_at        DATETIME NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at           DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_member (member_id),
    CONSTRAINT fk_users_member FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

CREATE TABLE user_roles (
    user_id INT UNSIGNED NOT NULL,
    role_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

-- Regu. Ketua regu adalah seorang anggota.
CREATE TABLE team_leaders (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name             VARCHAR(100) NOT NULL,
    leader_member_id INT UNSIGNED NOT NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_team_leader_member (leader_member_id),
    CONSTRAINT fk_team_leaders_member FOREIGN KEY (leader_member_id) REFERENCES members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

-- Riwayat keanggotaan regu. Satu anggota hanya boleh punya SATU baris aktif (valid_to IS NULL):
-- dijamin oleh kolom virtual + indeks unik (NULL tidak dianggap bentrok).
CREATE TABLE member_team_assignments (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id         INT UNSIGNED NOT NULL,
    team_id           INT UNSIGNED NOT NULL,
    valid_from        DATE NOT NULL,
    valid_to          DATE NULL,
    active_member_key INT UNSIGNED AS (IF(valid_to IS NULL, member_id, NULL)) VIRTUAL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_one_active_team_per_member (active_member_key),
    KEY ix_assign_team (team_id, valid_to),
    KEY ix_assign_member (member_id, valid_from),
    CONSTRAINT fk_assign_member FOREIGN KEY (member_id) REFERENCES members (id),
    CONSTRAINT fk_assign_team FOREIGN KEY (team_id) REFERENCES team_leaders (id),
    CONSTRAINT ck_assign_range CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

CREATE TABLE periods (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date   DATE NOT NULL,
    status     ENUM('AKTIF','TUTUP') NOT NULL DEFAULT 'AKTIF',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT ck_periods_range CHECK (end_date > start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

-- Siklus bulanan (12 per periode) beserta pertemuan.
CREATE TABLE period_months (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_id    INT UNSIGNED NOT NULL,
    month_date   DATE NOT NULL COMMENT 'Tanggal 1 bulan siklus',
    meeting_date DATE NULL,
    location     VARCHAR(120) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_period_month (period_id, month_date),
    CONSTRAINT fk_period_months_period FOREIGN KEY (period_id) REFERENCES periods (id),
    CONSTRAINT ck_period_month_day CHECK (DAY(month_date) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

CREATE TABLE settings (
    setting_key   VARCHAR(80) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    value_type    ENUM('INT','DECIMAL','BOOL','STRING') NOT NULL,
    description   VARCHAR(255) NULL,
    updated_by    INT UNSIGNED NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@

-- Penomoran tanpa celah: kenaikan dilakukan di dalam transaksi pemanggil (lihat NumberSequence).
CREATE TABLE number_sequences (
    seq_key    VARCHAR(30) NOT NULL COMMENT 'mis. TRX-2026',
    last_value INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (seq_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
