<?php
/**
 * Formulir "Tabungan Hari Raya" satu anggota. Tampilan meniru formulir Excel koperasi (judul bergaris bawah, tabel peach,
 * ringkasan bagi hasil, total diterima). Bagi hasil adalah PERKIRAAN (lihat ProfitShare).
 * @var array<string,mixed> $sheet hasil MemberSheet
 */
$m = $sheet['member'];
$s = $sheet['saving'];
?>
<article class="sheet sheet--tabungan" aria-label="Tabungan hari raya <?= e($m['name']) ?>">
    <h2 class="sheet__title sheet__title--underline">Tabungan Hari Raya &nbsp;"<?= e((string) config('app.short')) ?>"</h2>
    <dl class="sheet__meta">
        <dt>NO. URUT</dt><dd><?= (int) $m['no'] ?></dd>
        <dt>NAMA</dt><dd><?= e($m['name']) ?></dd>
        <dt>DAWIS / BLOK</dt><dd><?= e($m['address']) ?></dd>
    </dl>
    <p class="sheet__active">Aktif Per &nbsp;&nbsp; <span><?= e(month_label($m['active_from'])) ?></span></p>
    <table class="sheet__table sheet__table--peach">
        <thead>
            <tr><th class="sheet__no">NO</th><th>TGL</th><th>MASUK</th><th>KELUAR</th><th>TOTAL<br>TABUNGAN</th></tr>
        </thead>
        <tbody>
        <?php foreach ($s['rows'] as $r): ?>
            <tr>
                <td class="sheet__no"><?= (int) $r['no'] ?></td>
                <td class="sheet__date"><?= e(sheet_date($r['date'])) ?></td>
                <td class="sheet__num"><?= e(sheet_num($r['in'])) ?></td>
                <td class="sheet__num"><?= $r['out'] === 0 ? '' : e(sheet_num($r['out'])) ?></td>
                <td class="sheet__num"><?= e(sheet_num($r['total'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="sheet__sum"><td colspan="4">Total Tabungan</td><td class="sheet__num"><?= e(sheet_num($s['total'])) ?></td></tr>
            <tr class="sheet__sub"><td colspan="4">Bagi Hasil Tabungan</td><td class="sheet__num"><?= e(sheet_num($s['saver_share'])) ?></td></tr>
            <tr class="sheet__sub"><td colspan="3">Total Pinjaman</td><td class="sheet__num"><?= e(sheet_num($s['loan_total'])) ?></td><td></td></tr>
            <tr class="sheet__sub"><td colspan="4">Bagi Hasil Pinjaman</td><td class="sheet__num"><?= e(sheet_num($s['borrower_share'])) ?></td></tr>
            <tr class="sheet__sum sheet__sum--grand"><td colspan="4">Total di Terima</td><td class="sheet__num"><?= e(sheet_num($s['received'])) ?></td></tr>
        </tfoot>
    </table>
    <p class="sheet__foot">Bagi hasil adalah perkiraan<?= $sheet['as_of'] !== null ? ' per data ' . e(month_label((string) $sheet['as_of'])) : '' ?>; cicilan yang belum dibayar dihitung sesuai jadwal. Belum dikurangi sisa pinjaman.</p>
</article>
