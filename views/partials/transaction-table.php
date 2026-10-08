<?php
/** Tabel transaksi. Parameter: $rows (hasil Transaction::search), $showType (kolom jenis transaksi), $mode ('loan' = kolom tenor dan sisa pinjaman) */
$isLoan = ($mode ?? '') === 'loan';
$isPlain = ($mode ?? '') === 'payment';   // tanpa kolom jenis/simpanan
$typeLabels = \App\Models\Transaction::TYPE_LABELS;
$kindLabels = \App\Services\SavingService::KIND_LABELS;
?>
<div class="table-wrap">
    <table class="table table--stack">
        <thead>
            <tr>
                <th>No. dokumen</th><th>Tanggal</th><th>Anggota</th><th>Bulan</th>
                <?php if ($isLoan): ?>
                <th>Tenor</th><th class="num">Pokok</th><th class="num">Sisa tagihan</th><th>Status</th>
                <?php elseif ($isPlain): ?>
                <th class="num">Nominal</th><th>Status</th>
                <?php else: ?>
                <th><?= !empty($showType) ? 'Jenis' : 'Simpanan' ?></th>
                <th class="num">Nominal</th><th>Status</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td data-label="No. dokumen" class="nowrap">
                    <a href="<?= e(url('/transaksi/' . (int) $r['id'])) ?>"><?= e($r['doc_no']) ?></a>
                    <?php if ($r['reverses_id'] !== null): ?><span class="tag">Pembalik</span><?php endif; ?>
                    <?php if ($r['source'] === 'IMPOR_EXCEL'): ?><span class="tag">Impor</span><?php endif; ?>
                </td>
                <td data-label="Tanggal" class="nowrap"><?= e(date_id((string) $r['trx_date'])) ?></td>
                <td data-label="Anggota">
                    <?php if ($r['member_id'] !== null): ?>
                        <?= e($r['member_name']) ?> <span class="muted">· <?= e($r['member_no']) ?></span>
                    <?php else: ?><span class="muted">-</span><?php endif; ?>
                </td>
                <td data-label="Bulan"><?= e(month_label((string) $r['month_date'])) ?></td>
                <?php if ($isLoan): ?>
                <td data-label="Tenor" class="nowrap"><?= (int) $r['tenor_months'] ?> bulan <span class="muted">· <?= e(number_format((float) $r['rate_pct_month'], 2, ',', '.')) ?>%</span></td>
                <td data-label="Pokok" class="num"><?= e(money((int) $r['amount'])) ?></td>
                <td data-label="Sisa tagihan" class="num"><?= $r['outstanding'] === null ? '<span class="muted">-</span>' : ((int) $r['outstanding'] > 0 ? e(money((int) $r['outstanding'])) : '<span class="muted">Lunas</span>') ?></td>
                <td data-label="Status"><?= status_badge((string) $r['status']) ?></td>
                <?php elseif ($isPlain): ?>
                <td data-label="Nominal" class="num"><?= e(money(($r['reverses_id'] !== null ? -1 : 1) * (int) $r['amount'])) ?></td>
                <td data-label="Status"><?= status_badge((string) $r['status']) ?></td>
                <?php else: ?>
                <td data-label="<?= !empty($showType) ? 'Jenis' : 'Simpanan' ?>">
                    <?= e(!empty($showType) ? ($typeLabels[$r['type']] ?? $r['type']) . ($r['kind'] ? ' · ' . ($kindLabels[$r['kind']] ?? $r['kind']) : '') : ($kindLabels[$r['kind']] ?? $r['kind'])) ?>
                </td>
                <td data-label="Nominal" class="num"><?= e(money(($r['reverses_id'] !== null ? -1 : 1) * (int) $r['amount'])) ?></td>
                <td data-label="Status"><?= status_badge((string) $r['status']) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
