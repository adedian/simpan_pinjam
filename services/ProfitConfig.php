<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;

/**
 * Konfigurasi komponen pembagian bunga (migrasi 013). Daftar komponen berlaku mulai bulan tertentu, hanya-tambah.
 *
 *   PENABUNG  dibagi ke anggota menurut saldo tabungan akhir bulan           (rumus tetap di ProfitShare)
 *   PEMINJAM  dibagi ke anggota menurut dasar pinjaman                       (rumus tetap di ProfitShare)
 *   SHU       Kas SHU: sumber laporan Jasa Pinjaman dan Kas SHU serta biaya  (tepat satu; menyerap sisa pembulatan)
 *   DANA      dana lain (Cadangan, Operasional, ...): hanya total, tidak dibagi per anggota
 *
 * Persentase dalam seperseratus persen (2000 = 20,00%), total per versi WAJIB 10000. Nama boleh diubah, identitas komponen
 * adalah `code` yang stabil antar versi. Bunga bulan M memakai versi dengan effective_from terbesar yang <= M, jadi mengubah
 * versi baru tidak pernah mengubah bulan-bulan lama.
 */
final class ProfitConfig
{
    public const TOTAL = 10000;
    public const MAX_DANA = 12;
    public const MAX_NAME = 60;
    private const FIXED = ['PENABUNG' => 'PENABUNG', 'PEMINJAM' => 'PEMINJAM', 'SHU' => 'SHU'];

    /** @var array<int,array<string,mixed>>|null versi terurut effective_from naik */
    private static ?array $cache = null;

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Semua versi, terurut effective_from NAIK.
     * @return array<int,array{id:int,effective_from:string,note:?string,created_at:string,created_by:string,items:array<int,array{code:string,kind:string,name:string,pct:int}>}>
     */
    public static function versions(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $items = [];
        foreach (Database::select('SELECT config_id, code, kind, name, pct_hundredths FROM profit_share_config_items ORDER BY config_id, position') as $r) {
            $items[(int) $r['config_id']][] = ['code' => (string) $r['code'], 'kind' => (string) $r['kind'], 'name' => (string) $r['name'], 'pct' => (int) $r['pct_hundredths']];
        }
        $out = [];
        foreach (Database::select('SELECT c.id, c.effective_from, c.note, c.created_at, u.name AS by_name FROM profit_share_configs c LEFT JOIN users u ON u.id = c.created_by ORDER BY c.effective_from') as $r) {
            $out[] = ['id' => (int) $r['id'], 'effective_from' => (string) $r['effective_from'], 'note' => $r['note'] === null ? null : (string) $r['note'],
                'created_at' => (string) $r['created_at'], 'created_by' => (string) ($r['by_name'] ?? 'Sistem'), 'items' => $items[(int) $r['id']] ?? []];
        }
        return self::$cache = $out;
    }

    /**
     * Versi yang berlaku pada bulan itu (tanggal apa pun dalam bulan).
     * @return array{id:int,effective_from:string,note:?string,created_at:string,created_by:string,items:array<int,array{code:string,kind:string,name:string,pct:int}>}
     */
    public static function forMonth(string $date): array
    {
        $month = substr($date, 0, 7) . '-01';
        $hit   = null;
        foreach (self::versions() as $v) {
            if ($v['effective_from'] <= $month) {
                $hit = $v;
            }
        }
        // bulan sebelum versi pertama: pakai versi pertama (tidak ada celah)
        return $hit ?? self::versions()[0] ?? throw new \RuntimeException('Konfigurasi pembagian bunga belum ada. Jalankan migrasi 013.');
    }

    /** Versi yang berlaku bulan ini. */
    public static function current(): array
    {
        return self::forMonth(date('Y-m-01'));
    }

    /**
     * Persentase tiap jenis sebagai pecahan (0.4 = 40%) plus daftar komponen DANA.
     * @param array{items:array<int,array{code:string,kind:string,name:string,pct:int}>} $config
     * @return array{saver:float,borrower:float,shu:float,dana:array<string,float>}
     */
    public static function fractions(array $config): array
    {
        $out = ['saver' => 0.0, 'borrower' => 0.0, 'shu' => 0.0, 'dana' => []];
        foreach ($config['items'] as $i) {
            $f = $i['pct'] / self::TOTAL;
            match ($i['kind']) {
                'PENABUNG' => $out['saver'] = $f,
                'PEMINJAM' => $out['borrower'] = $f,
                'SHU'      => $out['shu'] = $f,
                default    => $out['dana'][$i['code']] = $f,
            };
        }
        return $out;
    }

    /** "20,00%" -> tampil "20" atau "12,5". */
    public static function fmtPct(int $hundredths): string
    {
        return rtrim(rtrim(number_format($hundredths / 100, 2, ',', ''), '0'), ',') . '%';
    }

