<?php $totalPending = array_sum(array_column($byType, 'n')); ?>
<section class="card">
    <div class="card__head">
        <h2 class="card__title">Menunggu validasi <span class="muted">· <?= (int) $totalPending ?></span></h2>
        <a class="btn btn--secondary" href="<?= e(url('/validasi/riwayat')) ?>">Riwayat validasi</a>
    </div>

    <?php if ($noChecker): ?>
        <div class="alert alert--warning" role="note">Belum ada akun <strong>Pemeriksa</strong> yang aktif. Transaksi milik Head, regu Head, atau dibuat Head tidak bisa divalidasi siapa pun sampai Head membuat akun Pemeriksa di Data Pengguna.</div>
    <?php endif; ?>

    <div class="stats">
        <?php foreach ($types as $code => $label): if ($byType[$code]['n'] === 0) { continue; } ?>
            <div class="stat"><span class="stat__label"><?= e($label) ?> · <?= (int) $byType[$code]['n'] ?> transaksi</span><strong class="stat__value"><?= e(money((int) $byType[$code]['sum'])) ?></strong></div>
        <?php endforeach; ?>
        <div class="stat"><span class="stat__label">Kas tersedia sekarang</span><strong class="stat__value"><?= e(money($cash)) ?></strong><span class="stat__hint">Batas pencairan pinjaman</span></div>
    </div>

    <form class="toolbar" method="get" action="<?= e(url('/validasi')) ?>">
        <div class="toolbar__search">
            <label class="sr-only" for="q">Cari</label>
            <input id="q" class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari anggota atau nomor dokumen" maxlength="60">
        </div>
        <div>
            <label class="sr-only" for="jenis">Jenis</label>
            <select id="jenis" class="input" name="jenis">
                <option value="">Semua jenis</option>
                <?php foreach ($types as $code => $label): ?>
                    <option value="<?= e($code) ?>"<?= $filters['type'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn--secondary">Cari</button>
        <?php if ($filters['q'] !== '' || $filters['type'] !== ''): ?><a class="btn btn--link" href="<?= e(url('/validasi')) ?>">Atur ulang</a><?php endif; ?>
    </form>

    <?php if ($rows === []): ?>
        <p class="empty">Tidak ada transaksi yang menunggu validasi.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>No. dokumen</th><th>Jenis</th><th>Anggota</th><th class="num">Nominal</th><th>Diajukan</th><th>Anda</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $v = $r['verdict']; ?>
                <tr>
                    <td data-label="No. dokumen" class="nowrap"><a href="<?= e(url('/transaksi/' . (int) $r['id'])) ?>"><?= e($r['doc_no']) ?></a><?= $r['head_related'] ? ' <span class="tag">Hanya Pemeriksa</span>' : '' ?></td>
                    <td data-label="Jenis"><?= e($types[$r['type']] ?? $r['type']) ?></td>
                    <td data-label="Anggota"><?= $r['member_id'] !== null ? e($r['member_name']) . ' <span class="muted">· ' . e($r['member_no']) . '</span>' : '<span class="muted">-</span>' ?></td>
                    <td data-label="Nominal" class="num"><?= e(money(($r['reverses_id'] !== null ? -1 : 1) * (int) $r['amount'])) ?></td>
                    <td data-label="Diajukan"><?= e($r['creator_name'] ?? '-') ?><br><span class="muted"><?= $r['status_changed_at'] !== null ? e(date('d-m-Y H:i', strtotime((string) $r['status_changed_at']))) : '-' ?></span></td>
                    <td data-label="Anda"><?= $v['eligible'] ? '<span class="badge badge--disetujui">Bisa divalidasi</span>' : '<span class="muted" title="' . e((string) $v['reason']) . '">Tidak bisa</span>' ?></td>
                    <td class="cell-actions"><a class="btn <?= $v['eligible'] ? 'btn--primary' : 'btn--secondary' ?> btn--sm" href="<?= e(url('/transaksi/' . (int) $r['id'] . '#validasi')) ?>"><?= $v['eligible'] ? 'Periksa' : 'Lihat' ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= \App\Core\View::include('partials/pagination', ['pager' => $pager, 'basePath' => '/validasi', 'query' => ['q' => $filters['q'], 'jenis' => $filters['type']]]) ?>
    <?php endif; ?>
</section>
