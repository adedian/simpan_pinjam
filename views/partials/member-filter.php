<?php
/**
 * Pilihan anggota untuk baris saringan (toolbar): kotak pilih anggota yang bisa dicari, nilainya id anggota di ?anggota=.
 * Hanya untuk Superadmin dan Ketua Regu (MemberLookup 'lihat'); Anggota biasa hanya melihat dirinya sendiri, jadi tidak ada yang dipilih.
 * Bila ada ?anggota= tetapi kotaknya tidak tampil, nilainya dipertahankan sebagai input tersembunyi.
 *
 * Parameter ($p): value (id anggota), name (bawaan "anggota"), id, label, labelled (label terlihat), placeholder
 */
$name     = (string) ($p['name'] ?? 'anggota');
$value    = (int) ($p['value'] ?? 0);
$id       = (string) ($p['id'] ?? 'f_' . $name);
$labelled = !empty($p['labelled']);
?>
<?php if (\App\Services\MemberLookup::allowed(\App\Services\Auth::user(), 'lihat')): ?>
    <div class="<?= $labelled ? 'toolbar__field' : 'toolbar__search' ?> toolbar__field--member">
        <label class="<?= $labelled ? 'toolbar__label' : 'sr-only' ?>" for="<?= e($id) ?>"><?= e((string) ($p['label'] ?? 'Anggota')) ?></label>
        <?= \App\Core\View::include('partials/member-picker', ['p' => [
            'id' => $id, 'name' => $name, 'context' => 'lihat', 'value' => $value > 0 ? $value : '', 'clearable' => true,
            'placeholder' => $p['placeholder'] ?? 'Cari anggota…',
        ]]) ?>
    </div>
<?php elseif ($value > 0): ?>
    <input type="hidden" name="<?= e($name) ?>" value="<?= (int) $value ?>">
<?php endif; ?>
