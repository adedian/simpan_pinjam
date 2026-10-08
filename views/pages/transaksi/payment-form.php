<?php $isEdit = $trx !== null; $v = static fn (string $k, string $d = ''): string => (string) ($values[$k] ?? $d); ?>
<section class="card">
    <h2 class="card__title"><?= $isEdit ? 'Ubah draft ' . e($trx['doc_no']) : 'Catat angsuran' ?></h2>
    <p class="muted">
        Isi satu jumlah per anggota. Sistem membaginya ke cicilan <strong>paling tua dulu</strong>; boleh sebagian, boleh melunasi lebih awal, tanpa denda.
        Pinjaman yang dicairkan bulan ini baru bisa dibayar bulan berikutnya. Jumlah tidak boleh melebihi sisa tagihan.
        Lihat <a href="<?= e(url('/transaksi/angsuran/tagihan')) ?>">Tagihan</a> untuk jumlah yang jatuh tempo. Pembagian tampil setelah disimpan.
    </p>

    <?php if (!empty($errors['_form'])): ?><div class="alert alert--danger" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>

    <form class="form form--wide" method="post" action="<?= e($action) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="_version" value="<?= e($version) ?>"><?php else: ?><input type="hidden" name="_form_id" value="<?= e($formId) ?>"><?php endif; ?>

        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'member_id', 'label' => 'Anggota', 'type' => 'select', 'options' => $memberOptions, 'value' => $v('member_id'), 'error' => $errors['member_id'] ?? null, 'required' => true,
            'hint' => 'Hanya anggota aktif di regu Anda.']]) ?>

        <div class="form__row">
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'period_month_id', 'label' => 'Bulan pembayaran', 'type' => 'select', 'options' => $monthOptions, 'value' => $v('period_month_id'), 'error' => $errors['period_month_id'] ?? null, 'required' => true]]) ?>
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'trx_date', 'label' => 'Tanggal pembayaran', 'type' => 'date', 'value' => $v('trx_date', $today), 'max' => $today, 'error' => $errors['trx_date'] ?? null, 'required' => true]]) ?>
        </div>

        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'amount', 'label' => 'Jumlah dibayar', 'value' => $v('amount'), 'error' => $errors['amount'] ?? null, 'required' => true, 'inputmode' => 'numeric', 'maxlength' => 15, 'placeholder' => '1.100.000', 'suffix' => 'Rupiah',
            'hint' => 'Angka bulat tanpa desimal.']]) ?>

        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'description', 'label' => 'Keterangan', 'value' => $v('description'), 'error' => $errors['description'] ?? null, 'maxlength' => 255, 'hint' => 'Opsional.']]) ?>

        <?php if (isset($errors['duplicate']) || !empty($values['confirm_duplicate'])): ?>
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'confirm_duplicate', 'type' => 'checkbox', 'label' => 'Ini memang pembayaran terpisah, bukan input ganda', 'value' => $values['confirm_duplicate'] ?? 0, 'error' => $errors['duplicate'] ?? null]]) ?>
        <?php endif; ?>

        <div class="actions">
            <?php /* Draft di urutan pertama: tombol bawaan saat Enter ditekan harus yang paling aman. */ ?>
            <button type="submit" class="btn btn--secondary" name="action" value="draft">Simpan sebagai draft</button>
            <button type="submit" class="btn btn--primary" name="action" value="submit" data-confirm="Ajukan pembayaran ini ke validasi? Setelah diajukan, jumlah dan pembagiannya tidak bisa diubah lagi.">Simpan dan ajukan</button>
            <a class="btn btn--link" href="<?= e($isEdit ? url('/transaksi/' . (int) $trx['id']) : url('/transaksi/angsuran')) ?>">Batal</a>
        </div>
    </form>
</section>
