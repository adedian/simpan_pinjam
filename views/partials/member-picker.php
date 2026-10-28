<?php
/**
 * Kotak pilih anggota yang bisa dicari (hasil dimuat lewat /api/anggota). Skrip: public/assets/js/member-picker.js.
 * Nilai yang dikirim formulir hanya lewat input tersembunyi dan hanya terisi bila pengguna MEMILIH anggota dari daftar;
 * teks yang diketik saja tidak pernah menjadi nilai. Server tetap memeriksa ulang anggota dan hak aksesnya saat menyimpan.
 *
 * Parameter ($p):
 *   id, name, context (catat|lihat|ketua|akun), value (id anggota; nomor anggota untuk konteks akun),
 *   required, disabled, clearable, placeholder, team (id regu, konteks ketua), include (id anggota tertaut, konteks akun),
 *   invalid (bool), describedby, class
 */
use App\Services\Auth;
use App\Services\MemberLookup;

$context  = (string) ($p['context'] ?? 'lihat');
$value    = $p['value'] ?? '';
$opts     = ['team' => (int) ($p['team'] ?? 0), 'include' => (int) ($p['include'] ?? 0)];
$selected = (string) $value !== '' ? MemberLookup::find(Auth::user(), $context, is_numeric($value) ? (int) $value : (string) $value, $opts) : null;
$byNo     = $context === 'akun';
$id       = (string) ($p['id'] ?? 'mp_' . preg_replace('/[^a-z0-9_]/i', '_', (string) $p['name']));
$required = !empty($p['required']);
$clearable = $p['clearable'] ?? !$required;
$placeholder = (string) ($p['placeholder'] ?? 'Cari nama / nomor anggota');
?>
<div class="mp<?= $selected !== null ? ' has-value' : '' ?><?= !empty($p['disabled']) ? ' is-disabled' : '' ?><?= !empty($p['class']) ? ' ' . e($p['class']) : '' ?>" data-member-picker
     data-endpoint="<?= e(url('/api/anggota')) ?>" data-context="<?= e($context) ?>" data-team="<?= (int) $opts['team'] ?>" data-include="<?= (int) $opts['include'] ?>">
    <input type="hidden" name="<?= e((string) $p['name']) ?>" value="<?= $selected === null ? '' : e($byNo ? $selected['member_no'] : (string) $selected['id']) ?>" data-mp-value<?= !empty($p['disabled']) ? ' disabled' : '' ?>>
    <input id="<?= e($id) ?>" class="input mp__input" type="text" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false"
           autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search" maxlength="60"
           value="<?= $selected === null ? '' : e($selected['label']) ?>" placeholder="<?= e($placeholder) ?>"
           data-mp-label="<?= $selected === null ? '' : e($selected['label']) ?>"<?= $required ? ' required' : '' ?><?= !empty($p['disabled']) ? ' disabled' : '' ?>
           <?= !empty($p['invalid']) ? ' aria-invalid="true"' : '' ?><?= !empty($p['describedby']) ? ' aria-describedby="' . e($p['describedby']) . '"' : '' ?>>
    <?php if ($clearable): ?><button type="button" class="mp__clear" aria-label="Hapus pilihan anggota" tabindex="-1" hidden>&times;</button><?php endif; ?>
    <span class="mp__chevron" aria-hidden="true"></span>
    <noscript><span class="field__hint">Aktifkan JavaScript untuk memilih anggota.</span></noscript>
</div>
