<?php
/** Daftar pencairan akhir per periode. Variabel: $periods, $period, $batches, $active (?array), $snapshot (?array). */
?>
<section class="card">
    <div class="card__head">
        <h2 class="card__title">Pencairan Akhir</h2>
        <?php if ($active === null && $snapshot !== null): ?>
            <a class="btn btn--primary" href="<?= e(url('/pencairan/baru?periode=' . (int) $period)) ?>">Susun pencairan</a>
        <?php endif; ?>
    </div>
    <p class="muted">Rekap pembagian akhir: tabungan + bagi hasil − sisa pinjaman − potongan arisan/lain, dibulatkan ke bawah. Disusun dari snapshot bagi hasil yang sudah ditetapkan, sehingga angkanya tidak berubah. Ini lembar rekap, bukan pembukuan: tidak mengubah saldo, kas, atau pinjaman.</p>

    <form class="toolbar no-print" method="get" action="<?= e(url('/pencairan')) ?>">
        <div class="toolbar__field toolbar__field--select">
            <label class="sr-only" for="p_periode">Periode</label>
            <select id="p_periode" class="input" name="periode">
                <?php foreach ($periods as $pr): ?>
                    <option value="<?= (int) $pr['id'] ?>"<?= $pr['id'] === $period ? ' selected' : '' ?>><?= e($pr['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn--secondary">Tampilkan</button>
    </form>

    <?php if ($active === null && $snapshot === null): ?>
        <div class="alert alert--warning" role="note">Belum ada snapshot bagi hasil yang berlaku pada periode ini. Tetapkan dulu di <a href="<?= e(url('/laporan/bagihasil?periode=' . (int) $period)) ?>">Pembagian Bunga</a>, lalu kembali ke sini.</div>
    <?php elseif ($active === null): ?>
        <div class="alert alert--info" role="note">Dasar yang tersedia: snapshot data per <strong><?= e(month_label($snapshot['as_of'])) ?></strong> (ditetapkan <?= e(date('d-m-Y H:i', (int) strtotime($snapshot['created_at']))) ?>). Pastikan itu data terakhir sebelum pembagian.</div>
    <?php endif; ?>

    <?php if ($batches === []): ?>
        <p class="empty">Belum ada pencairan pada periode ini.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Disusun</th><th>Dasar data</th><th class="num">Anggota</th><th class="num">Dibayarkan</th><th class="num">Diterima</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
                <tr>
                    <td data-label="Disusun"><?= e(date('d-m-Y H:i', (int) strtotime((string) $b['created_at']))) ?> <span class="muted">· <?= e((string) ($b['created_name'] ?? '-')) ?></span></td>
                    <td data-label="Dasar data"><?= e(month_label((string) $b['as_of_month'])) ?></td>
                    <td data-label="Anggota" class="num"><?= (int) $b['members_count'] ?></td>
                    <td data-label="Dibayarkan" class="num"><?= e(money((int) $b['net_total'])) ?></td>
                    <td data-label="Diterima" class="num"><?= (int) $b['received_count'] ?> / <?= (int) $b['payable_count'] ?></td>
                    <td data-label="Status"><?= $b['void_reason'] === null ? '<span class="badge badge--disetujui">Berlaku</span>' : '<span class="badge badge--ditolak">Dibatalkan</span>' ?></td>
                    <td class="cell-actions"><a class="btn btn--secondary btn--sm" href="<?= e(url('/pencairan/' . (int) $b['id'])) ?>">Buka</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
