-- 003: penjaga integritas di level database.
-- Tujuan: transaksi yang sudah diajukan TIDAK BISA diubah/dihapus, status hanya boleh bergerak
-- lewat jalur yang sah, dan jejak audit tidak bisa dimodifikasi, siapa pun yang menjalankan SQL-nya
-- (aplikasi, skrip, atau salah klik di phpMyAdmin).
-- Catatan: penghapusan berantai (ON DELETE CASCADE) tidak mengaktifkan trigger; aman karena hanya
-- terjadi saat transaksi DRAFT dihapus.

-- ===================== transactions =====================

CREATE TRIGGER trg_transactions_bu BEFORE UPDATE ON transactions FOR EACH ROW
BEGIN
    IF OLD.status <> 'DRAFT' THEN
        IF NOT (NEW.trx_no <=> OLD.trx_no AND NEW.doc_no <=> OLD.doc_no AND NEW.type <=> OLD.type
                AND NEW.member_id <=> OLD.member_id AND NEW.team_id <=> OLD.team_id
                AND NEW.period_month_id <=> OLD.period_month_id AND NEW.trx_date <=> OLD.trx_date
                AND NEW.amount <=> OLD.amount AND NEW.reverses_id <=> OLD.reverses_id
                AND NEW.description <=> OLD.description AND NEW.source <=> OLD.source
                AND NEW.import_ref <=> OLD.import_ref AND NEW.created_by <=> OLD.created_by
                AND NEW.deleted_at <=> OLD.deleted_at) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transaksi yang sudah diajukan tidak boleh diubah';
        END IF;
    END IF;

    IF NEW.status <> OLD.status THEN
        IF NOT ((OLD.status = 'DRAFT' AND NEW.status IN ('MENUNGGU_VALIDASI','DIBATALKAN'))
             OR (OLD.status = 'MENUNGGU_VALIDASI' AND NEW.status IN ('DISETUJUI','DITOLAK','DIBATALKAN'))) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Perubahan status transaksi tidak diizinkan';
        END IF;
    END IF;
END

-- @@

CREATE TRIGGER trg_transactions_bd BEFORE DELETE ON transactions FOR EACH ROW
BEGIN
    IF OLD.status <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transaksi keuangan yang sudah diajukan tidak boleh dihapus';
    END IF;
END

-- @@

-- ===================== tabel rincian: hanya boleh berubah saat transaksi induk DRAFT =====================

