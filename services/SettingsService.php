<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Helpers\Money;

/**
 * Pengaturan koperasi. Definisi (label, jenis, batas, penjelasan) ada di sini sebagai satu-satunya
 * sumber; nilainya disimpan di tabel settings. Nilai selalu divalidasi sebelum disimpan, dan
 * kelompok persentase harus berjumlah tepat 100.
 */
final class SettingsService
{
    /** @var array<string,array{group:string,label:string,type:string,min?:int|float,max?:int|float,unit?:string,help?:string,money?:bool}> */
    public const DEFINITIONS = [
        'interest_rate_pct_month' => ['group' => 'Pinjaman', 'label' => 'Bunga per bulan', 'type' => 'decimal', 'min' => 0.01, 'max' => 10, 'unit' => '% per bulan',
            'help' => 'Berlaku untuk pinjaman baru. Pinjaman yang sudah ada tetap memakai tarif saat dicairkan.'],
        'loan_tenor_min' => ['group' => 'Pinjaman', 'label' => 'Tenor minimum', 'type' => 'int', 'min' => 1, 'max' => 12, 'unit' => 'bulan'],
        'loan_tenor_max' => ['group' => 'Pinjaman', 'label' => 'Tenor maksimum', 'type' => 'int', 'min' => 1, 'max' => 12, 'unit' => 'bulan',
            'help' => 'Tidak boleh melewati akhir periode program.'],
        'loan_max_amount' => ['group' => 'Pinjaman', 'label' => 'Plafon pinjaman', 'type' => 'int', 'min' => 0, 'max' => 1000000000, 'money' => true,
            'help' => 'Maksimum per pencairan. 0 = tanpa batas. Nilai awal diambil dari pinjaman terbesar di Excel.'],
        'loan_max_active_per_member' => ['group' => 'Pinjaman', 'label' => 'Batas pinjaman aktif per anggota', 'type' => 'int', 'min' => 0, 'max' => 50,
            'help' => '0 = tidak dibatasi.'],
        'allow_negative_cash' => ['group' => 'Kas', 'label' => 'Izinkan pencairan melebihi kas tersedia', 'type' => 'bool',
            'help' => 'Disarankan mati: pinjaman hanya boleh dicairkan sebesar kas yang ada.'],
        'saving_pokok_amount' => ['group' => 'Simpanan', 'label' => 'Simpanan pokok', 'type' => 'int', 'min' => 0, 'max' => 100000000, 'money' => true,
            'help' => 'BELUM FINAL: Excel menghitung Rp 50.000, pengumuman menyebut Rp 25.000. Pastikan sebelum dipakai.'],
        'withdrawals_enabled' => ['group' => 'Simpanan', 'label' => 'Izinkan penarikan tabungan', 'type' => 'bool',
            'help' => 'Default mati: tabungan baru dicairkan pada pembagian akhir.'],
        'profit_share_saver_pct' => ['group' => 'Bagi hasil bunga', 'label' => 'Untuk penabung', 'type' => 'int', 'min' => 0, 'max' => 100, 'unit' => '%'],
        'profit_share_borrower_pct' => ['group' => 'Bagi hasil bunga', 'label' => 'Untuk peminjam', 'type' => 'int', 'min' => 0, 'max' => 100, 'unit' => '%'],
        'profit_share_shu_pct' => ['group' => 'Bagi hasil bunga', 'label' => 'Untuk Kas SHU', 'type' => 'int', 'min' => 0, 'max' => 100, 'unit' => '%',
            'help' => 'Ketiga bagian di atas harus berjumlah tepat 100%.'],
        'reserve_pct' => ['group' => 'Bagi hasil bunga', 'label' => 'Potongan cadangan', 'type' => 'decimal', 'min' => 0, 'max' => 50, 'unit' => '%',
            'help' => 'Dipotong dari bagi hasil penabung dan peminjam. Pengecualian diatur per anggota.'],
        'shu_member_pct' => ['group' => 'Pembagian SHU', 'label' => 'Untuk anggota', 'type' => 'int', 'min' => 0, 'max' => 100, 'unit' => '%'],
        'shu_manager_pct' => ['group' => 'Pembagian SHU', 'label' => 'Untuk pengelola', 'type' => 'int', 'min' => 0, 'max' => 100, 'unit' => '%',
            'help' => 'Kedua bagian harus berjumlah tepat 100%.'],
    ];

    /** @return array<string,string> kunci => nilai tersimpan */
    public static function values(): array
    {
        $rows = Database::select('SELECT setting_key, setting_value FROM settings');
        return array_column($rows, 'setting_value', 'setting_key');
    }

