<?php if (!empty($forced)): ?>
    <h1 class="guest__title">Buat kata sandi baru</h1>
    <div class="alert alert--warning" role="status">Kata sandi Anda masih sementara. Ganti dulu sebelum memakai sistem.</div>
<?php endif; ?>

<?php if (empty($forced)): ?><section class="card"><h2 class="card__title">Ganti kata sandi</h2><?php endif; ?>

<form class="form" method="post" action="<?= e(url('/profil/password')) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="field<?= !empty($errors['current_password']) ? ' field--error' : '' ?>">
        <label for="current_password">Kata sandi saat ini</label>
        <input id="current_password" class="input" type="password" name="current_password" autocomplete="current-password" required>
        <?php if (!empty($errors['current_password'])): ?><small class="field__error"><?= e($errors['current_password']) ?></small><?php endif; ?>
    </div>

    <div class="field<?= !empty($errors['password']) ? ' field--error' : '' ?>">
        <label for="password">Kata sandi baru</label>
        <input id="password" class="input" type="password" name="password" autocomplete="new-password" minlength="8" required>
        <small class="field__hint">Minimal 8 karakter, memuat huruf dan angka, tidak mengandung nama pengguna.</small>
        <?php if (!empty($errors['password'])): ?><small class="field__error"><?= e($errors['password']) ?></small><?php endif; ?>
    </div>

    <div class="field<?= !empty($errors['password_confirmation']) ? ' field--error' : '' ?>">
        <label for="password_confirmation">Ulangi kata sandi baru</label>
        <input id="password_confirmation" class="input" type="password" name="password_confirmation" autocomplete="new-password" required>
        <?php if (!empty($errors['password_confirmation'])): ?><small class="field__error"><?= e($errors['password_confirmation']) ?></small><?php endif; ?>
    </div>

    <button type="submit" class="btn btn--primary">Simpan kata sandi</button>
</form>

<?php if (empty($forced)): ?></section><?php endif; ?>

<?php if (!empty($forced)): ?>
    <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn--secondary btn--block">Keluar</button></form>
<?php endif; ?>
