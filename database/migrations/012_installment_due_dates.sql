-- 012: tanggal jatuh tempo per cicilan.
--
-- Sebelumnya jadwal hanya menyimpan BULAN jatuh tempo (due_month_id, bulan siklus) dan jatuh tempo dianggap akhir bulan
-- itu. Mulai migrasi ini pinjaman BARU memperoleh tanggal jatuh tempo nyata: tanggal pinjaman DISETUJUI ditambah n bulan
-- (cicilan ke-n), tanggal sama tiap bulan, dan bila bulan tujuan lebih pendek memakai hari terakhir bulan itu.
-- Kolom diisi aplikasi satu kali, tepat saat pencairan disetujui (LoanService::stampDueDates).
--
-- Pinjaman lama (hasil impor Excel dan yang sudah disetujui sebelum migrasi ini) dibiarkan NULL: tanggal efektifnya
-- tetap akhir bulan jatuh tempo (LAST_DAY), jadi tunggakan, bagi hasil, dan laporan lama TIDAK berubah.
-- due_month_id tetap bulan siklus (dasar rekap bulanan dan perkiraan bagi hasil), tidak disentuh.

ALTER TABLE loan_installments
    ADD COLUMN due_date DATE NULL COMMENT 'Tanggal jatuh tempo nyata; NULL = akhir bulan due_month_id (data lama)' AFTER due_month_id,
    ADD KEY ix_installment_due_date (due_date);

-- @@

-- Jadwal tetap terkunci setelah pinjaman tidak lagi DRAFT. Satu-satunya pengecualian: mengisi due_date dari NULL menjadi
-- tanggal, HANYA saat transaksi pencairannya sudah DISETUJUI (langkah penetapan di persetujuan), dengan semua kolom lain
-- tidak berubah. Setelah terisi tidak bisa diubah lagi.
DROP TRIGGER IF EXISTS trg_installments_bu;

-- @@

CREATE TRIGGER trg_installments_bu BEFORE UPDATE ON loan_installments FOR EACH ROW
BEGIN
    IF NOT (OLD.due_date IS NULL AND NEW.due_date IS NOT NULL
            AND OLD.loan_id = NEW.loan_id AND OLD.seq = NEW.seq AND OLD.due_month_id = NEW.due_month_id AND OLD.amount_due = NEW.amount_due
            AND (SELECT t.status FROM loans l JOIN transactions t ON t.id = l.transaction_id WHERE l.id = OLD.loan_id) = 'DISETUJUI') THEN
        IF (SELECT t.status FROM loans l JOIN transactions t ON t.id = l.transaction_id WHERE l.id = OLD.loan_id) <> 'DRAFT'
           OR (SELECT t.status FROM loans l JOIN transactions t ON t.id = l.transaction_id WHERE l.id = NEW.loan_id) <> 'DRAFT' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Jadwal cicilan hanya boleh diubah saat pinjaman DRAFT';
        END IF;
    END IF;
END

-- @@

-- Status cicilan: due_date kini tanggal EFEKTIF (tanggal nyata, atau akhir bulan jatuh tempo untuk data lama).
-- Kolom lama tidak berubah urutan/arti; due_date ditambahkan di akhir.
CREATE OR REPLACE VIEW v_installment_status AS
SELECT
    li.id AS installment_id,
    li.loan_id,
    li.seq,
    li.due_month_id,
    pm.month_date AS due_month,
    li.amount_due,
    COALESCE(SUM(ip.amount), 0) AS paid_amount,
    li.amount_due - COALESCE(SUM(ip.amount), 0) AS remaining_amount,
    COALESCE(li.due_date, LAST_DAY(pm.month_date)) AS due_date
FROM loan_installments li
JOIN period_months pm ON pm.id = li.due_month_id
LEFT JOIN installment_payments ip
       ON ip.installment_id = li.id
      AND EXISTS (SELECT 1 FROM transactions pt
                  WHERE pt.id = ip.transaction_id AND pt.status = 'DISETUJUI' AND pt.deleted_at IS NULL)
GROUP BY li.id, li.loan_id, li.seq, li.due_month_id, pm.month_date, li.amount_due, li.due_date;

-- @@

-- Menunggak = ada sisa dan tanggal jatuh tempo SUDAH LEWAT (kurang dari hari ini). Jatuh tempo hari ini belum menunggak.
-- Untuk data lama: LAST_DAY(bulan) < hari ini  <=>  bulan < awal bulan berjalan, sama dengan definisi sebelumnya.
CREATE OR REPLACE VIEW v_overdue_installments AS
SELECT s.installment_id, s.loan_id, b.loan_no, b.member_id, s.seq, s.due_month, s.amount_due, s.paid_amount, s.remaining_amount, s.due_date
FROM v_installment_status s
JOIN v_loan_balances b ON b.loan_id = s.loan_id
WHERE s.remaining_amount > 0
  AND s.due_date < CURDATE();
