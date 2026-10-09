<section class="card">
    <div class="card__head">
        <h2 class="card__title">Laporan per anggota <span class="muted">· <?= (int) $pager['total'] ?></span></h2>
        <div class="actions">
            <?php $keep = http_build_query(array_filter(['q' => $filters['q'], 'regu' => $filters['team'] > 0 ? (string) $filters['team'] : ''], static fn (string $v): bool => $v !== '')); ?>
            <a class="btn btn--secondary btn--sm" href="<?= e(url('/laporan/anggota/cetak/pinjaman' . ($keep !== '' ? '?' . $keep : ''))) ?>">Cetak semua formulir pinjaman</a>
            <a class="btn btn--secondary btn--sm" href="<?= e(url('/laporan/anggota/cetak/tabungan' . ($keep !== '' ? '?' . $keep : ''))) ?>">Cetak semua formulir tabungan</a>
            <?php if ($canExport): ?><a class="btn btn--primary btn--sm" href="<?= e(url('/laporan/anggota/unduh' . ($keep !== '' ? '?' . $keep : ''))) ?>">Unduh Excel semua formulir</a><?php endif; ?>
        </div>
    </div>
    <p class="muted">Pilih anggota untuk membuka kartu anggota: tabungan per bulan, pinjaman beserta jadwal cicilan, dan seluruh transaksinya. Siap dicetak.</p>

    <form class="toolbar" method="get" action="<?= e(url('/laporan/anggota')) ?>">
        <div class="toolbar__search">
            <label class="sr-only" for="q">Cari</label>
            <input id="q" class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari nama atau nomor anggota" maxlength="60">
        </div>
        <?php if ($teams !== []): ?>
        <div>
            <label class="sr-only" for="regu">Regu</label>
            <select id="regu" class="input" name="regu" title="Regu">
                <option value="0">Semua regu</option>
                <?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"<?= $filters['team'] === $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn--secondary">Cari</button>
        <?php if ($filters['q'] !== '' || $filters['team'] > 0): ?><a class="btn btn--link" href="<?= e(url('/laporan/anggota')) ?>">Atur ulang</a><?php endif; ?>
    </form>

    <?php if ($rows === []): ?>
        <p class="empty">Tidak ada anggota yang cocok.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>No. Anggota</th><th>Nama</th><th>Regu</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td data-label="No. Anggota"><?= e($r['member_no']) ?></td>
                    <td data-label="Nama"><?= e($r['name']) ?></td>
                    <td data-label="Regu"><?= e($r['team_name']) ?></td>
                    <td data-label="Status"><?= $r['status'] === 'AKTIF' ? '<span class="badge badge--disetujui">Aktif</span>' : '<span class="badge badge--draft">Nonaktif</span>' ?></td>
                    <td class="cell-actions"><a class="btn btn--secondary btn--sm" href="<?= e(url('/laporan/anggota/' . (int) $r['id'])) ?>">Buka kartu</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?= \App\Core\View::include('partials/pagination', ['pager' => $pager, 'query' => ['q' => $filters['q'], 'regu' => $filters['team']], 'basePath' => '/laporan/anggota']) ?>
</section>
