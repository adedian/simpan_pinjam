<?php
use App\Core\View;

/**
 * Formulir cetak per anggota (satu anggota atau massal).
 * @var string $kind 'pinjaman' | 'tabungan'
 * @var array<int,array<string,mixed>> $sheets
 * @var array<string,mixed>|null $single anggota tunggal (null = massal)
 */
$partial = $kind === 'pinjaman' ? 'partials/sheet-pinjaman' : 'partials/sheet-tabungan';
$names   = ['pinjaman' => 'Rekap Pinjaman', 'tabungan' => 'Tabungan Hari Raya'];
$other   = $kind === 'pinjaman' ? 'tabungan' : 'pinjaman';
?>
<section class="card no-print">
    <div class="card__head">
        <h2 class="card__title"><?= e($names[$kind]) ?><?= $single !== null ? ' · ' . e($single['member']['name']) : ' · semua anggota' ?> <span class="muted">· <?= count($sheets) ?> formulir</span></h2>
        <div class="actions">
            <?php if ($single !== null): ?>
                <a class="btn btn--link" href="<?= e(url('/laporan/anggota/' . (int) $single['member']['id'])) ?>">Kartu anggota</a>
                <a class="btn btn--secondary" href="<?= e(url('/laporan/anggota/' . (int) $single['member']['id'] . '/' . $other)) ?>"><?= e($names[$other]) ?></a>
            <?php else: ?>
                <a class="btn btn--link" href="<?= e(url('/laporan/anggota')) ?>">Daftar anggota</a>
                <a class="btn btn--secondary" href="<?= e(url('/laporan/anggota/cetak/' . $other . ($query !== '' ? '?' . $query : ''))) ?>"><?= e($names[$other]) ?></a>
            <?php endif; ?>
            <?php if ($canExport): ?><a class="btn btn--secondary" href="<?= e(url('/laporan/anggota/unduh' . ($single !== null ? '?anggota=' . (int) $single['member']['id'] : ($query !== '' ? '?' . $query : '')))) ?>">Unduh Excel</a><?php endif; ?>
            <button type="button" class="btn btn--primary" data-print>Cetak</button>
        </div>
    </div>
    <p class="muted">Ukuran A4 tegak. Pada kotak cetak peramban, matikan "Header dan footer" dan nyalakan "Background graphics" agar warna kepala tabel ikut tercetak.</p>
    <?php if ($single === null): ?>
    <form class="toolbar" method="get" action="<?= e(url('/laporan/anggota/cetak/' . $kind)) ?>">
        <div class="toolbar__search">
            <label class="sr-only" for="q">Cari</label>
            <input id="q" class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari nama atau nomor anggota" maxlength="60">
        </div>
        <?php if ($teams !== []): ?>
        <div>
            <label class="sr-only" for="regu">Regu</label>
            <select id="regu" class="input" name="regu" title="Regu">
                <option value="0">Semua regu</option>
                <?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"<?= $filters['team'] === $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn--secondary">Terapkan</button>
        <?php if ($filters['q'] !== '' || $filters['team'] > 0): ?><a class="btn btn--link" href="<?= e(url('/laporan/anggota/cetak/' . $kind)) ?>">Atur ulang</a><?php endif; ?>
    </form>
    <?php endif; ?>
</section>

<?php if ($sheets === []): ?>
    <section class="card no-print"><p class="empty">Tidak ada anggota yang cocok.</p></section>
<?php else: ?>
<div class="sheet-page sheet-grid sheet-grid--<?= e($kind) ?>">
    <?php foreach ($sheets as $sheet): ?>
        <div class="sheet-cell"><?= View::include($partial, ['sheet' => $sheet]) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