    /**
     * Bagikan $total rupiah ke komponen menurut persentase. Semua dibulatkan setengah ke atas, KECUALI komponen SHU yang
     * menyerap selisih pembulatan, sehingga jumlahnya SELALU tepat sama dengan $total.
     * @param array<int,array{code:string,kind:string,pct:int}> $items
     * @return array<string,int> code => rupiah
     */
    public static function allocate(array $items, int $total): array
    {
        $out  = [];
        $used = 0;
        $shu  = null;
        foreach ($items as $i) {
            if ($i['kind'] === 'SHU') {
                $shu = $i['code'];
                continue;
            }
            $out[$i['code']] = intdiv(2 * $total * $i['pct'] + self::TOTAL, 2 * self::TOTAL);
            $used += $out[$i['code']];
        }
        if ($shu !== null) {
            $out[$shu] = $total - $used;
        }
        return $out;
    }

    // ------------------------------------------------------------------ validasi dan simpan

    /**
     * Normalkan dan periksa isian formulir. Komponen tetap (Penabung, Peminjam, Kas SHU) selalu ada; jenisnya ditentukan
     * server dari kode, bukan dari isian.
     * @param array<int|string,mixed> $rows isian: [['code'=>, 'name'=>, 'pct'=>], ...] menurut urutan tampil
     * @return array{0:array<string,string>,1:array<int,array{code:string,kind:string,name:string,pct:int}>,2:int} [galat, komponen bersih, total]
     */
    public static function normalize(array $rows): array
    {
        $errors = [];
        $items  = [];
        $seen   = [];
        $names  = [];
        $dana   = 0;
        $total  = 0;
        $known  = [];
        foreach ((self::versions() === [] ? [] : self::versions()[count(self::versions()) - 1]['items']) as $i) {
            $known[$i['code']] = true;
        }
        $rows = array_slice(array_values($rows), 0, 40);
        foreach ($rows as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $pos   = $idx;   // kunci galat sama dengan nomor baris di formulir
            $label = 'Baris ' . ($idx + 1);
            $code  = strtoupper(trim((string) ($row['code'] ?? '')));
            $name  = clean_text($row['name'] ?? '');
            if (!isset(self::FIXED[$code]) && $name === '' && trim((string) ($row['pct'] ?? '')) === '') {
                continue;   // baris dana kosong (cadangan formulir tanpa JavaScript) diabaikan
            }
            if (isset(self::FIXED[$code])) {
                $kind = self::FIXED[$code];
            } else {
                $kind = 'DANA';
                $dana++;
                if (!preg_match('/^D[0-9]{1,6}$/', $code) || isset($seen[$code])) {
                    $code = '';   // kode baru dibuat di bawah
                }
            }
            if ($code !== '' && isset($seen[$code])) {
                $errors['_form'] = 'Komponen ganda terdeteksi. Muat ulang halaman.';
                continue;
            }
            if ($name === '') {
                $errors["name{$pos}"] = "{$label}: nama komponen wajib diisi.";
            } elseif (mb_strlen($name) > self::MAX_NAME) {
                $errors["name{$pos}"] = "{$label}: nama maksimal " . self::MAX_NAME . ' karakter.';
            } elseif (isset($names[mb_strtolower($name)])) {
                $errors["name{$pos}"] = "{$label}: nama \"{$name}\" sudah dipakai komponen lain.";
            }
            $names[mb_strtolower($name)] = true;

            $rawPct = str_replace([' ', '%'], '', trim((string) ($row['pct'] ?? '')));
            $rawPct = str_replace(',', '.', $rawPct);
            $pct    = 0;
            if ($rawPct === '' || preg_match('/^\d{1,3}(\.\d{1,2})?$/', $rawPct) !== 1) {
                $errors["pct{$pos}"] = "{$label}: persentase isi dengan angka, maksimal 2 desimal (mis. 20 atau 12,5).";
            } elseif ((float) $rawPct > 100) {
                $errors["pct{$pos}"] = "{$label}: persentase tidak boleh lebih dari 100%.";
            } else {
                $pct = (int) round((float) $rawPct * 100);
            }
            $total += $pct;
            if ($code !== '') {
                $seen[$code] = true;
            }
            $items[] = ['code' => $code, 'kind' => $kind, 'name' => $name, 'pct' => $pct];
        }
        // kode baru untuk komponen dana yang belum punya
        $next = 1;
        foreach ($items as &$it) {
            if ($it['code'] === '') {
                while (isset($seen['D' . $next]) || isset($known['D' . $next])) {
                    $next++;
                }
                $it['code'] = 'D' . $next;
                $seen[$it['code']] = true;
            }
        }
        unset($it);

        $kinds = array_count_values(array_column($items, 'kind'));
        foreach (['PENABUNG' => 'Penabung', 'PEMINJAM' => 'Peminjam', 'SHU' => 'Kas SHU'] as $k => $label) {
            if (($kinds[$k] ?? 0) !== 1) {
                $errors['_form'] = "Komponen {$label} wajib ada tepat satu. Komponen tetap tidak boleh dihapus.";
            }
        }
        if ($dana > self::MAX_DANA) {
            $errors['_form'] = 'Komponen dana maksimal ' . self::MAX_DANA . '.';
        }
        if ($errors === [] || !isset($errors['_form'])) {
            if ($total !== self::TOTAL && !array_filter(array_keys($errors), static fn (string $k): bool => str_starts_with($k, 'pct'))) {
                $diff = self::TOTAL - $total;
                $errors['total'] = $diff > 0
                    ? 'Total persentase ' . self::fmtPct($total) . ': masih tersisa ' . self::fmtPct($diff) . ' yang belum dialokasikan. Total harus tepat 100%.'
                    : 'Total persentase ' . self::fmtPct($total) . ': melebihi 100% sebanyak ' . self::fmtPct(-$diff) . '. Total harus tepat 100%.';
            }
        }
        return [$errors, $items, $total];
    }

