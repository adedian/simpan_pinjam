<section class="card">
    <h2 class="card__title">Simulasi pinjaman</h2>
    <p class="muted">
        Hanya perhitungan, tidak menyimpan apa pun. Bunga <strong><?= e(number_format((float) $rate, 2, ',', '.')) ?>% per bulan</strong> flat
        (pokok × tarif × tenor), dibayar bersama cicilan. Tenor <?= (int) $tenorMin ?>–<?= (int) $tenorMax ?> bulan<?= $ceiling > 0 ? ', plafon ' . e(money($ceiling)) : '' ?>.
        Cicilan terakhir menyerap sisa pembulatan.
    </p>

    <form class="form form--wide" method="get" action="<?= e(url('/transaksi/pinjaman/simulasi')) ?>" novalidate>
        <div class="form__row">
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'pokok', 'label' => 'Jumlah pinjaman', 'value' => $values['pokok'], 'error' => $simErrors['pokok'] ?? null, 'inputmode' => 'numeric', 'maxlength' => 15, 'placeholder' => '5.000.000', 'suffix' => 'Rupiah']]) ?>
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'tenor', 'label' => 'Tenor', 'value' => $values['tenor'], 'error' => $simErrors['tenor'] ?? null, 'inputmode' => 'numeric', 'maxlength' => 2, 'suffix' => 'bulan']]) ?>
        </div>
        <div class="actions">
            <button type="submit" class="btn btn--primary">Hitung</button>
            <?php if ($canCreate): ?><a class="btn btn--secondary" href="<?= e(url('/transaksi/pinjaman/baru')) ?>">Catat pinjaman</a><?php endif; ?>
            <a class="btn btn--link" href="<?= e(url('/transaksi/pinjaman')) ?>">Kembali ke daftar</a>
        </div>
    </form>
</section>

<?php if ($quote !== null): ?>
<section class="card">
    <h2 class="card__title">Hasil</h2>
    <div class="stats">
        <div class="stat"><span class="stat__label">Pokok</span><strong class="stat__value"><?= e(money((int) $principal)) ?></strong></div>
        <div class="stat"><span class="stat__label">Bunga (dibayar di muka)</span><strong class="stat__value"><?= e(money($quote['interest'])) ?></strong></div>
        <div class="stat"><span class="stat__label">Total tagihan</span><strong class="stat__value"><?= e(money($quote['total'])) ?></strong></div>
    </div>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Cicilan ke</th><th class="num">Jumlah</th></tr></thead>
            <tbody>
            <?php foreach ($quote['installments'] as $i => $amount): ?>
                <tr><td data-label="Cicilan ke"><?= $i + 1 ?></td><td data-label="Jumlah" class="num"><?= e(money($amount)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
