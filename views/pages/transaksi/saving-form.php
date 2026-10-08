<?php $isEdit = $trx !== null; $v = static fn (string $k, string $d = ''): string => (string) ($values[$k] ?? $d); ?>
<section class="card">
    <h2 class="card__title"><?= $isEdit ? 'Ubah draft ' . e($trx['doc_no']) : 'Catat simpanan' ?></h2>
    <p class="muted">Simpanan baru belum memengaruhi saldo. Saldo baru bertambah setelah divalidasi dan disetujui.</p>

    <?php if (!empty($errors['_form'])): ?><div class="alert alert--danger" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>

    <form class="form form--wide" method="post" action="<?= e($action) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="_version" value="<?= e($version) ?>"><?php else: ?><input type="hidden" name="_form_id" value="<?= e($formId) ?>"><?php endif; ?>

        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'member_id', 'label' => 'Anggota', 'type' => 'select', 'options' => $memberOptions, 'value' => $v('member_id'), 'error' => $errors['member_id'] ?? null, 'required' => true,
            'hint' => 'Hanya anggota aktif di regu Anda.']]) ?>

        <div class="form__row">
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'period_month_id', 'label' => 'Bulan setoran', 'type' => 'select', 'options' => $monthOptions, 'value' => $v('period_month_id'), 'error' => $errors['period_month_id'] ?? null, 'required' => true]]) ?>
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'kind', 'label' => 'Jenis simpanan', 'type' => 'select', 'options' => $kinds, 'value' => $v('kind', 'WAJIB'), 'error' => $errors['kind'] ?? null, 'required' => true]]) ?>
        </div>

        <div class="form__row">
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'amount', 'label' => 'Nominal', 'value' => $v('amount'), 'error' => $errors['amount'] ?? null, 'required' => true, 'inputmode' => 'numeric', 'maxlength' => 15, 'placeholder' => '50.000', 'suffix' => 'Rupiah',
                'hint' => 'Angka bulat tanpa desimal. Titik pemisah ribuan boleh.']]) ?>
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'trx_date', 'label' => 'Tanggal setoran', 'type' => 'date', 'value' => $v('trx_date', $today), 'max' => $today, 'error' => $errors['trx_date'] ?? null, 'required' => true]]) ?>
        </div>

        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'description', 'label' => 'Keterangan', 'value' => $v('description'), 'error' => $errors['description'] ?? null, 'maxlength' => 255, 'hint' => 'Opsional.']]) ?>

        <?php if (isset($errors['duplicate']) || !empty($values['confirm_duplicate'])): ?>
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'confirm_duplicate', 'type' => 'checkbox', 'label' => 'Ini memang setoran terpisah, bukan input ganda', 'value' => $values['confirm_duplicate'] ?? 0, 'error' => $errors['duplicate'] ?? null]]) ?>
        <?php endif; ?>

        <div class="actions">
            <?php /* Draft di urutan pertama: tombol bawaan saat Enter ditekan harus yang paling aman. */ ?>
            <button type="submit" class="btn btn--secondary" name="action" value="draft">Simpan sebagai draft</button>
            <button type="submit" class="btn btn--primary" name="action" value="submit" data-confirm="Ajukan simpanan ini ke validasi? Setelah diajukan, nominalnya tidak bisa diubah lagi.">Simpan dan ajukan</button>
            <a class="btn btn--link" href="<?= e($isEdit ? url('/transaksi/' . (int) $trx['id']) : url('/transaksi/simpanan')) ?>">Batal</a>
        </div>
    </form>
</section>
