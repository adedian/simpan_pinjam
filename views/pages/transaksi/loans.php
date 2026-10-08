<section class="card">
    <div class="card__head">
        <h2 class="card__title">Pinjaman <span class="muted">· <?= (int) $pager['total'] ?></span></h2>
        <div class="actions">
            <a class="btn btn--secondary" href="<?= e(url('/transaksi/pinjaman/simulasi')) ?>">Simulasi</a>
            <?php if ($canCreate): ?><a class="btn btn--primary" href="<?= e(url('/transaksi/pinjaman/baru')) ?>">Catat pinjaman</a><?php endif; ?>
        </div>
    </div>

    <?= \App\Core\View::include('partials/transaction-summary', ['totals' => $totals]) ?>

    <form class="toolbar" method="get" action="<?= e(url('/transaksi/pinjaman')) ?>">
        <?php if ($filters['member'] > 0): ?><input type="hidden" name="anggota" value="<?= (int) $filters['member'] ?>"><?php endif; ?>
        <div class="toolbar__search">
            <label class="sr-only" for="q">Cari</label>
            <input id="q" class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari anggota atau nomor dokumen" maxlength="60">
        </div>
        <div>
            <label class="sr-only" for="bulan">Bulan</label>
            <select id="bulan" class="input" name="bulan">
                <option value="">Semua bulan</option>
                <?php foreach ($months as $mo): ?>
                    <option value="<?= (int) $mo['id'] ?>"<?= $filters['month'] === (int) $mo['id'] ? ' selected' : '' ?>><?= e(month_label((string) $mo['month_date'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="sr-only" for="status">Status</label>
            <select id="status" class="input" name="status">
                <option value="">Semua status</option>
                <?php foreach (\App\Models\Transaction::STATUSES as $st): ?>
                    <option value="<?= e($st) ?>"<?= $filters['status'] === $st ? ' selected' : '' ?>><?= e(ucwords(strtolower(str_replace('_', ' ', $st)))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn--secondary">Cari</button>
        <?php if ($filters['q'] !== '' || $filters['month'] || $filters['status'] !== '' || $filters['member']): ?>
            <a class="btn btn--link" href="<?= e(url('/transaksi/pinjaman')) ?>">Atur ulang</a>
        <?php endif; ?>
    </form>
    <?php if ($member !== null): ?>
        <p class="muted">Menampilkan pinjaman <strong><?= e($member['name']) ?></strong> (<?= e($member['member_no']) ?>).</p>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <p class="empty">Belum ada pinjaman yang cocok.</p>
    <?php else: ?>
        <?= \App\Core\View::include('partials/transaction-table', ['rows' => $rows, 'showType' => false, 'mode' => 'loan']) ?>
        <?= \App\Core\View::include('partials/pagination', ['pager' => $pager, 'basePath' => '/transaksi/pinjaman',
            'query' => ['q' => $filters['q'], 'bulan' => $filters['month'], 'status' => $filters['status'], 'anggota' => $filters['member']]]) ?>
    <?php endif; ?>
</section>
