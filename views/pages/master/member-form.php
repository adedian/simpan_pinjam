<?php $isEdit = $member !== null; $teamOptions = ['' => 'Pilih regu']; foreach ($teams as $t) { $teamOptions[$t['id']] = $t['name']; } ?>
<section class="card">
    <h2 class="card__title"><?= $isEdit ? 'Ubah ' . e($member['name']) . ' · ' . e($member['member_no']) : 'Anggota baru' ?></h2>

    <?php if (!empty($errors['_form'])): ?><div class="alert alert--danger" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>

    <form class="form form--wide" method="post" action="<?= e($action) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="_version" value="<?= e($version) ?>"><?php endif; ?>

        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'name', 'label' => 'Nama lengkap', 'value' => $values['name'] ?? '', 'error' => $errors['name'] ?? null, 'required' => true, 'maxlength' => 100, 'hint' => 'Tulis seperti di daftar, mis. "Bu Sari".']]) ?>
        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'address_block', 'label' => 'Blok / alamat', 'value' => $values['address_block'] ?? '', 'error' => $errors['address_block'] ?? null, 'maxlength' => 40, 'hint' => 'Mis. E - 08A. Kosongkan bila tidak ada.']]) ?>

        <div class="form__row">
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'team_id', 'label' => 'Regu', 'type' => 'select', 'options' => $teamOptions, 'value' => $values['team_id'] ?? '', 'error' => $errors['team_id'] ?? null, 'required' => true,
                'hint' => $isEdit ? 'Pindah regu dicatat dengan tanggal hari ini; riwayatnya tersimpan.' : null]]) ?>
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'active_from', 'label' => 'Aktif sejak', 'type' => 'month', 'value' => $values['active_from'] ?? '', 'error' => $errors['active_from'] ?? null, 'required' => true]]) ?>
        </div>

        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['AKTIF' => 'Aktif', 'NONAKTIF' => 'Nonaktif'], 'value' => $values['status'] ?? 'AKTIF', 'error' => $errors['status'] ?? null,
            'hint' => 'Anggota nonaktif tidak bisa menerima transaksi baru. Tidak bisa dinonaktifkan bila masih punya pinjaman berjalan atau memimpin regu.']]) ?>

        <fieldset class="fieldset">
            <legend>Pengaturan khusus</legend>
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'is_manager', 'type' => 'checkbox', 'label' => 'Pengelola', 'value' => $values['is_manager'] ?? 0, 'hint' => 'Penerima 40% SHU.']]) ?>
            <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'reserve_exempt', 'type' => 'checkbox', 'label' => 'Dikecualikan dari potongan cadangan', 'value' => $values['reserve_exempt'] ?? 0, 'hint' => 'Hanya bila sudah disepakati. Perubahannya tercatat di audit.']]) ?>
        </fieldset>

        <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'notes', 'label' => 'Catatan', 'type' => 'textarea', 'value' => $values['notes'] ?? '', 'error' => $errors['notes'] ?? null, 'maxlength' => 255]]) ?>

        <div class="actions">
            <button type="submit" class="btn btn--primary">Simpan</button>
            <a class="btn btn--secondary" href="<?= e($isEdit ? url('/anggota/' . (int) $member['id']) : url('/master/anggota')) ?>">Batal</a>
        </div>
    </form>
</section>
