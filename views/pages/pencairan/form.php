<?php
/** Formulir penyusunan pencairan. Variabel: $period, $pv (hasil PayoutService::preview), $units, $errors, $old. */
use App\Services\PayoutService;

$oldD    = is_array($old['d'] ?? null) ? $old['d'] : [];
$unitSel = (int) ($old['unit'] ?? $units[0]);
$noteOld = (string) ($old['note'] ?? '');
$sum     = ['savings' => 0, 'saver' => 0, 'borrower' => 0, 'loan' => 0];
foreach ($pv['rows'] as $r) {
    foreach ($sum as $k => $_) {
        $sum[$k] += $r[$k];
    }
}
$errKeys = array_filter(array_keys($errors), static fn ($k): bool => $k !== '_form');
?>
<section class="card">
    <h2 class="card__title">Susun Pencairan Akhir</h2>
    <p class="muted">Dasar: snapshot bagi hasil data per <strong><?= e(month_label($pv['as_of'])) ?></strong>. Tabungan, bagi hasil, dan sisa pinjaman diambil dari snapshot itu dan tidak bisa diubah di sini. Yang diisi hanya potongan arisan dan potongan lain (bila ada) beserta keterangannya.</p>

    <?php if ($pv['drift'] > 0 || ($pv['live_as_of'] !== null && $pv['live_as_of'] !== $pv['as_of'])): ?>
        <div class="alert alert--warning" role="alert">
            <strong>Data sudah bergerak sejak snapshot.</strong>
            <?= (int) $pv['drift'] ?> anggota punya tabungan atau sisa pinjaman yang berbeda dari snapshot<?= $pv['live_as_of'] !== null && $pv['live_as_of'] !== $pv['as_of'] ? ', dan data terbaru sudah sampai ' . e(month_label((string) $pv['live_as_of'])) : '' ?>.
            Bila pencairan harus memakai data terbaru, batalkan snapshot lama dan tetapkan ulang di Pembagian Bunga sebelum melanjutkan.
        </div>
    <?php endif; ?>
    <?php if (!empty($errors['_form'])): ?><div class="alert alert--danger" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
    <?php if ($errKeys !== []): ?>
        <div class="alert alert--danger" role="alert"><strong>Periksa isian:</strong>
            <ul><?php foreach ($errKeys as $k): ?><li><?= e($errors[$k]) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form class="form" method="post" action="<?= e(url('/pencairan')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="periode" value="<?= (int) $period ?>">
        <input type="hidden" name="snapshot" value="<?= (int) $pv['snapshot_id'] ?>">

        <div class="form__row">
            <div class="field">
                <label for="f_unit">Pembulatan ke bawah</label>
                <select id="f_unit" class="input" name="unit">
                    <?php foreach ($units as $u): ?>
                        <option value="<?= (int) $u ?>"<?= $u === $unitSel ? ' selected' : '' ?>>Kelipatan Rp <?= e(number_format($u, 0, ',', '.')) ?><?= $u === 1 ? ' (tanpa pembulatan)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="f_note">Catatan (opsional)</label>
                <input id="f_note" class="input" type="text" name="note" maxlength="255" value="<?= e($noteOld) ?>" placeholder="mis. pembagian THR Lebaran 2027">
            </div>
        </div>

        <div class="table-wrap">
            <table class="table table--stack table--report">
                <thead>
                    <tr><th>No.</th><th>Nama</th><th>Regu</th><th class="num">Tabungan</th><th class="num">Bagi hasil</th><th class="num">Sisa pinjaman</th><th>Potongan arisan</th><th>Potongan lain</th><th>Keterangan potongan</th><th class="num">Diterima (perkiraan)</th></tr>
                </thead>
                <tbody>
                <?php foreach ($pv['rows'] as $r): $id = (int) $r['member_id']; $o = is_array($oldD[$id] ?? null) ? $oldD[$id] : [];
                    $est = PayoutService::calc($r['savings'], $r['saver'], $r['borrower'], $r['loan'], 0, 0, $unitSel); ?>
                    <tr>
                        <td data-label="No."><?= e($r['no']) ?></td>
                        <td data-label="Nama"><strong><?= e($r['name']) ?></strong></td>
                        <td data-label="Regu"><?= e($r['team']) ?></td>
                        <td data-label="Tabungan" class="num"><?= e(money($r['savings'])) ?></td>
                        <td data-label="Bagi hasil" class="num"><?= e(money($r['saver'] + $r['borrower'])) ?></td>
                        <td data-label="Sisa pinjaman" class="num"><?= $r['loan'] === 0 ? '<span class="muted">-</span>' : e(money($r['loan'])) ?></td>
                        <td data-label="Potongan arisan"><input class="input input--num" type="text" inputmode="numeric" maxlength="15" name="d[<?= (int) $id ?>][arisan]" value="<?= e((string) ($o['arisan'] ?? '')) ?>" placeholder="0" aria-label="Potongan arisan <?= e($r['name']) ?>"></td>
                        <td data-label="Potongan lain"><input class="input input--num" type="text" inputmode="numeric" maxlength="15" name="d[<?= (int) $id ?>][other]" value="<?= e((string) ($o['other'] ?? '')) ?>" placeholder="0" aria-label="Potongan lain <?= e($r['name']) ?>"></td>
                        <td data-label="Keterangan"><input class="input" type="text" maxlength="255" name="d[<?= (int) $id ?>][note]" value="<?= e((string) ($o['note'] ?? '')) ?>" placeholder="wajib bila ada potongan" aria-label="Keterangan potongan <?= e($r['name']) ?>"></td>
                        <td data-label="Diterima (perkiraan)" class="num"><?= $est['shortfall'] > 0 ? '<span class="muted">0 (kurang ' . e(money($est['shortfall'])) . ')</span>' : e(money($est['net'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><th colspan="3">Jumlah (<?= count($pv['rows']) ?> anggota)</th><th class="num"><?= e(money($sum['savings'])) ?></th><th class="num"><?= e(money($sum['saver'] + $sum['borrower'])) ?></th><th class="num"><?= e(money($sum['loan'])) ?></th><th colspan="4"></th></tr>
                </tfoot>
            </table>
        </div>
        <p class="muted">Kolom "Diterima (perkiraan)" belum memperhitungkan potongan arisan dan lain; angka pastinya tampil setelah disusun. Anggota yang potongannya melebihi haknya menerima 0 dan kekurangannya dicatat.</p>

        <div class="actions">
            <button type="submit" class="btn btn--primary" data-confirm="Susun pencairan akhir dari snapshot data per <?= e(month_label($pv['as_of'])) ?>? Hasilnya tidak bisa diubah; bila salah, batalkan (dengan alasan) lalu susun ulang.">Susun pencairan</button>
            <a class="btn btn--link" href="<?= e(url('/pencairan?periode=' . (int) $period)) ?>">Batal</a>
        </div>
    </form>
</section>
