<?php
/**
 * Ringkasan per status. Parameter: $totals (Transaction::search). Hanya DISETUJUI yang masuk saldo;
 * status lain ditampilkan terpisah supaya tidak ada yang mengira sudah dihitung.
 */
$cards = [
    ['DISETUJUI', 'Disetujui', 'Sudah masuk saldo'],
    ['MENUNGGU_VALIDASI', 'Menunggu validasi', 'Belum masuk saldo'],
    ['DRAFT', 'Draft', 'Belum diajukan'],
];
?>
<div class="stats">
    <?php foreach ($cards as [$key, $label, $hint]): ?>
        <div class="stat">
            <span class="stat__label"><?= e($label) ?> · <?= (int) $totals[$key]['n'] ?> transaksi</span>
            <strong class="stat__value"><?= e(money((int) $totals[$key]['sum'])) ?></strong>
            <span class="stat__hint"><?= e($hint) ?></span>
        </div>
    <?php endforeach; ?>
</div>
