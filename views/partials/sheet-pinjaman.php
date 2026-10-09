<?php
/**
 * Formulir "Rekap Pinjaman" satu anggota. Tampilan meniru formulir Excel koperasi (judul, identitas, tabel biru, sisa pinjaman).
 * @var array<string,mixed> $sheet hasil MemberSheet
 */
$m = $sheet['member'];
?>
<article class="sheet sheet--pinjaman" aria-label="Rekap pinjaman <?= e($m['name']) ?>">
    <h2 class="sheet__title">REKAP PINJAMAN "<?= e(mb_strtoupper((string) config('app.short'))) ?>"</h2>
    <dl class="sheet__meta">
        <dt>NO. URUT</dt><dd><?= (int) $m['no'] ?></dd>
        <dt>NAMA</dt><dd><?= e($m['name']) ?></dd>
        <dt>ALAMAT</dt><dd><?= e($m['address']) ?></dd>
    </dl>
    <table class="sheet__table sheet__table--blue">
        <thead>
            <tr><th class="sheet__no">NO</th><th>TGL</th><th>PINJAMAN<br>POKOK</th><th>BUNGA</th><th>BAYAR ANGSURAN<br>+ BUNGA</th><th>SISA<br>PINJAMAN</th><th class="sheet__note-col">Keterangan</th></tr>
        </thead>
        <tbody>
        <?php foreach ($sheet['loan']['rows'] as $r): ?>
            <tr>
                <td class="sheet__no"><?= (int) $r['no'] ?></td>
                <td class="sheet__date"><?= e(sheet_date($r['date'])) ?></td>
                <td class="sheet__num"><?= e(sheet_num($r['pokok'])) ?></td>
                <td class="sheet__num"><?= e(sheet_num($r['bunga'])) ?></td>
                <td class="sheet__num"><?= e(sheet_num($r['bayar'])) ?></td>
                <td class="sheet__num"><?= e(sheet_num($r['sisa'])) ?></td>
                <td class="sheet__text"><?= e($r['note']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="sheet__sum"><td colspan="5">SISA PINJAMAN</td><td class="sheet__num"><?= e(sheet_num($sheet['loan']['remaining'])) ?></td><td></td></tr>
        </tfoot>
    </table>
</article>
