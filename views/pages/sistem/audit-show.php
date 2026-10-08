<?php
use App\Models\Audit;

$link = match ($entry['entity_type']) {
    'transaction' => $entry['entity_id'] !== null ? '/transaksi/' . (int) $entry['entity_id'] : null,
    'member'      => $entry['entity_id'] !== null ? '/anggota/' . (int) $entry['entity_id'] : null,
    default       => null,
};
$beforeMap = array_column($before, 1, 0);
$afterMap  = array_column($after, 1, 0);
$keys      = array_values(array_unique(array_merge(array_column($before, 0), array_column($after, 0))));
?>
<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= e(Audit::label((string) $entry['action'])) ?> <span class="muted">· #<?= (int) $entry['id'] ?></span></h2>
        <a class="btn btn--link" href="<?= e(url('/sistem/audit')) ?>">Kembali ke daftar</a>
    </div>
    <dl class="dl">
        <div><dt>Waktu</dt><dd><?= e(date('d-m-Y H:i:s', strtotime((string) $entry['created_at']))) ?></dd></div>
        <div><dt>Pengguna</dt><dd><?= $entry['username'] !== null ? e($entry['username']) : '<span class="muted">tamu / sistem</span>' ?><?= !empty($entry['roles']) ? ' <span class="muted">· ' . e($entry['roles']) . '</span>' : '' ?></dd></div>
        <div><dt>Kode aksi</dt><dd><code><?= e($entry['action']) ?></code></dd></div>
        <div><dt>Objek</dt><dd><?= e(Audit::ENTITIES[$entry['entity_type']] ?? $entry['entity_type']) ?><?= $entry['entity_id'] !== null ? ' #' . (int) $entry['entity_id'] : '' ?></dd></div>
        <div><dt>Referensi</dt><dd><?= $entry['reference_no'] === null ? '-' : ($link !== null ? '<a href="' . e(url($link)) . '">' . e($entry['reference_no']) . '</a>' : e($entry['reference_no'])) ?></dd></div>
        <div><dt>Alamat IP</dt><dd><?= e((string) ($entry['ip_address'] ?? '-')) ?></dd></div>
        <div><dt>Peramban</dt><dd><?= e((string) ($entry['user_agent'] ?? '-')) ?></dd></div>
    </dl>
</section>

<section class="card">
    <h2 class="card__title">Isi perubahan</h2>
    <?php if ($keys === []): ?>
        <p class="empty">Aksi ini tidak menyimpan rincian isi (hanya siapa, kapan, dan objeknya).</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Bidang</th><th>Sebelum</th><th>Sesudah</th></tr></thead>
            <tbody>
            <?php foreach ($keys as $k): $b = $beforeMap[$k] ?? null; $a = $afterMap[$k] ?? null; ?>
                <tr>
                    <td data-label="Bidang"><strong><?= e($k) ?></strong></td>
                    <td data-label="Sebelum"><?= $b === null ? '<span class="muted">-</span>' : e($b) ?></td>
                    <td data-label="Sesudah"><?= $a === null ? '<span class="muted">-</span>' : ($b !== null && $b !== $a ? '<strong>' . e($a) . '</strong>' : e($a)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted report__note">Nilai rahasia (kata sandi, token, hash) tidak pernah dicatat, dan disembunyikan bila ada.</p>
    <?php endif; ?>
</section>