CREATE TRIGGER trg_savings_bi BEFORE INSERT ON savings FOR EACH ROW
BEGIN
    IF (SELECT status FROM transactions WHERE id = NEW.transaction_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rincian transaksi hanya boleh diubah saat DRAFT';
    END IF;
END

-- @@

CREATE TRIGGER trg_savings_bu BEFORE UPDATE ON savings FOR EACH ROW
BEGIN
    IF (SELECT status FROM transactions WHERE id = OLD.transaction_id) <> 'DRAFT'
       OR (SELECT status FROM transactions WHERE id = NEW.transaction_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rincian transaksi hanya boleh diubah saat DRAFT';
    END IF;
END

-- @@

CREATE TRIGGER trg_savings_bd BEFORE DELETE ON savings FOR EACH ROW
BEGIN
    IF (SELECT status FROM transactions WHERE id = OLD.transaction_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rincian transaksi hanya boleh diubah saat DRAFT';
    END IF;
END

-- @@

CREATE TRIGGER trg_expenses_bi BEFORE INSERT ON expenses FOR EACH ROW
BEGIN
    IF (SELECT status FROM transactions WHERE id = NEW.transaction_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rincian transaksi hanya boleh diubah saat DRAFT';
    END IF;
END

-- @@

CREATE TRIGGER trg_expenses_bu BEFORE UPDATE ON expenses FOR EACH ROW
BEGIN
    IF (SELECT status FROM transactions WHERE id = OLD.transaction_id) <> 'DRAFT'
       OR (SELECT status FROM transactions WHERE id = NEW.transaction_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rincian transaksi hanya boleh diubah saat DRAFT';
    END IF;
END

-- @@

CREATE TRIGGER trg_expenses_bd BEFORE DELETE ON expenses FOR EACH ROW
BEGIN
    IF (SELECT status FROM transactions WHERE id = OLD.transaction_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rincian transaksi hanya boleh diubah saat DRAFT';
    END IF;
END

-- @@

CREATE TRIGGER trg_loans_bi BEFORE INSERT ON loans FOR EACH ROW
BEGIN
    IF (SELECT status FROM transactions WHERE id = NEW.transaction_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rincian transaksi hanya boleh diubah saat DRAFT';
    END IF;
END

-- @@

CREATE TRIGGER trg_loans_bu BEFORE UPDATE ON loans FOR EACH ROW
BEGIN
    IF (SELECT status FROM transactions WHERE id = OLD.transaction_id) <> 'DRAFT'
       OR (SELECT status FROM transactions WHERE id = NEW.transaction_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rincian transaksi hanya boleh diubah saat DRAFT';
    END IF;
END

-- @@

CREATE TRIGGER trg_loans_bd BEFORE DELETE ON loans FOR EACH ROW
BEGIN
    IF (SELECT status FROM transactions WHERE id = OLD.transaction_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rincian transaksi hanya boleh diubah saat DRAFT';
    END IF;
END

-- @@

CREATE TRIGGER trg_installments_bi BEFORE INSERT ON loan_installments FOR EACH ROW
BEGIN
    IF (SELECT t.status FROM loans l JOIN transactions t ON t.id = l.transaction_id WHERE l.id = NEW.loan_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Jadwal cicilan hanya boleh diubah saat pinjaman DRAFT';
    END IF;
END

-- @@

CREATE TRIGGER trg_installments_bu BEFORE UPDATE ON loan_installments FOR EACH ROW
BEGIN
    IF (SELECT t.status FROM loans l JOIN transactions t ON t.id = l.transaction_id WHERE l.id = OLD.loan_id) <> 'DRAFT'
       OR (SELECT t.status FROM loans l JOIN transactions t ON t.id = l.transaction_id WHERE l.id = NEW.loan_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Jadwal cicilan hanya boleh diubah saat pinjaman DRAFT';
    END IF;
END

-- @@

CREATE TRIGGER trg_installments_bd BEFORE DELETE ON loan_installments FOR EACH ROW
BEGIN
    IF (SELECT t.status FROM loans l JOIN transactions t ON t.id = l.transaction_id WHERE l.id = OLD.loan_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Jadwal cicilan hanya boleh diubah saat pinjaman DRAFT';
    END IF;
END

-- @@

CREATE TRIGGER trg_alloc_bi BEFORE INSERT ON installment_payments FOR EACH ROW
BEGIN
    IF (SELECT status FROM transactions WHERE id = NEW.transaction_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Alokasi angsuran hanya boleh diubah saat DRAFT';
    END IF;
END

-- @@

CREATE TRIGGER trg_alloc_bu BEFORE UPDATE ON installment_payments FOR EACH ROW
BEGIN
    IF (SELECT status FROM transactions WHERE id = OLD.transaction_id) <> 'DRAFT'
       OR (SELECT status FROM transactions WHERE id = NEW.transaction_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Alokasi angsuran hanya boleh diubah saat DRAFT';
    END IF;
END

-- @@

CREATE TRIGGER trg_alloc_bd BEFORE DELETE ON installment_payments FOR EACH ROW
BEGIN
    IF (SELECT status FROM transactions WHERE id = OLD.transaction_id) <> 'DRAFT' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Alokasi angsuran hanya boleh diubah saat DRAFT';
    END IF;
END

-- @@

-- ===================== append-only =====================

CREATE TRIGGER trg_validations_bu BEFORE UPDATE ON transaction_validations FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Riwayat validasi bersifat append-only';
END

-- @@

CREATE TRIGGER trg_validations_bd BEFORE DELETE ON transaction_validations FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Riwayat validasi bersifat append-only';
END

-- @@

CREATE TRIGGER trg_audit_bu BEFORE UPDATE ON audit_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log bersifat append-only';
END

-- @@

CREATE TRIGGER trg_audit_bd BEFORE DELETE ON audit_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log bersifat append-only';
END
