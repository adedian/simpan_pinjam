-- 004: view saldo. SEMUA angka saldo di aplikasi berasal dari sini, dihitung dari transaksi
-- berstatus DISETUJUI. Tidak ada saldo yang disimpan.
--
-- Konvensi arah: transaksi biasa bertanda +1, transaksi pembalik (reverses_id terisi) bertanda -1.
-- Pengaruh ke kas:     SIMPANAN +, ANGSURAN +, PENARIKAN -, PENCAIRAN_PINJAMAN -, BIAYA -
-- Pengaruh ke tabungan: SIMPANAN +, PENARIKAN -

CREATE OR REPLACE VIEW v_ledger AS
SELECT
    t.id                AS transaction_id,
    t.trx_no,
    t.doc_no,
    t.type,
    t.member_id,
    t.team_id,
    t.period_month_id,
    t.trx_date,
    t.amount,
    IF(t.reverses_id IS NULL, 1, -1) AS sign,
    IF(t.reverses_id IS NULL, 1, -1) * CASE t.type
        WHEN 'SIMPANAN' THEN t.amount
        WHEN 'ANGSURAN' THEN t.amount
        ELSE -t.amount
    END AS cash_delta,
    IF(t.reverses_id IS NULL, 1, -1) * CASE t.type
        WHEN 'SIMPANAN' THEN t.amount
        WHEN 'PENARIKAN' THEN -t.amount
        ELSE 0
    END AS savings_delta
FROM transactions t
WHERE t.status = 'DISETUJUI' AND t.deleted_at IS NULL;

-- @@

CREATE OR REPLACE VIEW v_member_savings AS
SELECT m.id AS member_id, COALESCE(SUM(l.savings_delta), 0) AS savings_balance
FROM members m
LEFT JOIN v_ledger l ON l.member_id = m.id
GROUP BY m.id;

-- @@

-- Status tiap cicilan berdasarkan alokasi pembayaran yang DISETUJUI.
CREATE OR REPLACE VIEW v_installment_status AS
SELECT
    li.id AS installment_id,
    li.loan_id,
    li.seq,
    li.due_month_id,
    pm.month_date AS due_month,
    li.amount_due,
    COALESCE(SUM(ip.amount), 0) AS paid_amount,
    li.amount_due - COALESCE(SUM(ip.amount), 0) AS remaining_amount
FROM loan_installments li
JOIN period_months pm ON pm.id = li.due_month_id
LEFT JOIN installment_payments ip
       ON ip.installment_id = li.id
      AND EXISTS (SELECT 1 FROM transactions pt
                  WHERE pt.id = ip.transaction_id AND pt.status = 'DISETUJUI' AND pt.deleted_at IS NULL)
GROUP BY li.id, li.loan_id, li.seq, li.due_month_id, pm.month_date, li.amount_due;

-- @@

-- Pinjaman EFEKTIF: transaksi pencairannya DISETUJUI dan belum dibalik.
-- outstanding_principal memakai pro-rata (pokok x sisa / total) agar deterministik;
-- dasar bagi hasil peminjam (sisa pokok) memakai kolom ini.
CREATE OR REPLACE VIEW v_loan_balances AS
SELECT
    ln.id          AS loan_id,
    t.doc_no       AS loan_no,
    ln.transaction_id,
    ln.member_id,
    ln.principal,
    ln.total_interest,
    ln.principal + ln.total_interest AS total_due,
    COALESCE(p.paid, 0) AS paid,
    ln.principal + ln.total_interest - COALESCE(p.paid, 0) AS outstanding,
    ROUND(ln.principal * (ln.principal + ln.total_interest - COALESCE(p.paid, 0))
          / (ln.principal + ln.total_interest)) AS outstanding_principal,
    IF(ln.principal + ln.total_interest - COALESCE(p.paid, 0) <= 0, 'LUNAS', 'AKTIF') AS loan_status
FROM loans ln
JOIN transactions t
  ON t.id = ln.transaction_id AND t.status = 'DISETUJUI' AND t.deleted_at IS NULL AND t.reverses_id IS NULL
LEFT JOIN (
    SELECT li.loan_id, SUM(ip.amount) AS paid
    FROM installment_payments ip
    JOIN loan_installments li ON li.id = ip.installment_id
    JOIN transactions pt ON pt.id = ip.transaction_id AND pt.status = 'DISETUJUI' AND pt.deleted_at IS NULL
    GROUP BY li.loan_id
) p ON p.loan_id = ln.id
WHERE NOT EXISTS (SELECT 1 FROM transactions r WHERE r.reverses_id = t.id AND r.status = 'DISETUJUI');

-- @@

-- Cicilan jatuh tempo (bulan jatuh tempo sudah lewat) yang belum lunas, pada pinjaman efektif.
CREATE OR REPLACE VIEW v_overdue_installments AS
SELECT s.installment_id, s.loan_id, b.loan_no, b.member_id, s.seq, s.due_month, s.amount_due, s.paid_amount, s.remaining_amount
FROM v_installment_status s
JOIN v_loan_balances b ON b.loan_id = s.loan_id
WHERE s.remaining_amount > 0
  AND s.due_month < DATE_FORMAT(CURDATE(), '%Y-%m-01');

-- @@

