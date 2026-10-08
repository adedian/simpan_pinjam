<?php foreach (($flash ?? []) as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>" role="status"><?= e($item['message']) ?></div>
<?php endforeach; ?>
