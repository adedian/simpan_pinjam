<?php if (!empty($secret)): ?>
<section class="card card--secret" aria-live="polite">
    <h2 class="card__title">Kata sandi sementara untuk <strong><?= e($secret['username']) ?></strong> (<?= e($secret['action']) ?>)</h2>
    <p class="secret" id="secret-value"><?= e($secret['password']) ?></p>
    <p class="muted">Ini <strong>satu-satunya kali</strong> kata sandi ini ditampilkan. Sampaikan langsung kepada yang bersangkutan (jangan lewat grup). Orang itu wajib menggantinya saat masuk pertama.</p>
</section>
<?php endif; ?>

<section class="card">
    <div class="card__head">
        <h2 class="card__title">Pengguna <span class="muted">· <?= count($users) ?></span></h2>
        <a class="btn btn--primary" href="<?= e(url('/master/pengguna/baru')) ?>">Tambah pengguna</a>
    </div>

    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Pengguna</th><th>Peran</th><th>Anggota</th><th>Terakhir masuk</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <?php $roles = array_filter(explode(',', (string) $u['role_codes'])); $self = (int) $u['id'] === (int) $me; ?>
                <tr>
                    <td data-label="Pengguna"><strong><?= e($u['name']) ?></strong><br><span class="muted"><?= e($u['username']) ?></span><?= $self ? ' <span class="tag">Anda</span>' : '' ?></td>
                    <td data-label="Peran"><?= e(implode(', ', array_map(static fn ($r) => $labels[$r] ?? $r, $roles))) ?></td>
                    <td data-label="Anggota"><?= $u['member_no'] ? e($u['member_no']) . ' · ' . e($u['member_name']) : '<span class="muted">-</span>' ?></td>
                    <td data-label="Terakhir masuk"><?= $u['last_login_at'] ? e(date('d-m-Y H:i', strtotime((string) $u['last_login_at']))) : '<span class="muted">Belum pernah</span>' ?></td>
                    <td data-label="Status">
                        <span class="badge badge--<?= $u['is_active'] ? 'disetujui' : 'draft' ?>"><?= $u['is_active'] ? 'Aktif' : 'Nonaktif' ?></span>
                        <?php if ($u['is_locked']): ?><span class="badge badge--ditolak">Terkunci</span><?php endif; ?>
                        <?php if ($u['must_change_password']): ?><span class="badge badge--menunggu-validasi">Sandi sementara</span><?php endif; ?>
                    </td>
                    <td class="cell-actions">
                        <a class="btn btn--secondary btn--sm" href="<?= e(url('/master/pengguna/' . (int) $u['id'] . '/ubah')) ?>">Ubah</a>
                        <?php if (!$self): ?>
                            <details class="menu">
                                <summary class="btn btn--secondary btn--sm">Lainnya</summary>
                                <div class="menu__list">
                                    <form method="post" action="<?= e(url('/master/pengguna/' . (int) $u['id'] . '/reset-password')) ?>">
                                        <?= csrf_field() ?><input type="hidden" name="_version" value="<?= e($u['updated_at']) ?>">
                                        <button type="submit" class="menu__item" data-confirm="Buat kata sandi sementara baru untuk <?= e($u['username']) ?>? Semua sesinya akan dikeluarkan.">Reset kata sandi</button>
                                    </form>
                                    <?php if ($u['is_locked']): ?>
                                        <form method="post" action="<?= e(url('/master/pengguna/' . (int) $u['id'] . '/buka-kunci')) ?>"><?= csrf_field() ?><button type="submit" class="menu__item">Buka kunci</button></form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e(url('/master/pengguna/' . (int) $u['id'] . '/status')) ?>">
                                        <?= csrf_field() ?><input type="hidden" name="_version" value="<?= e($u['updated_at']) ?>"><input type="hidden" name="active" value="<?= $u['is_active'] ? '0' : '1' ?>">
                                        <button type="submit" class="menu__item<?= $u['is_active'] ? ' menu__item--danger' : '' ?>" <?= $u['is_active'] ? 'data-confirm="Nonaktifkan akun ' . e($u['username']) . '? Orang ini tidak bisa masuk lagi."' : '' ?>><?= $u['is_active'] ? 'Nonaktifkan akun' : 'Aktifkan akun' ?></button>
                                    </form>
                                </div>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
