<section class="card">
    <div class="card__head">
        <h2 class="card__title">Riwayat transaksi <span class="muted">· <?= (int) $pager['total'] ?></span></h2>
    </div>

    <?= \App\Core\View::include('partials/transaction-summary', ['totals' => $totals]) ?>

    <form class="toolbar" method="get" action="<?= e(url('/transaksi/riwayat')) ?>">
        <?php if ($filters['member'] > 0): ?><input type="hidden" name="anggota" value="<?= (int) $filters['member'] ?>"><?php endif; ?>
        <div class="toolbar__search">
            <label class="sr-only" for="q">Cari</label>
            <input id="q" class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari anggota atau nomor dokumen" maxlength="60">
        </div>
        <div>
            <label class="sr-only" for="type">Jenis transaksi</label>
            <select id="type" class="input" name="type">
                <option value="">Semua jenis</option>
                <?php foreach ($types as $code => $label): ?>
                    <option value="<?= e($code) ?>"<?= $filters['type'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
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
        <?php if ($filters['q'] !== '' || $filters['type'] !== '' || $filters['month'] || $filters['status'] !== '' || $filters['member']): ?>
            <a class="btn btn--link" href="<?= e(url('/transaksi/riwayat')) ?>">Atur ulang</a>
        <?php endif; ?>
    </form>
    <?php if ($member !== null): ?>
        <p class="muted">Menampilkan transaksi <strong><?= e($member['name']) ?></strong> (<?= e($member['member_no']) ?>).</p>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <p class="empty">Belum ada transaksi yang cocok.</p>
    <?php else: ?>
        <?= \App\Core\View::include('partials/transaction-table', ['rows' => $rows, 'showType' => true]) ?>
        <?= \App\Core\View::include('partials/pagination', ['pager' => $pager, 'basePath' => '/transaksi/riwayat',
            'query' => ['q' => $filters['q'], 'type' => $filters['type'], 'bulan' => $filters['month'], 'status' => $filters['status'], 'anggota' => $filters['member']]]) ?>
    <?php endif; ?>
</section>