    /** Nilai bertipe (int|float|bool|string) untuk dipakai fitur lain. */
    public static function get(string $key): int|float|bool|string
    {
        $def = self::DEFINITIONS[$key] ?? throw new \InvalidArgumentException("Pengaturan tidak dikenal: {$key}");
        $rows = Database::select('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
        if ($rows === []) {
            throw new \RuntimeException("Pengaturan {$key} belum ada di database.");
        }
        $raw = (string) $rows[0]['setting_value'];
        return match ($def['type']) {
            'int'     => (int) $raw,
            'decimal' => (float) $raw,
            'bool'    => $raw === '1',
            default   => $raw,
        };
    }

    /**
     * Validasi isian formulir. Kunci yang tidak ada di DEFINITIONS diabaikan.
     * @param array<string,mixed> $input kunci => nilai mentah
     * @return array{0:array<string,string>,1:array<string,string>} [galat, nilai bersih siap simpan]
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $clean  = [];
        foreach (self::DEFINITIONS as $key => $def) {
            $raw = $def['type'] === 'bool' ? (empty($input[$key]) ? '0' : '1') : trim((string) ($input[$key] ?? ''));
            $label = $def['label'];

            switch ($def['type']) {
                case 'bool':
                    $clean[$key] = $raw;
                    break;
                case 'int':
                    $n = !empty($def['money']) ? Money::parse($raw) : (preg_match('/^\d{1,9}$/', $raw) === 1 ? (int) $raw : null);
                    if ($n === null) {
                        $errors[$key] = "{$label}: isi dengan angka bulat" . (!empty($def['money']) ? ' (tanpa desimal).' : '.');
                    } elseif ($n < $def['min'] || $n > $def['max']) {
                        $errors[$key] = "{$label}: harus antara " . self::fmt($def['min'], $def) . ' dan ' . self::fmt($def['max'], $def) . '.';
                    } else {
                        $clean[$key] = (string) $n;
                    }
                    break;
                case 'decimal':
                    $norm = str_replace(',', '.', $raw);
                    if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $norm) !== 1) {
                        $errors[$key] = "{$label}: isi dengan angka, maksimal 2 desimal.";
                    } elseif ((float) $norm < $def['min'] || (float) $norm > $def['max']) {
                        $errors[$key] = "{$label}: harus antara {$def['min']} dan {$def['max']}.";
                    } else {
                        $clean[$key] = number_format((float) $norm, 2, '.', '');
                    }
                    break;
            }
        }

        // aturan lintas-field (hanya bila field terkait valid)
        $sum = static function (array $keys) use ($clean): ?int {
            $total = 0;
            foreach ($keys as $k) {
                if (!isset($clean[$k])) {
                    return null;
                }
                $total += (int) $clean[$k];
            }
            return $total;
        };
        if (($s = $sum(['profit_share_saver_pct', 'profit_share_borrower_pct', 'profit_share_shu_pct'])) !== null && $s !== 100) {
            $errors['profit_share_shu_pct'] = "Bagi hasil bunga harus berjumlah tepat 100% (sekarang {$s}%).";
        }
        if (($s = $sum(['shu_member_pct', 'shu_manager_pct'])) !== null && $s !== 100) {
            $errors['shu_manager_pct'] = "Pembagian SHU harus berjumlah tepat 100% (sekarang {$s}%).";
        }
        if (isset($clean['loan_tenor_min'], $clean['loan_tenor_max']) && (int) $clean['loan_tenor_min'] > (int) $clean['loan_tenor_max']) {
            $errors['loan_tenor_max'] = 'Tenor maksimum tidak boleh lebih kecil dari tenor minimum.';
        }
        return [$errors, $clean];
    }

    /**
     * Simpan hanya nilai yang berubah, masing-masing dengan catatan audit sebelum/sesudah.
     * @param array<string,string> $clean hasil validate()
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     * @return array<int,string> kunci yang berubah
     */
    public static function save(Request $request, array $actor, array $clean): array
    {
        return Database::transaction(static function (\PDO $pdo) use ($request, $actor, $clean): array {
            $current = $pdo->query('SELECT setting_key, setting_value FROM settings FOR UPDATE')->fetchAll(\PDO::FETCH_KEY_PAIR);
            $changed = [];
            foreach (self::DEFINITIONS as $key => $_) {
                if (!array_key_exists($key, $clean) || !array_key_exists($key, $current)) {
                    continue;
                }
                // bandingkan secara numerik agar "2.0" dan "2.00" tidak dianggap perubahan
                $same = is_numeric($current[$key]) && is_numeric($clean[$key]) ? (float) $current[$key] === (float) $clean[$key] : $current[$key] === $clean[$key];
                if ($same) {
                    continue;
                }
                $pdo->prepare('UPDATE settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?')->execute([$clean[$key], $actor['id'], $key]);
                AuditLog::record($request, $actor, 'SETTING_UPDATED', 'setting', null, $key, ['value' => $current[$key]], ['value' => $clean[$key]], $pdo);
                $changed[] = $key;
            }
            return $changed;
        });
    }

    /** @param array{money?:bool} $def */
    private static function fmt(int|float $n, array $def): string
    {
        return !empty($def['money']) ? Money::format((int) $n) : (string) $n;
    }
}
