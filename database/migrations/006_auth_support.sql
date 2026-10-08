-- 006: dukungan autentikasi (Phase 4).

-- Sesi yang diterbitkan SEBELUM waktu ini dianggap tidak sah (dipakai saat kata sandi diganti,
-- supaya sesi lama di perangkat lain ikut mati).
ALTER TABLE users
    ADD COLUMN credentials_changed_at DATETIME NULL AFTER must_change_password;

-- @@

-- Catatan percobaan masuk, untuk pembatasan laju per IP dan per nama pengguna.
-- Tidak menyimpan kata sandi. Baris lama dibersihkan oleh aplikasi (> 30 hari).
CREATE TABLE login_attempts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username   VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    succeeded  TINYINT(1) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_attempts_ip (ip_address, created_at),
    KEY ix_attempts_user (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
