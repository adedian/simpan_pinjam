<?php
/**
 * Komponen pembagian bunga. Parameter: $versions (terbaru dulu), $rows (isian), $min (bulan berlaku paling awal, Y-m-d), $effective (Y-m-d),
 * $note, $formId, $errors.
 */
use App\Services\ProfitConfig;

$fixed = ['PENABUNG' => 'Dibagi ke anggota menurut saldo tabungan', 'PEMINJAM' => 'Dibagi ke anggota menurut dasar pinjaman', 'SHU' => 'Kas SHU: sumber laporan Jasa Pinjaman dan Kas SHU'];
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$effMonth = $effective !== '' ? substr($effective, 0, 7) : '';
?>
<section class="card">
    <h2 class="card__title">Komponen pembagian bunga</h2>
    <p class="muted">
        Bunga yang diterima tiap bulan dibagi ke komponen di bawah. <strong>Penabung</strong> dan <strong>Peminjam</strong> dibagi ke setiap anggota (rumus tidak berubah),
        <strong>Kas SHU</strong> menjadi dasar laporan Jasa Pinjaman dan Kas SHU, dan komponen <strong>dana</strong> (mis. Dana Cadangan, Dana Operasional) hanya dihitung totalnya.
        Total harus tepat 100%. Perubahan berlaku <strong>mulai bulan yang Anda pilih</strong>; bulan sebelumnya tetap memakai persentase lamanya.
    </p>

    <?php if ($err('_form')): ?><div class="alert alert--danger" role="alert"><?= e($err('_form')) ?></div><?php endif; ?>

    <form method="post" action="<?= e(url('/sistem/pembagian-bunga')) ?>" novalidate class="comp" data-comp-form>
        <?= csrf_field() ?>
        <input type="hidden" name="_form_id" value="<?= e($formId) ?>">

        <div class="comp__head" aria-hidden="true"><span>Nama komponen</span><span>Persentase</span><span class="comp__preview-head">Pratinjau</span><span></span></div>
        <div class="comp__rows" data-comp-rows>
        <?php foreach ($rows as $i => $r): $kind = isset($fixed[$r['code']]) ? $r['code'] : 'DANA'; ?>
            <div class="comp-row<?= $err("name{$i}") || $err("pct{$i}") ? ' comp-row--error' : '' ?>" data-row data-kind="<?= e($kind) ?>">
                <input type="hidden" name="items[<?= (int) $i ?>][code]" value="<?= e($r['code']) ?>">
                <div class="comp-row__name">
                    <label class="sr-only" for="c_name_<?= (int) $i ?>">Nama komponen</label>
                    <input id="c_name_<?= (int) $i ?>" class="input" type="text" name="items[<?= (int) $i ?>][name]" value="<?= e($r['name']) ?>" maxlength="<?= ProfitConfig::MAX_NAME ?>" placeholder="Nama komponen" autocomplete="off">
                    <small class="comp-row__kind"><?= e($kind === 'DANA' ? 'Dana (hanya total)' : $fixed[$kind]) ?></small>
                    <?php if ($err("name{$i}")): ?><small class="field__error"><?= e($err("name{$i}")) ?></small><?php endif; ?>
                </div>
                <div class="comp-row__pct">
                    <label class="sr-only" for="c_pct_<?= (int) $i ?>">Persentase</label>
                    <div class="input-group">
                        <input id="c_pct_<?= (int) $i ?>" class="input input--num" type="text" inputmode="decimal" name="items[<?= (int) $i ?>][pct]" value="<?= e($r['pct']) ?>" maxlength="6" placeholder="0" autocomplete="off" data-pct>
                        <span class="input-group__suffix">%</span>
                    </div>
                    <?php if ($err("pct{$i}")): ?><small class="field__error"><?= e($err("pct{$i}")) ?></small><?php endif; ?>
                </div>
                <div class="comp-row__amount" data-preview>-</div>
                <div class="comp-row__tools">
                    <?php if ($kind === 'DANA'): ?>
                        <button type="button" class="icon-btn" data-up title="Naikkan" aria-label="Naikkan">&uarr;</button>
                        <button type="button" class="icon-btn" data-down title="Turunkan" aria-label="Turunkan">&darr;</button>
                        <button type="button" class="icon-btn icon-btn--danger" data-remove title="Hapus komponen" aria-label="Hapus komponen">&times;</button>
                    <?php else: ?>
                        <span class="badge badge--draft" title="Komponen tetap: nama dan persentase boleh diubah, tapi tidak boleh dihapus">Tetap</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>

        <template data-comp-template>
            <div class="comp-row" data-row data-kind="DANA">
                <input type="hidden" name="items[__i__][code]" value="">
                <div class="comp-row__name">
                    <label class="sr-only">Nama komponen</label>
                    <input class="input" type="text" name="items[__i__][name]" value="" maxlength="<?= ProfitConfig::MAX_NAME ?>" placeholder="Nama komponen" autocomplete="off">
                    <small class="comp-row__kind">Dana (hanya total)</small>
                </div>
                <div class="comp-row__pct">
                    <label class="sr-only">Persentase</label>
                    <div class="input-group">
                        <input class="input input--num" type="text" inputmode="decimal" name="items[__i__][pct]" value="" maxlength="6" placeholder="0" autocomplete="off" data-pct>
                        <span class="input-group__suffix">%</span>
                    </div>
                </div>
                <div class="comp-row__amount" data-preview>-</div>
                <div class="comp-row__tools">
                    <button type="button" class="icon-btn" data-up title="Naikkan" aria-label="Naikkan">&uarr;</button>
                    <button type="button" class="icon-btn" data-down title="Turunkan" aria-label="Turunkan">&darr;</button>
                    <button type="button" class="icon-btn icon-btn--danger" data-remove title="Hapus komponen" aria-label="Hapus komponen">&times;</button>
                </div>
            </div>
        </template>

        <div class="comp__add no-print">
            <button type="button" class="btn btn--secondary" data-add hidden>+ Tambah komponen dana</button>
            <span class="muted" data-add-hint>Baris kosong di bawah komponen tetap bisa diisi untuk menambah komponen dana.</span>
        </div>

        <div class="alloc" data-alloc role="status" aria-live="polite">
            <div class="alloc__bar"><span data-alloc-fill></span></div>
            <p class="alloc__text" data-alloc-text>Total <strong data-alloc-total>-</strong></p>
        </div>
        <?php if ($err('total')): ?><div class="alert alert--danger" role="alert"><?= e($err('total')) ?></div><?php endif; ?>

        <div class="comp__grid">
            <div class="field<?= $err('effective_from') ? ' field--error' : '' ?>">
                <label for="c_eff">Berlaku mulai bulan <span class="req" aria-hidden="true">*</span></label>
                <input id="c_eff" class="input" type="month" name="effective_from" value="<?= e($effMonth) ?>" min="<?= e(substr($min, 0, 7)) ?>" required data-eff data-current-month="<?= e(date('Y-m')) ?>">
                <small class="field__hint">Paling awal <?= e(month_label($min)) ?> (sesudah versi sebelumnya dan sesudah snapshot yang sudah ditetapkan). Bunga yang diterima bulan itu dan sesudahnya memakai komponen ini.</small>
                <?php if ($err('effective_from')): ?><small class="field__error"><?= e($err('effective_from')) ?></small><?php endif; ?>
            </div>
            <div class="field">
                <label for="c_base">Contoh bunga untuk pratinjau</label>
                <div class="input-group">
                    <input id="c_base" class="input input--num" type="text" inputmode="numeric" value="1.000.000" maxlength="14" autocomplete="off" data-base>
                    <span class="input-group__suffix">Rp</span>
                </div>
                <small class="field__hint">Hanya untuk melihat nominal tiap komponen; tidak disimpan. Kas SHU menyerap selisih pembulatan.</small>
            </div>
        </div>

        <div class="field<?= $err('confirm_past') ? ' field--error' : '' ?>" data-past hidden>
            <label class="check"><input type="checkbox" name="confirm_past" value="1"><span>Bulan berlaku sudah lewat: saya paham angka perkiraan bulan-bulan itu akan berubah (snapshot yang sudah ditetapkan tetap).</span></label>
            <?php if ($err('confirm_past')): ?><small class="field__error"><?= e($err('confirm_past')) ?></small><?php endif; ?>
        </div>

        <div class="field">
            <label for="c_note">Catatan perubahan</label>
            <input id="c_note" class="input" type="text" name="note" value="<?= e($note) ?>" maxlength="255" placeholder="Mis. rapat anggota 1 November: tambah Dana Operasional" autocomplete="off">
        </div>

        <div class="actions actions--sticky">
            <button type="submit" class="btn btn--primary" data-save>Simpan komponen</button>
            <span class="muted">Disimpan sebagai versi baru dan tercatat di audit log. Versi lama tidak diubah.</span>
        </div>
    </form>
</section>

<section class="card">
    <h2 class="card__title">Riwayat versi</h2>
    <div class="table-wrap">
        <table class="table table--stack table--compact">
            <thead><tr><th>Berlaku mulai</th><th>Komponen</th><th>Dibuat</th><th>Catatan</th></tr></thead>
            <tbody>
            <?php foreach ($versions as $n => $v): ?>
                <tr>
                    <td data-label="Berlaku mulai" class="nowrap"><?= e($v['effective_from'] <= '2000-01-01' ? 'Sejak awal' : month_label($v['effective_from'])) ?><?= $n === 0 ? ' <span class="badge badge--disetujui">Terbaru</span>' : '' ?></td>
                    <td data-label="Komponen">
                        <?php foreach ($v['items'] as $i): ?><span class="comp-chip"><?= e($i['name']) ?> <strong><?= e(ProfitConfig::fmtPct($i['pct'])) ?></strong></span> <?php endforeach; ?>
                    </td>
                    <td data-label="Dibuat" class="nowrap"><?= e(date_id(substr($v['created_at'], 0, 10))) ?><br><small class="muted"><?= e($v['created_by']) ?></small></td>
                    <td data-label="Catatan"><?= e((string) ($v['note'] ?? '-')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
