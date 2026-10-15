<?php
/**
 * Bagian tambahan Report Pembagian Bunga di atas tabel: ringkasan, kontrol (hanya cakupan seluruh koperasi), dan tombol snapshot.
 * Parameter: $report (struktur ProfitReport::build), $snapshotForm (?array), $filters.
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

<?php if ($meta !== null): ?>
<p class="report__status">
    <span class="badge <?= $meta['source'] === 'snapshot' ? 'badge--disetujui' : 'badge--menunggu-validasi' ?>"><?= e($meta['status']) ?></span>
    Data sampai <strong><?= e($meta['as_of'] !== null ? month_label((string) $meta['as_of']) : '-') ?></strong> · dihitung <?= e($meta['when']) ?> ·
    <?= $meta['mode'] === 'EXCEL' ? 'mengikuti perhitungan Excel koperasi' : 'aturan bagi hasil standar' ?>
</p>
<?php endif; ?>

<?php if (!empty($report['control'])): $c = $report['control']; ?>
<div class="alert <?= $c['ok'] ? 'alert--info' : 'alert--danger' ?> no-print" role="note">
    <strong>Kontrol pembagian:</strong>
    pool penabung + peminjam <?= e(money($c['pool'])) ?> = dibagikan (bruto) <?= e(money($c['gross'])) ?> + tidak terbagi <?= e(money($c['undistributed'])) ?>.
    <?= $c['ok'] ? 'Seimbang: tidak ada rupiah hilang atau dibagi dua kali.' : 'TIDAK seimbang: periksa data sebelum menetapkan.' ?>
    <?php if ($c['undistributed'] > 0 && ($meta['mode'] ?? '') === 'EXCEL'): ?>Bagian yang tidak terbagi mengikuti Excel (bagi hasil penabung Desember dibiarkan 0).<?php endif; ?>
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