-- Ringkasan global. `selisih` HARUS selalu 0:
--   kas_tersedia + piutang_beredar = saldo_tabungan + bunga_dibukukan - biaya
-- Selisih tidak nol berarti ada angsuran yang belum teralokasi penuh ke cicilan.
CREATE OR REPLACE VIEW v_global_summary AS
SELECT
    x.saldo_tabungan,
    x.kas_tersedia,
    x.piutang_beredar,
    x.bunga_dibukukan,
    x.biaya,
    x.kas_tersedia + x.piutang_beredar - (x.saldo_tabungan + x.bunga_dibukukan - x.biaya) AS selisih
FROM (
    SELECT
        (SELECT COALESCE(SUM(savings_delta), 0) FROM v_ledger) AS saldo_tabungan,
        (SELECT COALESCE(SUM(cash_delta), 0) FROM v_ledger) AS kas_tersedia,
        (SELECT COALESCE(SUM(outstanding), 0) FROM v_loan_balances) AS piutang_beredar,
        (SELECT COALESCE(SUM(total_interest), 0) FROM v_loan_balances) AS bunga_dibukukan,
        (SELECT COALESCE(SUM(sign * amount), 0) FROM v_ledger WHERE type = 'BIAYA') AS biaya
) x;

-- @@

-- Pemeriksa integritas. Hasil harus KOSONG. Dijalankan oleh database/tools/check_integrity.php.
CREATE OR REPLACE VIEW v_integrity_issues AS
SELECT 'ALOKASI_ANGSURAN_TIDAK_SAMA' COLLATE utf8mb4_unicode_ci AS issue, t.id AS entity_id, t.trx_no AS reference,
       CONCAT('nominal ', IF(t.reverses_id IS NULL, t.amount, -t.amount), ' vs alokasi ', COALESCE(SUM(ip.amount), 0)) COLLATE utf8mb4_unicode_ci AS detail
FROM transactions t
LEFT JOIN installment_payments ip ON ip.transaction_id = t.id
WHERE t.type = 'ANGSURAN' AND t.status = 'DISETUJUI' AND t.deleted_at IS NULL
GROUP BY t.id, t.trx_no, t.amount, t.reverses_id
HAVING COALESCE(SUM(ip.amount), 0) <> IF(t.reverses_id IS NULL, t.amount, -t.amount)

UNION ALL
SELECT 'CICILAN_KELEBIHAN_BAYAR' COLLATE utf8mb4_unicode_ci, installment_id, CONCAT('loan#', loan_id, ' cicilan ', seq) COLLATE utf8mb4_unicode_ci,
       CONCAT('terbayar ', paid_amount, ' > tagihan ', amount_due) COLLATE utf8mb4_unicode_ci
FROM v_installment_status WHERE remaining_amount < 0

UNION ALL
SELECT 'JADWAL_TIDAK_SAMA_TOTAL' COLLATE utf8mb4_unicode_ci, ln.id, t.doc_no,
       CONCAT('jadwal ', COALESCE(SUM(li.amount_due), 0), ' vs pokok+bunga ', ln.principal + ln.total_interest) COLLATE utf8mb4_unicode_ci
FROM loans ln
JOIN transactions t ON t.id = ln.transaction_id AND t.status = 'DISETUJUI' AND t.deleted_at IS NULL
LEFT JOIN loan_installments li ON li.loan_id = ln.id
GROUP BY ln.id, t.doc_no, ln.principal, ln.total_interest
HAVING COALESCE(SUM(li.amount_due), 0) <> ln.principal + ln.total_interest

UNION ALL
SELECT 'BUNGA_TIDAK_SESUAI_RUMUS' COLLATE utf8mb4_unicode_ci, ln.id, t.doc_no,
       CONCAT('bunga ', ln.total_interest, ' vs ', ROUND(ln.principal * ln.rate_pct_month * ln.tenor_months / 100)) COLLATE utf8mb4_unicode_ci
FROM loans ln
JOIN transactions t ON t.id = ln.transaction_id AND t.status = 'DISETUJUI' AND t.deleted_at IS NULL
WHERE ln.total_interest <> ROUND(ln.principal * ln.rate_pct_month * ln.tenor_months / 100)

UNION ALL
SELECT 'SALDO_TABUNGAN_NEGATIF' COLLATE utf8mb4_unicode_ci, member_id, CAST(member_id AS CHAR) COLLATE utf8mb4_unicode_ci, CONCAT('saldo ', savings_balance) COLLATE utf8mb4_unicode_ci
FROM v_member_savings WHERE savings_balance < 0

UNION ALL
SELECT 'KAS_NEGATIF' COLLATE utf8mb4_unicode_ci, 0, 'KAS' COLLATE utf8mb4_unicode_ci, CONCAT('kas ', kas_tersedia) COLLATE utf8mb4_unicode_ci
FROM v_global_summary WHERE kas_tersedia < 0

UNION ALL
SELECT 'SELISIH_GLOBAL_TIDAK_NOL' COLLATE utf8mb4_unicode_ci, 0, 'GLOBAL' COLLATE utf8mb4_unicode_ci, CONCAT('selisih ', selisih) COLLATE utf8mb4_unicode_ci
FROM v_global_summary WHERE selisih <> 0;
