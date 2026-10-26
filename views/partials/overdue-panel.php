<?php
/**
 * Panel "Tunggakan Pinjaman" di dashboard Ketua Regu dan Superadmin. Parameter: $overdue (OverdueService::panel), $global (bool).
 * Isi panel ikut diperbarui otomatis oleh pembaruan langsung (halaman dashboard adalah wilayah live).
 */
$s = $overdue['summary'];
?>
<section class="card" id="tunggakan">
    <div class="card__head">
        <h2 class="card__title">Tunggakan Pinjaman <?php if ($s['members'] > 0): ?><span class="badge badge--ditolak"><?= (int) $s['members'] ?> anggota</span><?php endif; ?></h2>
        <a class="btn btn--secondary btn--sm" href="<?= e(url('/transaksi/angsuran/tunggakan')) ?>">Lihat semua</a>
    </div>

    <?php if ($overdue['today']['installments'] > 0): ?>
        <p class="alert alert--warning" role="note"><strong>Jatuh tempo hari ini:</strong> <?= (int) $overdue['today']['installments'] ?> cicilan (<?= (int) $overdue['today']['members'] ?> anggota), <?= e(money($overdue['today']['amount'])) ?>. Baru dihitung terlambat mulai besok bila belum dibayar.</p>
    <?php endif; ?>

    <?php if ($s['installments'] === 0): ?>
        <p class="empty">Tidak ada tunggakan. Semua cicilan yang sudah jatuh tempo sudah dibayar.</p>
    <?php else: ?>
        <div class="stats stats--report">
            <div class="stat"><span class="stat__label">Anggota menunggak</span><strong class="stat__value"><?= e(number_format($s['members'], 0, ',', '.')) ?></strong><span class="stat__hint"><?= $global ? 'Seluruh koperasi' : 'Di regu Anda' ?></span></div>
            <div class="stat"><span class="stat__label">Angsuran belum dibayar</span><strong class="stat__value"><?= e(number_format($s['installments'], 0, ',', '.')) ?></strong><span class="stat__hint">Terlama <?= e(number_format($s['max_days'], 0, ',', '.')) ?> hari</span></div>
            <div class="stat"><span class="stat__label">Total nominal tunggakan</span><strong class="stat__value stat__value--negative"><?= e(money($s['amount'])) ?></strong><span class="stat__hint"><?= $s['pending'] > 0 ? 'Belum termasuk ' . e(money($s['pending'])) . ' yang menunggu validasi' : 'Sisa kewajiban cicilan yang lewat jatuh tempo' ?></span></div>
        </div>

        <?php if ($global && count($overdue['teams']) > 1): ?>
        <p class="muted">Per regu:
            <?php foreach ($overdue['teams'] as $k => $t): ?><?= $k > 0 ? ' · ' : '' ?><strong><?= e($t['name']) ?></strong> <?= (int) $t['members'] ?> anggota, <?= e(money($t['amount'])) ?><?php endforeach; ?>
        </p>
        <?php endif; ?>

        <?= \App\Core\View::include('partials/overdue-table', ['items' => $overdue['items'], 'showTeam' => $global]) ?>
        <?php if ($overdue['more'] > 0): ?><p class="muted"><a href="<?= e(url('/transaksi/angsuran/tunggakan')) ?>">Dan <?= (int) $overdue['more'] ?> angsuran lainnya &rarr;</a></p><?php endif; ?>
        <p class="muted report__note">Ini pengingat operasional untuk tindak lanjut, bukan bukti anggota sengaja tidak membayar. Pembayaran yang masih menunggu validasi belum mengurangi tunggakan. Tidak ada denda.</p>
    <?php endif; ?>
</section>
