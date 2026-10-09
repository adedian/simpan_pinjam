-- 008: penyesuaian rencana pembayaran untuk perkiraan bagi hasil (formulir cetak).
--
-- Perkiraan bagi hasil mengikuti Excel koperasi: tiap pinjaman dianggap dibayar sesuai jadwal (pokok/tenor + bunga/tenor
-- mulai bulan setelah pencairan). Bila bendahara menggeser atau mengubah rencana satu pinjaman (mis. cicilan ditunda ke
-- bulan lain), selisihnya dicatat di sini: `amount` ditambahkan ke rencana bulan itu (negatif = dikurangi / dipindah).
-- Tabel ini TIDAK mengubah saldo, kas, atau transaksi apa pun; hanya dibaca ProfitShare. Isinya ditulis lewat
-- database/tools/plan_adjust.php (akun admin), bukan lewat aplikasi web.

CREATE TABLE loan_plan_adjustments (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    loan_id         BIGINT UNSIGNED NOT NULL,
    period_month_id INT UNSIGNED NOT NULL,
    amount          BIGINT NOT NULL COMMENT 'Selisih terhadap jadwal cicilan pada bulan ini (rupiah, bertanda)',
    note            VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plan_adjust (loan_id, period_month_id),
    CONSTRAINT fk_plan_adjust_loan FOREIGN KEY (loan_id) REFERENCES loans (id),
    CONSTRAINT fk_plan_adjust_month FOREIGN KEY (period_month_id) REFERENCES period_months (id),
    CONSTRAINT ck_plan_adjust_amount CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
