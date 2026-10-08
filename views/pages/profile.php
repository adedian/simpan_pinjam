<section class="card">
    <h2 class="card__title">Akun</h2>
    <dl class="dl">
        <div><dt>Nama</dt><dd><?= e($user['name']) ?></dd></div>
        <div><dt>Nama pengguna</dt><dd><?= e($user['username']) ?></dd></div>
        <div><dt>Peran</dt><dd><?= e($roleLabel) ?></dd></div>
        <?php if ($member !== null): ?>
            <div><dt>Anggota</dt><dd><a href="<?= e(url('/anggota/' . (int) $member['id'])) ?>"><?= e($member['member_no']) ?> · <?= e($member['name']) ?></a></dd></div>
            <div><dt>Regu</dt><dd><?= e($member['team_name'] ?? '-') ?></dd></div>
        <?php endif; ?>
    </dl>
    <p class="actions"><a class="btn btn--secondary" href="<?= e(url('/profil/password')) ?>">Ganti kata sandi</a></p>
</section>
