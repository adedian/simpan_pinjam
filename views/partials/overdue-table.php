<?php
/**
 * Tabel cicilan menunggak (dipakai panel dashboard dan halaman Tunggakan).
 * Parameter: $items (OverdueService::items), $showTeam (bool).
 * Enam kolom supaya muat tanpa geser horizontal: cicilan ke-n digabung ke kolom Pinjaman; seharusnya dan dibayar
 * ditulis di bawah sisa tunggakan. Keterlambatan: <= 30 hari kuning, lebih dari itu merah (penanda saja; ini pengingat
 * operasional, bukan tuduhan).
 */
?>
<div class="table-wrap">
    <table class="table table--stack">
        <thead>
            <tr>
                <th>Anggota</th><?php if (!empty($showTeam)): ?><th>Regu</th><?php endif; ?><th>Pinjaman dan cicilan</th><th>Jatuh tempo</th>
                <th class="num">Sisa tunggakan</th><th class="num">Terlambat</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $i): ?>
            <tr>
                <td data-label="Anggota"><a href="<?= e(url('/anggota/' . (int) $i['member_id'])) ?>"><?= e($i['name']) ?></a> <span class="muted">· <?= e($i['member_no']) ?></span></td>
                <?php if (!empty($showTeam)): ?><td data-label="Regu"><?= e($i['team_name']) ?></td><?php endif; ?>
                <td data-label="Pinjaman" class="nowrap"><a href="<?= e(url('/transaksi/' . (int) $i['loan_trx_id'])) ?>"><?= e($i['loan_no']) ?></a><br><small class="muted">cicilan ke-<?= (int) $i['seq'] ?> dari <?= (int) $i['tenor'] ?></small></td>
                <td data-label="Jatuh tempo" class="nowrap"><?= e(date_id($i['due_date'])) ?></td>
                <td data-label="Sisa tunggakan" class="num"><strong><?= e(money($i['remaining'])) ?></strong><br><small class="muted">seharusnya <?= e(money($i['amount_due'])) ?><?= $i['paid'] > 0 ? ' · dibayar ' . e(money($i['paid'])) : '' ?></small></td>
                <td data-label="Terlambat" class="num"><span class="badge <?= $i['days_late'] > 30 ? 'badge--ditolak' : 'badge--menunggu-validasi' ?>"><?= e(number_format($i['days_late'], 0, ',', '.')) ?> hari</span></td>
                <td data-label="Status"><?= e($i['status']) ?><?= $i['pending'] > 0 ? '<br><small class="muted">menunggu validasi ' . e(money($i['pending'])) . '</small>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
