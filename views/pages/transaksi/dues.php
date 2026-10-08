<section class="card">
    <div class="card__head">
        <h2 class="card__title">Tagihan angsuran <span class="muted">· <?= count($rows) ?> anggota</span></h2>
        <a class="btn btn--secondary" href="<?= e(url('/transaksi/angsuran')) ?>">Kembali ke daftar</a>
    </div>
    <p class="muted">
        Posisi per <strong><?= e($monthName) ?></strong>, dari pinjaman yang sudah disetujui dan pembayaran yang sudah disetujui.
        <em>Jatuh tempo</em> = cicilan sampai bulan ini yang belum lunas; <em>Tunggakan</em> = yang jatuh temponya sebelum bulan ini.
        Pembayaran yang masih menunggu validasi belum mengurangi tagihan. Tidak ada denda.
    </p>

    <div class="stats">
        <div class="stat"><span class="stat__label">Sisa seluruh pinjaman</span><strong class="stat__value"><?= e(money($total['outstanding'])) ?></strong></div>
        <div class="stat"><span class="stat__label">Jatuh tempo sampai <?= e($monthName) ?></span><strong class="stat__value"><?= e(money($total['due_now'])) ?></strong></div>
        <div class="stat"><span class="stat__label">Tunggakan</span><strong class="stat__value<?= $total['overdue'] > 0 ? ' stat__value--negative' : '' ?>"><?= e(money($total['overdue'])) ?></strong></div>
    </div>

    <?php $showTeam = count(array_unique(array_column($rows, 'team_name'))) > 1; ?>
    <?php if ($rows === []): ?>
        <p class="empty">Tidak ada anggota yang masih punya sisa pinjaman.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Anggota</th><?php if ($showTeam): ?><th>Regu</th><?php endif; ?><th class="num">Sisa pinjaman</th><th class="num">Jatuh tempo</th><th class="num">Tunggakan</th><th class="num">Menunggu</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td data-label="Anggota" class="nowrap"><a href="<?= e(url('/anggota/' . (int) $r['id'])) ?>"><?= e($r['name']) ?></a> <span class="muted">· <?= e($r['member_no']) ?></span></td>
                    <?php if ($showTeam): ?><td data-label="Regu"><?= e($r['team_name'] ?? '-') ?></td><?php endif; ?>
                    <td data-label="Sisa pinjaman" class="num"><?= e(money((int) $r['outstanding'])) ?></td>
                    <td data-label="Jatuh tempo" class="num"><?= (int) $r['due_now'] > 0 ? e(money((int) $r['due_now'])) : '<span class="muted">-</span>' ?></td>
                    <td data-label="Tunggakan" class="num"><?= (int) $r['overdue'] > 0 ? '<strong>' . e(money((int) $r['overdue'])) . '</strong>' : '<span class="muted">-</span>' ?></td>
                    <td data-label="Menunggu validasi" class="num"><?= (int) $r['pending'] > 0 ? e(money((int) $r['pending'])) : '<span class="muted">-</span>' ?></td>
                    <td class="cell-actions">
                        <a class="btn btn--secondary btn--sm" href="<?= e(url('/transaksi/angsuran?anggota=' . (int) $r['id'])) ?>">Riwayat</a>
                        <?php if ($canCreate): ?><a class="btn btn--primary btn--sm" href="<?= e(url('/transaksi/angsuran/baru?anggota=' . (int) $r['id'] . ((int) $r['due_now'] > 0 ? '&nominal=' . (int) $r['due_now'] : ''))) ?>">Catat</a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
