-- 007: dukungan transaksi pembalik (Phase 10).
--
-- Masalah di skema lama: uq_trx_reverses melarang dua transaksi yang membalik transaksi yang sama, APA PUN
-- statusnya. Akibatnya pembalik yang DITOLAK atau DIBATALKAN mengunci transaksi asal selamanya (tidak bisa
-- diajukan ulang). Aturan yang benar: satu transaksi hanya boleh punya SATU pembalik yang masih hidup
-- (DRAFT, MENUNGGU_VALIDASI, atau DISETUJUI). Pembalik yang ditolak/dibatalkan boleh diganti yang baru.

ALTER TABLE transactions ADD KEY ix_trx_reverses (reverses_id);

-- @@

ALTER TABLE transactions DROP INDEX uq_trx_reverses;

-- @@

ALTER TABLE transactions
    ADD COLUMN open_reverses_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (IF(status IN ('DRAFT','MENUNGGU_VALIDASI','DISETUJUI'), reverses_id, NULL)) PERSISTENT
        COMMENT 'reverses_id hanya bila pembalik masih hidup; dipakai untuk melarang dua pembalik hidup'
        AFTER reverses_id;

-- @@

ALTER TABLE transactions ADD UNIQUE KEY uq_trx_open_reverses (open_reverses_id);

-- @@

-- Pemeriksa integritas: tambah dua pemeriksaan untuk pembalik. Hasil view harus tetap KOSONG.
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
FROM v_global_summary WHERE selisih <> 0

UNION ALL
-- Pembalik yang disetujui harus membalik transaksi DISETUJUI yang bukan pembalik, dengan jenis, anggota, dan nominal sama.
SELECT 'PEMBALIK_TIDAK_SESUAI' COLLATE utf8mb4_unicode_ci, r.id, r.trx_no,
       CONCAT('membalik ', COALESCE(o.trx_no, '(tidak ada)'), ': jenis/anggota/nominal berbeda, atau yang dibalik belum disetujui / sudah berupa pembalik') COLLATE utf8mb4_unicode_ci
FROM transactions r
LEFT JOIN transactions o ON o.id = r.reverses_id
WHERE r.reverses_id IS NOT NULL AND r.status = 'DISETUJUI' AND r.deleted_at IS NULL
  AND (o.id IS NULL OR o.status <> 'DISETUJUI' OR o.reverses_id IS NOT NULL OR o.type <> r.type
       OR NOT (o.member_id <=> r.member_id) OR o.amount <> r.amount)

UNION ALL
-- Pinjaman yang sudah dibalik tidak boleh punya pembayaran bersih (kas dan piutang tidak akan sinkron).
SELECT 'PINJAMAN_DIBALIK_MASIH_ADA_PEMBAYARAN' COLLATE utf8mb4_unicode_ci, ln.id, t.doc_no,
       CONCAT('terbayar bersih ', p.paid) COLLATE utf8mb4_unicode_ci
FROM loans ln
JOIN transactions t ON t.id = ln.transaction_id AND t.status = 'DISETUJUI' AND t.deleted_at IS NULL AND t.reverses_id IS NULL
JOIN (
    SELECT li.loan_id, SUM(ip.amount) AS paid
    FROM installment_payments ip
    JOIN loan_installments li ON li.id = ip.installment_id
    JOIN transactions pt ON pt.id = ip.transaction_id AND pt.status = 'DISETUJUI' AND pt.deleted_at IS NULL
    GROUP BY li.loan_id
) p ON p.loan_id = ln.id
WHERE EXISTS (SELECT 1 FROM transactions r WHERE r.reverses_id = t.id AND r.status = 'DISETUJUI')
  AND p.paid <> 0;
