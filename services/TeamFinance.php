<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Ringkasan keuangan satu regu: empat indikator dashboard Ketua Regu dan kolom terkait di Laporan Per Ketua Regu.
 * SATU tempat definisi, supaya dashboard dan laporan tidak pernah berbeda. HANYA MEMBACA; semua angka dari transaksi DISETUJUI
 * lewat view saldo (v_member_savings, v_loan_balances, v_ledger). Tidak ada angka tersimpan atau tertanam.
 *
 *   savings      Saldo Total   : jumlah saldo tabungan anggota yang KINI berada di regu ini (sama dengan "Tabungan regu" lama)
 *   outstanding  Sisa Pinjaman : jumlah sisa tagihan (pokok + bunga) pinjaman berlaku milik anggota regu ini
 *   cash         Sisa Kas      : ARUS KAS REGU = setoran + angsuran - pencairan - penarikan dari transaksi yang tercatat atas regu
 *                                ini (v_ledger.team_id). Angka turunan, BUKAN uang fisik di tangan ketua regu: kas koperasi satu
 *                                dan dipinjamkan lintas regu, jadi bisa negatif. Biaya (belum dibebankan ke regu) tidak termasuk.
 *   interest     Total Bunga   : bunga dibukukan di muka dari pinjaman berlaku anggota regu ini (definisi sama dengan
 *                                "Bunga dibukukan" global). interest_received = bagian yang sudah ikut terbayar (pro rata).
 *
 * Pemanggil WAJIB memastikan pengguna boleh melihat regu ini (Ketua Regu: regunya sendiri; Superadmin: semua).
 */
final class TeamFinance
{
    /**
     * @return array{members:int,savings:int,outstanding:int,cash:int,interest:int,interest_received:int}
     */
    public static function summary(int $teamId): array
    {
        $r = Database::select(
            "SELECT COUNT(m.id) AS members,
                    COALESCE(SUM(s.savings_balance), 0) AS savings,
                    COALESCE(SUM(lb.outstanding), 0) AS outstanding,
                    COALESCE(SUM(lb.interest), 0) AS interest,
                    COALESCE(SUM(lb.interest_received), 0) AS interest_received
             FROM members m
             JOIN member_team_assignments a ON a.member_id = m.id AND a.valid_to IS NULL AND a.team_id = ?
             LEFT JOIN v_member_savings s ON s.member_id = m.id
             LEFT JOIN (SELECT member_id, SUM(outstanding) AS outstanding, SUM(total_interest) AS interest,
                               SUM(ROUND(total_interest * paid / total_due)) AS interest_received
                        FROM v_loan_balances GROUP BY member_id) lb ON lb.member_id = m.id
             WHERE m.deleted_at IS NULL",
            [$teamId]
        )[0];
        $cash = (int) Database::select('SELECT COALESCE(SUM(cash_delta), 0) AS v FROM v_ledger WHERE team_id = ?', [$teamId])[0]['v'];
        return [
            'members' => (int) $r['members'], 'savings' => (int) $r['savings'], 'outstanding' => (int) $r['outstanding'],
            'cash' => $cash, 'interest' => (int) $r['interest'], 'interest_received' => (int) $r['interest_received'],
        ];
    }

    /**
     * Semua regu aktif sekaligus (untuk laporan), kunci = id regu.
     * @return array<int,array{members:int,savings:int,outstanding:int,cash:int,interest:int,interest_received:int}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (Database::select('SELECT id FROM team_leaders WHERE is_active = 1') as $t) {
            $out[(int) $t['id']] = self::summary((int) $t['id']);
        }
        return $out;
    }
}
