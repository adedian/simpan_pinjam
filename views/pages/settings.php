<form method="post" action="<?= e(url('/sistem/pengaturan')) ?>" novalidate class="settings">
    <?= csrf_field() ?>

    <?php foreach ($groups as $group => $defs): ?>
        <section class="card">
            <h2 class="card__title"><?= e($group) ?></h2>
            <?php foreach ($defs as $key => $def): ?>
                <?php
                $raw = $values[$key] ?? '';
                if ($def['type'] === 'bool') {
                    $f = ['name' => "s[{$key}]", 'type' => 'checkbox', 'label' => $def['label'], 'value' => $fromOld ? !empty($raw) : $raw === '1', 'hint' => $def['help'] ?? null, 'error' => $errors[$key] ?? null];
                } else {
                    $shown = $raw;
                    if (!$fromOld && !empty($def['money'])) {
                        $shown = number_format((int) $raw, 0, ',', '.');
                    }
                    $f = ['name' => "s[{$key}]", 'label' => $def['label'], 'value' => $shown, 'hint' => $def['help'] ?? null, 'error' => $errors[$key] ?? null,
                        'inputmode' => $def['type'] === 'decimal' ? 'decimal' : 'numeric', 'suffix' => $def['unit'] ?? (!empty($def['money']) ? 'Rp' : null), 'maxlength' => 14];
                }
                ?>
                <?= \App\Core\View::include('partials/field', ['f' => $f]) ?>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

    <div class="actions actions--sticky">
        <button type="submit" class="btn btn--primary">Simpan pengaturan</button>
        <span class="muted">Setiap perubahan tercatat di audit log.</span>
    </div>
</form>
