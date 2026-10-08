<?php
$isEdit = $team !== null;
$options = ['' => 'Pilih ketua regu'];
foreach ($candidates as $c) {
    $options[$c['id']] = $c['name'] . ' (' . $c['member_no'] . ')';
}
?>
<section class="card">
    <h2 class="card__title"><?= $isEdit ? 'Ubah regu' : 'Regu baru' ?></h2>

    <?php if (!empty($errors['_form'])): ?><div class="alert alert--danger" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>

    <form class="form form--wide" method="post" action="<?= e($action) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="_version" value="<?= e($version) ?>"><?php endif; ?>

        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'name', 'label' => 'Nama regu', 'value' => $values['name'] ?? '', 'error' => $errors['name'] ?? null, 'required' => true, 'maxlength' => 100, 'hint' => 'Mis. "Regu Bu Yanti".']]) ?>
        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'leader_member_id', 'label' => 'Ketua regu', 'type' => 'select', 'options' => $options, 'value' => $values['leader_member_id'] ?? '', 'error' => $errors['leader_member_id'] ?? null, 'required' => true,
            'hint' => $isEdit ? 'Hanya anggota regu ini yang bisa dipilih. Pindahkan dulu lewat Data Anggota bila perlu.' : 'Ketua otomatis menjadi anggota regu yang baru dibuat.']]) ?>

        <?php if ($isEdit): ?>
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'is_active', 'type' => 'checkbox', 'label' => 'Regu aktif', 'value' => $values['is_active'] ?? 1, 'error' => $errors['is_active'] ?? null,
                'hint' => 'Regu hanya bisa dinonaktifkan bila tidak punya anggota selain ketua.']]) ?>
        <?php endif; ?>

        <div class="actions">
            <button type="submit" class="btn btn--primary">Simpan</button>
            <a class="btn btn--secondary" href="<?= e(url('/master/ketua-regu')) ?>">Batal</a>
        </div>
    </form>
</section>
