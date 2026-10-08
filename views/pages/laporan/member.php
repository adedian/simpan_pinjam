<?php
use App\Core\View;

$m = $card['member'];
$s = $card['summary'];
?>
<section class="card report">
    <header class="report__head">
        <div>
            <h2 class="card__title">Kartu anggota · <?= e($m['name']) ?> <span class="muted">· <?= e($m['member_no']) ?></span></h2>
            <p class="muted">Regu <?= e((string) ($m['team_name'] ?? '-')) ?><?= !empty($m['leader_name']) ? ' (ketua: ' . e($m['leader_name']) . ')' : '' ?> · aktif sejak <?= e(month_label((string) $m['active_from'])) ?> · <?= $m['status'] === 'AKTIF' ? 'aktif' : 'nonaktif' ?></p>
            <p class="muted report__stamp">Dicetak <?= e($printedAt) ?> oleh <?= e($printedBy) ?> · <?= e(config('app.name')) ?></p>
        </div>
        <div class="actions no-print">
            <a class="btn btn--link" href="<?= e(url('/laporan/anggota')) ?>">Daftar anggota</a>
            <button type="button" class="btn btn--secondary" data-print>Cetak</button>
            <?php if ($canExport): ?><a class="btn btn--primary" href="<?= e(url('/laporan/anggota/unduh?anggota=' . (int) $m['id'])) ?>">Unduh transaksi (CSV)</a><?php endif; ?>
        </div>
    </header>

    <div class="stats">
        <div class="stat"><span class="stat__label">Saldo tabungan</span><strong class="stat__value"><?= e(money($s['savings'])) ?></strong></div>
        <div class="stat"><span class="stat__label">Sisa pinjaman</span><strong class="stat__value"><?= e(money($s['outstanding'])) ?></strong></div>
        <div class="stat"><span class="stat__label">Cicilan berikutnya</span><strong class="stat__value"><?= e(money($s['next']['remaining'] ?? 0)) ?></strong><span class="stat__hint"><?= $s['next'] !== null ? 'Jatuh tempo ' . e(month_label($s['next']['due_month'])) : 'Tidak ada tagihan' ?></span></div>
        <div class="stat"><span class="stat__label">Tunggakan</span><strong class="stat__value<?= $s['overdue'] > 0 ? ' stat__value--negative' : '' ?>"><?= e(money($s['overdue'])) ?></strong></div>
    </div>
    <?php if ($s['byKind'] !== []): ?>
        <p class="muted">Rincian simpanan:
            <?php $parts = []; foreach ($s['byKind'] as $k => $v) { $parts[] = e($kinds[$k] ?? $k) . ' ' . e(money((int) $v)); } echo implode(' · ', $parts); ?></p>
    <?php endif; ?>
</section>

<section class="card">
    <h2 class="card__title">Per bulan <span class="muted">(rupiah)</span></h2>
    <?php if ($card['monthly'] === []): ?>
        <p class="empty">Belum ada transaksi yang disetujui.</p>
    <?php else: ?>
    <div class="table-wrap table-wrap--report">
        <table class="table table--report">
            <thead><tr><th>Bulan</th><th class="num">Simpanan</th><th class="num">Pencairan pinjaman</th><th class="num">Angsuran</th><th class="num">Saldo tabungan</th></tr></thead>
            <tbody>
            <?php foreach ($card['monthly'] as $r): ?>
                <tr>
                    <td><?= e(month_label($r['month'])) ?></td>
                    <td class="num"><?= $r['simpanan'] === 0 ? '<span class="muted">-</span>' : e(\App\Helpers\Money::format($r['simpanan'], false)) ?></td>
                    <td class="num"><?= $r['pencairan'] === 0 ? '<span class="muted">-</span>' : e(\App\Helpers\Money::format($r['pencairan'], false)) ?></td>
                    <td class="num"><?= $r['angsuran'] === 0 ? '<span class="muted">-</span>' : e(\App\Helpers\Money::format($r['angsuran'], false)) ?></td>
                    <td class="num"><strong><?= e(\App\Helpers\Money::format($r['saldo'], false)) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php foreach ($card['loans'] as $l): ?>
<section class="card">
    <h2 class="card__title">Pinjaman <a href="<?= e(url('/transaksi/' . (int) $l['transaction_id'])) ?>"><?= e($l['loan_no']) ?></a> <?= $l['loan_status'] === 'LUNAS' ? '<span class="badge badge--disetujui">Lunas</span>' : '<span class="badge badge--menunggu-validasi">Aktif</span>' ?></h2>
    <p class="muted">Total tagihan <?= e(money((int) $l['total_due'])) ?> · terbayar <?= e(money((int) $l['paid'])) ?> · sisa <strong><?= e(money((int) $l['outstanding'])) ?></strong></p>
    <div class="table-wrap table-wrap--report">
        <table class="table table--report">
            <thead><tr><th>Cicilan ke</th><th>Jatuh tempo</th><th class="num">Tagihan</th><th class="num">Terbayar</th><th class="num">Sisa</th></tr></thead>
            <tbody>
            <?php foreach ($l['installments'] as $in): ?>
                <tr>
                    <td><?= (int) $in['seq'] ?></td><td><?= e(month_label((string) $in['due_month'])) ?></td>
                    <td class="num"><?= e(\App\Helpers\Money::format((int) $in['amount_due'], false)) ?></td>
                    <td class="num"><?= (int) $in['paid_amount'] === 0 ? '<span class="muted">-</span>' : e(\App\Helpers\Money::format((int) $in['paid_amount'], false)) ?></td>
                    <td class="num"><?= (int) $in['remaining_amount'] > 0 ? e(\App\Helpers\Money::format((int) $in['remaining_amount'], false)) : '<span class="muted">Lunas</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endforeach; ?>

<section class="card">
    <h2 class="card__title">Semua transaksi <span class="muted">· <?= count($card['transactions']['rows']) ?></span></h2>
    <?= View::include('partials/report-table', ['report' => $card['transactions']]) ?>
    <p class="muted report__note"><?= e($card['transactions']['note']) ?> Termasuk transaksi yang belum disetujui; hanya yang disetujui masuk saldo.</p>
</section>
