<?php
/** Halaman Tunggakan Pinjaman: semua cicilan menunggak dalam cakupan pengguna. Parameter: $items, $summary, $teams, $filters, $showTeam. */
?>
<section class="card">
    <div class="card__head">
        <h2 class="card__title">Tunggakan Pinjaman <span class="muted">· <?= (int) $summary['installments'] ?> angsuran</span></h2>
        <a class="btn btn--secondary" href="<?= e(url('/transaksi/angsuran/tagihan')) ?>">Tagihan angsuran</a>
    </div>
    <p class="muted">
        Cicilan yang tanggal jatuh temponya sudah lewat dan masih ada sisanya, per <strong><?= e(date_id($filters['asof'] !== '' ? $filters['asof'] : date('Y-m-d'))) ?></strong><?= $filters['asof'] !== '' ? ' (tanggal pemeriksaan)' : '' ?>.
        Jatuh tempo hari ini belum menunggak. Tanggal jatuh tempo = tanggal pinjaman disetujui ditambah n bulan; pinjaman lama memakai akhir bulan jatuh tempo.
        Pembayaran yang masih menunggu validasi belum mengurangi tunggakan. Tidak ada denda.
    </p>

    <form class="toolbar no-print" method="get" action="<?= e(url('/transaksi/angsuran/tunggakan')) ?>">
        <div class="toolbar__field toolbar__field--text">
            <label class="sr-only" for="t_q">Cari</label>
            <input id="t_q" class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nama, nomor anggota, atau nomor pinjaman" maxlength="60">
        </div>
        <?php if ($teams !== []): ?>
        <div class="toolbar__field toolbar__field--select">
            <label class="sr-only" for="t_regu">Regu</label>
            <select id="t_regu" class="input" name="regu" title="Regu">
                <option value="0">Semua regu</option>
                <?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (int) $filters['team'] === (int) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="toolbar__field toolbar__field--date">
            <label class="toolbar__label" for="t_per">Per tanggal</label>
            <input id="t_per" class="input" type="date" name="per" value="<?= e($filters['asof']) ?>" max="<?= e(date('Y-m-d', strtotime('-1 day'))) ?>" title="Tanggal pemeriksaan (kosong = hari ini)">
        </div>
        <button type="submit" class="btn btn--primary">Terapkan Filter</button>
        <a class="btn btn--link" href="<?= e(url('/transaksi/angsuran/tunggakan')) ?>">Reset</a>
    </form>

    <?php if (!empty($today) && $today['installments'] > 0): ?>
        <p class="alert alert--warning" role="note"><strong>Jatuh tempo hari ini:</strong> <?= (int) $today['installments'] ?> cicilan (<?= (int) $today['members'] ?> anggota), <?= e(money($today['amount'])) ?>. Belum dihitung menunggak; terlambat mulai besok bila belum dibayar.</p>
    <?php endif; ?>

    <div class="stats stats--report">
        <div class="stat"><span class="stat__label">Anggota menunggak</span><strong class="stat__value"><?= e(number_format($summary['members'], 0, ',', '.')) ?></strong></div>
        <div class="stat"><span class="stat__label">Angsuran belum dibayar</span><strong class="stat__value"><?= e(number_format($summary['installments'], 0, ',', '.')) ?></strong><span class="stat__hint">Terlama <?= e(number_format($summary['max_days'], 0, ',', '.')) ?> hari</span></div>
        <div class="stat"><span class="stat__label">Total nominal tunggakan</span><strong class="stat__value<?= $summary['amount'] > 0 ? ' stat__value--negative' : '' ?>"><?= e(money($summary['amount'])) ?></strong></div>
    </div>

    <?php if ($items === []): ?>
        <p class="empty">Tidak ada tunggakan untuk saringan ini.</p>
    <?php else: ?>
        <?= \App\Core\View::include('partials/overdue-table', ['items' => $items, 'showTeam' => $showTeam]) ?>
    <?php endif; ?>
</section>
