<?php $isEdit = $trx !== null; $v = static fn (string $k, string $d = ''): string => (string) ($values[$k] ?? $d); ?>
<section class="card">
    <h2 class="card__title"><?= $isEdit ? 'Ubah draft ' . e($trx['doc_no']) : 'Catat biaya' ?></h2>
    <p class="muted">Biaya dibebankan ke Kas SHU. Biaya baru belum memengaruhi kas sampai diajukan dan disetujui di Validasi.</p>

    <?php if (!empty($errors['_form'])): ?><div class="alert alert--danger" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>

    <form class="form form--wide" method="post" action="<?= e($action) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="_version" value="<?= e($version) ?>"><?php else: ?><input type="hidden" name="_form_id" value="<?= e($formId) ?>"><?php endif; ?>

        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'category', 'label' => 'Keperluan biaya', 'value' => $v('category'), 'error' => $errors['category'] ?? null, 'required' => true, 'maxlength' => 60, 'placeholder' => 'mis. Cetak laporan Sep-Okt 26',
            'hint' => 'Singkat dan jelas; tampil di Kas SHU dan laporan.']]) ?>

        <div class="form__row">
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'period_month_id', 'label' => 'Bulan', 'type' => 'select', 'options' => $monthOptions, 'value' => $v('period_month_id'), 'error' => $errors['period_month_id'] ?? null, 'required' => true]]) ?>
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'trx_date', 'label' => 'Tanggal biaya', 'type' => 'date', 'value' => $v('trx_date', $today), 'max' => $today, 'error' => $errors['trx_date'] ?? null, 'required' => true]]) ?>
        </div>

        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'amount', 'label' => 'Nominal', 'value' => $v('amount'), 'error' => $errors['amount'] ?? null, 'required' => true, 'inputmode' => 'numeric', 'maxlength' => 15, 'placeholder' => '45.000', 'suffix' => 'Rupiah',
            'hint' => 'Angka bulat tanpa desimal. Titik pemisah ribuan boleh.']]) ?>

        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'description', 'label' => 'Keterangan tambahan', 'value' => $v('description'), 'error' => $errors['description'] ?? null, 'maxlength' => 255, 'hint' => 'Opsional.']]) ?>

        <?php if (isset($errors['duplicate']) || !empty($values['confirm_duplicate'])): ?>
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'confirm_duplicate', 'type' => 'checkbox', 'label' => 'Ini memang biaya terpisah, bukan input ganda', 'value' => $values['confirm_duplicate'] ?? 0, 'error' => $errors['duplicate'] ?? null]]) ?>
        <?php endif; ?>

        <div class="actions">
            <?php /* Draft di urutan pertama: tombol bawaan saat Enter ditekan harus yang paling aman. */ ?>
            <button type="submit" class="btn btn--secondary" name="action" value="draft">Simpan sebagai draft</button>
            <button type="submit" class="btn btn--primary" name="action" value="submit" data-confirm="Ajukan biaya ini ke validasi? Setelah diajukan, nominalnya tidak bisa diubah lagi.">Simpan dan ajukan</button>
            <a class="btn btn--link" href="<?= e(url('/biaya')) ?>">Batal</a>
        </div>
    </form>
</section>
