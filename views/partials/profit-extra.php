<?php
/**
 * Bagian tambahan Report Pembagian Bunga di atas tabel: ringkasan, kontrol (hanya cakupan seluruh koperasi), dan tombol snapshot.
 * Parameter: $report (struktur ProfitReport::build), $snapshotForm (?array), $voidForm (?array), $filters.
 */
$meta = $report['meta'] ?? null;
?>
<?php if (!empty($report['summary'])): ?>
<div class="stats stats--report">
    <?php foreach ($report['summary'] as $s): ?>
        <div class="stat"><span class="stat__label"><?= e($s['label']) ?></span><strong class="stat__value"><?= e(money((int) $s['value'])) ?></strong><span class="stat__hint"><?= e($s['hint']) ?></span></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($meta['components'])): $comp = $meta['components']; $compSum = (int) array_sum(array_column($comp, 'amount')); $showAmount = !empty($meta['global']); ?>
<div class="components">
    <h3 class="components__title">Komponen pembagian bunga</h3>
    <div class="table-wrap">
        <table class="table table--compact">
            <thead><tr><th>Komponen</th><th class="num">Persentase</th><?php if ($showAmount): ?><th class="num">Nominal</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($comp as $c): ?>
                <tr>
                    <td><?= e($c['name']) ?><?php if ($c['kind'] === 'DANA'): ?> <span class="muted">dana</span><?php endif; ?></td>
                    <td class="num"><?= e($c['pct']) ?></td>
                    <?php if ($showAmount): ?><td class="num"><?= e(money((int) $c['amount'])) ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($showAmount): ?><tfoot><tr><th>Jumlah</th><th class="num">100%</th><th class="num"><?= e(money($compSum)) ?></th></tr></tfoot><?php endif; ?>
        </table>
    </div>
    <?php if ($showAmount): ?>
    <p class="muted">Total sumber (bunga yang diterima pada bulan yang dihitung): <strong><?= e(money((int) $meta['total_interest'])) ?></strong>
        <?= $compSum === (int) $meta['total_interest'] ? '· seimbang dengan jumlah komponen.' : '· TIDAK sama dengan jumlah komponen, periksa data.' ?>
        Selisih pembulatan ditanggung Kas SHU.
        <?php if (\App\Core\Gate::allows(\App\Services\Auth::user(), 'settings.manage')): ?><a class="no-print" href="<?= e(url('/sistem/pembagian-bunga')) ?>">Atur komponen</a><?php endif; ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($meta !== null): ?>
<p class="report__status">
    <span class="badge <?= $meta['source'] === 'snapshot' ? ($meta['voided'] === null ? 'badge--disetujui' : 'badge--ditolak') : 'badge--menunggu-validasi' ?>"><?= e($meta['status']) ?></span>
    Data sampai <strong><?= e($meta['as_of'] !== null ? month_label((string) $meta['as_of']) : '-') ?></strong> · dihitung <?= e($meta['when']) ?> ·
    <?= $meta['mode'] === 'EXCEL' ? 'mengikuti perhitungan Excel koperasi' : 'aturan bagi hasil standar' ?>
</p>
<?php endif; ?>

<?php if (!empty($meta['voided'])): $v = $meta['voided']; ?>
<div class="alert alert--danger" role="alert">
    <strong>Snapshot ini DIBATALKAN</strong> pada <?= e(date('d-m-Y H:i', (int) strtotime((string) $v['at']))) ?> oleh <?= e($v['by']) ?>. Alasan: <?= e($v['reason']) ?>.
    Datanya disimpan sebagai riwayat dan tidak boleh dipakai sebagai dasar pembagian.
</div>
<?php endif; ?>

<?php if (!empty($report['control'])): $c = $report['control']; ?>
<div class="alert <?= $c['ok'] ? 'alert--info' : 'alert--danger' ?> no-print" role="note">
    <strong>Kontrol pembagian:</strong>
    pool penabung + peminjam <?= e(money($c['pool'])) ?> = dibagikan (bruto) <?= e(money($c['gross'])) ?> + tidak terbagi <?= e(money($c['undistributed'])) ?>.
    <?= $c['ok'] ? 'Seimbang: tidak ada rupiah hilang atau dibagi dua kali.' : 'TIDAK seimbang: periksa data sebelum menetapkan.' ?>
    <?php if ($c['undistributed'] > 0 && ($meta['mode'] ?? '') === 'EXCEL'): ?>Bagian yang tidak terbagi mengikuti Excel (bagi hasil penabung Desember dibiarkan 0).<?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($voidForm)): ?>
<div class="card card--inline no-print">
    <form method="post" action="<?= e(url('/laporan/bagihasil/snapshot/batal')) ?>" class="toolbar">
        <?= csrf_field() ?>
        <input type="hidden" name="periode" value="<?= (int) $voidForm['period'] ?>">
        <input type="hidden" name="snapshot" value="<?= (int) $voidForm['snapshot'] ?>">
        <div class="toolbar__field toolbar__field--text">
            <label class="sr-only" for="void_reason">Alasan pembatalan</label>
            <input id="void_reason" class="input" type="text" name="reason" minlength="5" maxlength="255" required placeholder="Alasan pembatalan (wajib), mis. ada transaksi yang terlewat">
        </div>
        <button type="submit" class="btn btn--danger" data-confirm="Batalkan snapshot data per <?= e(month_label((string) $voidForm['as_of'])) ?>? Snapshot tidak dihapus: ia ditandai DIBATALKAN beserta alasan Anda, dan tidak bisa dibatalkan lagi atau dikembalikan.">Batalkan snapshot ini</button>
    </form>
    <p class="muted">Pembatalan tidak menghapus apa pun dan tidak mengubah saldo, kas, atau transaksi. Setelahnya, data bulan yang sama boleh ditetapkan ulang sebagai snapshot baru.</p>
</div>
<?php endif; ?>

<?php if (!empty($snapshotForm)): ?>
<div class="card card--inline no-print">
    <?php if ($snapshotForm['exists']): ?>
        <p class="muted">Snapshot untuk data per <?= e(month_label((string) $snapshotForm['as_of'])) ?> sudah ada. Pilih di kolom "Sumber angka" untuk melihatnya. Penetapan kedua untuk keadaan data yang sama tidak diperbolehkan.</p>
    <?php else: ?>
        <form method="post" action="<?= e(url('/laporan/bagihasil/snapshot')) ?>" class="toolbar">
            <?= csrf_field() ?>
            <input type="hidden" name="periode" value="<?= (int) $snapshotForm['period'] ?>">
            <div class="toolbar__field toolbar__field--text">
                <label class="sr-only" for="snap_note">Catatan snapshot</label>
                <input id="snap_note" class="input" type="text" name="note" maxlength="255" placeholder="Catatan (opsional), mis. dasar pembagian THR">
            </div>
            <button type="submit" class="btn btn--primary" data-confirm="Simpan hasil hitung data per <?= e(month_label((string) $snapshotForm['as_of'])) ?> sebagai snapshot? Snapshot tidak bisa diubah atau dihapus.">Simpan sebagai snapshot</button>
        </form>
        <p class="muted">Snapshot menyimpan angka per anggota saat ini supaya laporan tidak berubah ketika transaksi baru masuk. Tidak mengubah saldo, kas, atau transaksi.</p>
    <?php endif; ?>
</div>
<?php endif; ?>
