<?php
$isEdit = $account !== null;
$memberOptions = ['' => 'Tidak ditautkan'];
foreach ($members as $m) {
    $memberOptions[$m['member_no']] = $m['name'] . ' (' . $m['member_no'] . ')' . ($m['leads_team'] ? ' · ketua regu' : '');
}
$checked = (array) ($values['roles'] ?? []);
?>
<section class="card">
    <h2 class="card__title"><?= $isEdit ? 'Ubah ' . e($account['username']) : 'Pengguna baru' ?></h2>

    <?php if (!empty($errors['_form'])): ?><div class="alert alert--danger" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>

    <form class="form form--wide" method="post" action="<?= e($action) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="_version" value="<?= e($version) ?>"><?php endif; ?>

        <?php if ($isEdit): ?>
            <div class="field"><label>Nama pengguna</label><div class="static"><?= e($account['username']) ?></div><small class="field__hint">Tidak bisa diubah.</small></div>
        <?php else: ?>
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'username', 'label' => 'Nama pengguna', 'value' => $values['username'] ?? '', 'error' => $errors['username'] ?? null, 'required' => true, 'maxlength' => 30, 'hint' => 'Huruf kecil, angka, titik, garis bawah atau strip. Tidak bisa diubah nanti.']]) ?>
        <?php endif; ?>
        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'name', 'label' => 'Nama', 'value' => $values['name'] ?? '', 'error' => $errors['name'] ?? null, 'required' => true, 'maxlength' => 100]]) ?>

        <fieldset class="fieldset<?= !empty($errors['roles']) ? ' fieldset--error' : '' ?>">
            <legend>Peran</legend>
            <?php foreach ($labels as $code => $label): ?>
                <div class="field field--check">
                    <label class="check">
                        <input type="checkbox" name="roles[]" value="<?= e($code) ?>"<?= in_array($code, $checked, true) ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
                        <span><?= e($label) ?></span>
                    </label>
                </div>
                <?php if ($isSelf && in_array($code, $checked, true)): ?><input type="hidden" name="roles[]" value="<?= e($code) ?>"><?php endif; ?>
            <?php endforeach; ?>
            <small class="field__hint">
                <?php if ($isSelf): ?>Anda tidak bisa mengubah peran akun Anda sendiri.<?php else: ?>
                    Head dan Pemeriksa tidak boleh dipegang orang yang sama. Ketua Regu harus ditautkan ke anggota yang memimpin regu. Anggota harus ditautkan ke data anggota.<?php endif; ?>
            </small>
            <?php if (!empty($errors['roles'])): ?><small class="field__error"><?= e($errors['roles']) ?></small><?php endif; ?>
        </fieldset>

        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'member_no', 'label' => 'Tautkan ke anggota', 'type' => 'select', 'options' => $memberOptions, 'value' => $values['member_no'] ?? '', 'error' => $errors['member_no'] ?? null,
            'hint' => 'Hanya anggota aktif yang belum punya akun.']]) ?>

        <?php if (!$isEdit): ?><p class="muted">Kata sandi sementara dibuat otomatis dan ditampilkan satu kali setelah akun dibuat.</p><?php endif; ?>

        <div class="actions">
            <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Simpan' : 'Buat akun' ?></button>
            <a class="btn btn--secondary" href="<?= e(url('/master/pengguna')) ?>">Batal</a>
        </div>
    </form>
</section>
