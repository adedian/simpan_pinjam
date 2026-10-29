-- 014: ketua regu boleh kosong saat regu dibuat.
--
-- Sebelumnya regu baru wajib punya ketua (seorang anggota), padahal anggota wajib punya regu: urutan input buntu.
-- Kini regu bisa dibuat dulu tanpa ketua; anggota ditambahkan lalu dimasukkan ke regunya (Ubah anggota), dan ketuanya
-- dipilih belakangan (Ubah regu). Indeks unik leader_member_id tetap berlaku: NULL tidak dianggap bentrok.

ALTER TABLE team_leaders MODIFY leader_member_id INT UNSIGNED NULL;
