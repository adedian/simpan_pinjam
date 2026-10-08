<?php if (!empty($framed)): ?>
    <section class="card error-card" aria-labelledby="error-title">
        <p class="error__code"><?= (int) $status ?></p>
        <h2 id="error-title" class="guest__title"><?= e($message) ?></h2>
        <?php if (!empty($hint)): ?><p class="muted"><?= e($hint) ?></p><?php endif; ?>
        <p class="error-card__actions">
            <a class="btn btn--primary" href="<?= e(url('/')) ?>" data-back>Kembali</a>
            <a class="btn btn--secondary" href="<?= e(url('/')) ?>">Ke Dashboard</a>
        </p>
    </section>
<?php else: ?>
    <p class="error__code"><?= (int) $status ?></p>
    <h1 class="guest__title"><?= e($message) ?></h1>
    <p><a class="btn btn--secondary" href="<?= e(url('/')) ?>">Kembali ke beranda</a></p>
    <?php if (!empty($debug)): ?>
        <pre class="debug"><?= e($debug) ?></pre>
    <?php endif; ?>
<?php endif; ?>