    /**
     * Bulan paling awal yang boleh dipakai versi baru: setelah versi terakhir dan setelah bulan data snapshot yang masih berlaku
     * (snapshot = penetapan; angkanya tidak diubah oleh versi baru, dan bulan yang sudah ditetapkan tidak boleh digeser).
     */
    public static function minEffectiveFrom(): string
    {
        $v      = self::versions();
        $min    = $v === [] ? '2000-01-01' : date('Y-m-01', strtotime('+1 month', strtotime($v[count($v) - 1]['effective_from'])));
        $latest = Database::select(
            'SELECT MAX(s.as_of_month) AS m FROM profit_share_snapshots s LEFT JOIN profit_share_snapshot_voids x ON x.snapshot_id = s.id WHERE x.id IS NULL'
        )[0]['m'] ?? null;
        if ($latest !== null) {
            $after = date('Y-m-01', strtotime('+1 month', strtotime((string) $latest)));
            if ($after > $min) {
                $min = $after;
            }
        }
        return $min;
    }

    /**
     * Simpan versi baru. Wajib diperiksa (normalize) lebih dulu oleh pemanggil; di sini dicek ulang aturan tanggal berlaku.
     * @param array{id:int,username:string,roles:array<int,string>} $actor
     * @param array<int,array{code:string,kind:string,name:string,pct:int}> $items hasil normalize()
     * @return int id versi baru
     */
    public static function save(Request $request, array $actor, string $effectiveFrom, array $items, ?string $note, bool $confirmPast): int
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $effectiveFrom);
        if ($d === false || $d->format('Y-m-d') !== $effectiveFrom) {
            throw RuleViolation::field('effective_from', 'Bulan berlaku tidak valid.');
        }
        $effectiveFrom = $d->format('Y-m-01');
        $note = $note === null ? null : mb_substr(clean_text($note), 0, 255);

        return Database::transaction(static function (\PDO $pdo) use ($request, $actor, $effectiveFrom, $items, $note, $confirmPast): int {
            // kunci tabel induk: dua perubahan serentak berjalan bergantian
            $pdo->query('SELECT id FROM profit_share_configs FOR UPDATE')->fetchAll();
            self::flush();
            $min = self::minEffectiveFrom();
            if ($effectiveFrom < $min) {
                throw RuleViolation::field('effective_from', 'Bulan berlaku paling awal ' . month_label($min) . ': sesudah versi sebelumnya dan sesudah bulan data snapshot yang sudah ditetapkan. Bulan yang sudah ditetapkan tidak boleh diubah.');
            }
            if ($effectiveFrom < date('Y-m-01') && !$confirmPast) {
                throw RuleViolation::field('confirm_past', 'Bulan berlaku ' . month_label($effectiveFrom) . ' sudah lewat. Centang pernyataan bahwa Anda memahami angka perkiraan bulan-bulan itu akan berubah.');
            }
            if (array_sum(array_column($items, 'pct')) !== self::TOTAL) {
                throw RuleViolation::field('total', 'Total persentase harus tepat 100%.');
            }
            $versions = self::versions();
            $before   = $versions === [] ? null : $versions[count($versions) - 1];

            $pdo->prepare('INSERT INTO profit_share_configs (effective_from, note, created_by) VALUES (?,?,?)')->execute([$effectiveFrom, $note, $actor['id']]);
            $id  = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO profit_share_config_items (config_id, position, code, kind, name, pct_hundredths) VALUES (?,?,?,?,?,?)');
            foreach ($items as $pos => $it) {
                $ins->execute([$id, $pos + 1, $it['code'], $it['kind'], $it['name'], $it['pct']]);
            }
            $fmt = static fn (array $list): array => array_map(static fn (array $i): string => $i['name'] . ' ' . self::fmtPct($i['pct']), $list);
            AuditLog::record($request, $actor, 'PROFIT_CONFIG_CHANGED', 'profit_config', $id, month_label($effectiveFrom),
                $before === null ? null : ['berlaku_mulai' => $before['effective_from'], 'komponen' => $fmt($before['items'])],
                ['berlaku_mulai' => $effectiveFrom, 'komponen' => $fmt($items), 'catatan' => $note, 'bulan_lalu_dikonfirmasi' => $confirmPast], $pdo);
            self::flush();
            return $id;
        });
    }
}
