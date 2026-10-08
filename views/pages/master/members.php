<section class="card">
    <div class="card__head">
        <h2 class="card__title">Anggota <span class="muted">· <?= (int) $pager['total'] ?></span></h2>
        <?php if ($canManage): ?><a class="btn btn--primary" href="<?= e(url('/master/anggota/baru')) ?>">Tambah anggota</a><?php endif; ?>
    </div>

    <form class="toolbar" method="get" action="<?= e(url('/master/anggota')) ?>">
        <div class="toolbar__search">
            <label class="sr-only" for="q">Cari</label>
            <input id="q" class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari nama, nomor, atau blok" maxlength="60">
        </div>
        <?php if (count($teams) > 1): ?>
        <div>
            <label class="sr-only" for="team">Regu</label>
            <select id="team" class="input" name="team">
                <option value="">Semua regu</option>
                <?php foreach ($teams as $t): ?>
                    <option value="<?= (int) $t['id'] ?>"<?= (int) $filters['team'] === (int) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label class="sr-only" for="status">Status</label>
            <select id="status" class="input" name="status">
                <option value="">Semua status</option>
                <option value="AKTIF"<?= $filters['status'] === 'AKTIF' ? ' selected' : '' ?>>Aktif</option>
                <option value="NONAKTIF"<?= $filters['status'] === 'NONAKTIF' ? ' selected' : '' ?>>Nonaktif</option>
            </select>
        </div>
        <button type="submit" class="btn btn--secondary">Cari</button>
        <?php if ($filters['q'] !== '' || $filters['team'] || $filters['status'] !== ''): ?>
            <a class="btn btn--link" href="<?= e(url('/master/anggota')) ?>">Atur ulang</a>
        <?php endif; ?>
    </form>

    <?php if ($rows === []): ?>
        <p class="empty">Tidak ada anggota yang cocok.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--stack">
                <thead>
                    <tr><th>No.</th><th>Nama</th><th>Blok</th><th>Regu</th><th class="num">Saldo tabungan</th><th class="num">Sisa pinjaman</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $m): ?>
                    <tr>
                        <td data-label="No."><?= e($m['member_no']) ?></td>
                        <td data-label="Nama">
                            <a href="<?= e(url('/anggota/' . (int) $m['id'])) ?>"><?= e($m['name']) ?></a>
                            <?php if ($m['is_manager']): ?><span class="tag">Pengelola</span><?php endif; ?>
                        </td>
                        <td data-label="Blok"><?= e($m['address_block'] ?? '-') ?></td>
                        <td data-label="Regu"><?= e($m['team_name'] ?? '-') ?></td>
                        <td data-label="Saldo tabungan" class="num"><?= e(money((int) $m['savings_balance'])) ?></td>
                        <td data-label="Sisa pinjaman" class="num"><?= (int) $m['outstanding'] > 0 ? e(money((int) $m['outstanding'])) : '<span class="muted">-</span>' ?></td>
                        <td data-label="Status"><span class="badge badge--<?= $m['status'] === 'AKTIF' ? 'disetujui' : 'draft' ?>"><?= $m['status'] === 'AKTIF' ? 'Aktif' : 'Nonaktif' ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= \App\Core\View::include('partials/pagination', ['pager' => $pager, 'query' => ['q' => $filters['q'], 'team' => $filters['team'], 'status' => $filters['status']], 'basePath' => '/master/anggota']) ?>
    <?php endif; ?>
</section>
