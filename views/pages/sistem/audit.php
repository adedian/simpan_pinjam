<?php
use App\Models\Audit;

$qs = static fn (array $over): string => http_build_query(array_filter($over, static fn ($v): bool => $v !== '' && $v !== null));
?>
<section class="section">
    <div class="stats">
        <div class="stat"><span class="stat__label">Seluruh catatan</span><strong class="stat__value"><?= e(number_format($summary['total'], 0, ',', '.')) ?></strong><span class="stat__hint">Tidak bisa diubah atau dihapus</span></div>
        <div class="stat"><span class="stat__label">24 jam terakhir</span><strong class="stat__value"><?= e(number_format($summary['day'], 0, ',', '.')) ?></strong><span class="stat__hint"><a href="<?= e(url('/sistem/audit?' . $qs(['dari' => date('Y-m-d', strtotime('-1 day'))]))) ?>">Lihat</a></span></div>
        <div class="stat"><span class="stat__label">Gagal masuk (24 jam)</span><strong class="stat__value<?= $summary['failed'] > 0 ? ' stat__value--negative' : '' ?>"><?= (int) $summary['failed'] ?></strong><span class="stat__hint"><a href="<?= e(url('/sistem/audit?' . $qs(['kelompok' => 'keamanan', 'dari' => date('Y-m-d', strtotime('-1 day'))]))) ?>">Peristiwa keamanan</a></span></div>
        <div class="stat"><span class="stat__label">Akses ditolak (24 jam)</span><strong class="stat__value<?= $summary['denied'] > 0 ? ' stat__value--negative' : '' ?>"><?= (int) $summary['denied'] ?></strong><span class="stat__hint">Unduhan 7 hari: <?= (int) $summary['exports'] ?></span></div>
    </div>
</section>

<section class="card">
    <header class="report__head">
        <div>
            <h2 class="card__title">Audit Log <span class="muted">· <?= (int) $pager['total'] ?> catatan</span></h2>
            <p class="muted">Siapa melakukan apa, kapan, dari mana. Catatan ditulis dalam transaksi yang sama dengan perubahan datanya, dan tidak bisa diubah atau dihapus siapa pun (dijaga trigger database).</p>
        </div>
        <?php if ($canExport): ?>
        <div class="actions"><a class="btn btn--primary" href="<?= e(url('/sistem/audit/unduh' . ($query !== [] ? '?' . $qs($query) : ''))) ?>">Unduh CSV</a></div>
        <?php endif; ?>
    </header>

    <form class="toolbar" method="get" action="<?= e(url('/sistem/audit')) ?>">
        <div><label class="sr-only" for="a_dari">Dari tanggal</label><input id="a_dari" class="input" type="date" name="dari" value="<?= e($filters['dari']) ?>" title="Dari tanggal"></div>
        <div><label class="sr-only" for="a_sampai">Sampai tanggal</label><input id="a_sampai" class="input" type="date" name="sampai" value="<?= e($filters['sampai']) ?>" title="Sampai tanggal"></div>
        <div>
            <label class="sr-only" for="a_kelompok">Kelompok</label>
            <select id="a_kelompok" class="input" name="kelompok" title="Kelompok">
                <option value="">Semua kelompok</option>
                <?php foreach ($groups as $code => $label): ?><option value="<?= e($code) ?>"<?= $filters['kelompok'] === $code ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="sr-only" for="a_aksi">Aksi</label>
            <select id="a_aksi" class="input" name="aksi" title="Aksi">
                <option value="">Semua aksi</option>
                <?php foreach ($actions as $code => $label): ?><option value="<?= e($code) ?>"<?= $filters['aksi'] === $code ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="sr-only" for="a_entitas">Objek</label>
            <select id="a_entitas" class="input" name="entitas" title="Objek">
                <option value="">Semua objek</option>
                <?php foreach ($entities as $code => $label): ?><option value="<?= e($code) ?>"<?= $filters['entitas'] === $code ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar__search"><label class="sr-only" for="a_pengguna">Pengguna</label><input id="a_pengguna" class="input" type="search" name="pengguna" value="<?= e($filters['pengguna']) ?>" placeholder="Pengguna" maxlength="50"></div>
        <div class="toolbar__search"><label class="sr-only" for="a_q">Cari nomor dokumen atau pengguna</label><input id="a_q" class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nomor dokumen / referensi" maxlength="60"></div>
        <div><label class="sr-only" for="a_ip">Alamat IP</label><input id="a_ip" class="input" type="search" name="ip" value="<?= e($filters['ip']) ?>" placeholder="Alamat IP" maxlength="45"></div>
        <button type="submit" class="btn btn--secondary">Tampilkan</button>
        <?php if ($query !== []): ?><a class="btn btn--link" href="<?= e(url('/sistem/audit')) ?>">Atur ulang</a><?php endif; ?>
    </form>

    <?php if ($rows === []): ?>
        <p class="empty">Tidak ada catatan untuk saringan ini.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Objek</th><th>Referensi</th><th>IP</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $link = match ($r['entity_type']) {
                    'transaction' => $r['entity_id'] !== null ? '/transaksi/' . (int) $r['entity_id'] : null,
                    'member'      => $r['entity_id'] !== null ? '/anggota/' . (int) $r['entity_id'] : null,
                    default       => null,
                };
                $group = Audit::ACTIONS[$r['action']][1] ?? '';
            ?>
                <tr>
                    <td data-label="Waktu"><?= e(date('d-m-Y H:i:s', strtotime((string) $r['created_at']))) ?></td>
                    <td data-label="Pengguna"><?= $r['username'] !== null ? e($r['username']) : '<span class="muted">tamu/sistem</span>' ?><?= !empty($r['roles']) ? '<br><span class="muted">' . e($r['roles']) . '</span>' : '' ?></td>
                    <td data-label="Aksi"><?= $group === 'keamanan' ? '<span class="badge badge--ditolak">' . e(Audit::label((string) $r['action'])) . '</span>' : e(Audit::label((string) $r['action'])) ?><br><span class="muted"><?= e($r['action']) ?></span></td>
                    <td data-label="Objek"><?= e(Audit::ENTITIES[$r['entity_type']] ?? $r['entity_type']) ?><?= $r['entity_id'] !== null ? ' #' . (int) $r['entity_id'] : '' ?></td>
                    <td data-label="Referensi"><?= $r['reference_no'] === null ? '<span class="muted">-</span>' : ($link !== null ? '<a href="' . e(url($link)) . '">' . e($r['reference_no']) . '</a>' : e($r['reference_no'])) ?></td>
                    <td data-label="IP"><?= e((string) ($r['ip_address'] ?? '-')) ?></td>
                    <td class="num"><a class="btn btn--secondary btn--sm" href="<?= e(url('/sistem/audit/' . (int) $r['id'])) ?>">Rincian</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?= \App\Core\View::include('partials/pagination', ['pager' => $pager, 'query' => $query, 'basePath' => '/sistem/audit']) ?>
</section>
