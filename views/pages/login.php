<main class="auth">
    <div class="auth__card">
        <?= \App\Core\View::include('partials/auth-art', []) ?>

        <section class="auth__main">
            <div class="auth__form-wrap">
                <header class="auth__head">
                    <h1 class="auth__hello">Halo!</h1>
                    <p class="auth__sub">Masuk ke akun Anda</p>
                </header>

                <?= \App\Core\View::include('partials/flash', ['flash' => $flash ?? []]) ?>
                <?php if (!empty($errors['form'])): ?>
                    <div class="alert alert--danger" role="alert"><?= e($errors['form']) ?></div>
                <?php endif; ?>

                <form class="auth__form" method="post" action="<?= e(url('/login')) ?>" novalidate>
                    <?= csrf_field() ?>

                    <div class="auth__field">
                        <label class="sr-only" for="username">Nama pengguna</label>
                        <div class="pill<?= !empty($errors['username']) ? ' pill--error' : '' ?>">
                            <span class="pill__icon"><?= icon('user', 22) ?></span>
                            <input id="username" class="pill__input" type="text" name="username" value="<?= e($old['username'] ?? '') ?>"
                                   placeholder="Nama pengguna" autocomplete="username" autocapitalize="none" spellcheck="false"
                                   maxlength="50" required autofocus>
                        </div>
                        <?php if (!empty($errors['username'])): ?><small class="field__error"><?= e($errors['username']) ?></small><?php endif; ?>
                    </div>

                    <div class="auth__field">
                        <label class="sr-only" for="password">Kata sandi</label>
                        <div class="pill<?= !empty($errors['password']) ? ' pill--error' : '' ?>">
                            <span class="pill__icon"><?= icon('lock', 22) ?></span>
                            <input id="password" class="pill__input" type="password" name="password"
                                   placeholder="Kata sandi" autocomplete="current-password" required>
                            <button type="button" class="pill__toggle" data-password-toggle="password" aria-label="Tampilkan kata sandi" aria-pressed="false" hidden>
                                <span data-icon-show><?= icon('eye', 22) ?></span>
                                <span data-icon-hide hidden><?= icon('eye-off', 22) ?></span>
                            </button>
                        </div>
                        <?php if (!empty($errors['password'])): ?><small class="field__error"><?= e($errors['password']) ?></small><?php endif; ?>
                    </div>

                    <button type="submit" class="btn-pill">Masuk</button>
                </form>

                <p class="auth__note"><?= icon('lock', 15) ?> Akun dibuat oleh Head. Lupa kata sandi? Hubungi Head untuk dibuatkan yang baru.</p>
            </div>
        </section>

        <aside class="auth__aside">
            <?= \App\Core\View::include('partials/brand', []) ?>
            <h2 class="auth__welcome">Selamat datang kembali</h2>
            <p class="auth__lead">Catat simpanan, pinjaman, dan angsuran dengan tenang. Saldo baru bertambah setelah transaksi divalidasi, jadi angkanya selalu bisa dipercaya.</p>
        </aside>
    </div>
</main>
