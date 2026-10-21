<?php
/** Rekap satu pencairan. Variabel: $p (PayoutService::find), $report, $denoms, $canExport, $printedBy, $printedAt. */
use App\Core\View;

$voided   = (bool) $p['voided'];
$payable  = array_values(array_filter($p['lines'], static fn (array $l): bool => (int) $l['net_paid'] > 0));
$received = count(array_filter($payable, static fn (array $l): bool => $l['received_on'] !== null));
$short    = array_values(array_filter($p['lines'], static fn (array $l): bool => (int) $l['shortfall'] > 0));
$receivedSum = (int) array_sum(array_map(static fn (array $l): int => $l['received_on'] !== null ? (int) $l['net_paid'] : 0, $payable));
?>
<section class="card report">
    <header class="report__head">
        <div>
            <h2 class="card__title">Pencairan Akhir · <?= e($p['period_name']) ?> <?= $voided ? '<span class="badge badge--ditolak">Dibatalkan</span>' : '<span class="badge badge--disetujui">Berlaku</span>' ?></h2>
            <p class="muted"><?= e($report['subtitle']) ?></p>
            <p class="muted report__stamp">Dicetak <?= e($printedAt) ?> oleh <?= e($printedBy) ?> · <?= e(config('app.name')) ?></p>
        </div>
        <div class="actions no-print">
            <a class="btn btn--link" href="<?= e(url('/pencairan?periode=' . (int) $p['period_id'])) ?>">Daftar</a>
            <button type="button" class="btn btn--secondary" data-print>Cetak</button>
            <?php if ($canExport): ?><a class="btn btn--primary" href="<?= e(url('/pencairan/' . (int) $p['id'] . '/unduh')) ?>">Unduh Excel</a><?php endif; ?>
        </div>
    </header>

    <?php if ($voided): ?>
        <div class="alert alert--danger" role="alert">
            <strong>Pencairan ini DIBATALKAN</strong> pada <?= e(date('d-m-Y H:i', (int) strtotime((string) $p['voided_at']))) ?> oleh <?= e((string) ($p['void_name'] ?? '-')) ?>. Alasan: <?= e((string) $p['void_reason']) ?>.
            Datanya disimpan sebagai riwayat dan tidak boleh dipakai untuk membayar.
        </div>
    <?php endif; ?>
    <?php if ($p['note'] !== null && $p['note'] !== ''): ?><p><strong>Catatan:</strong> <?= e((string) $p['note']) ?></p><?php endif; ?>

    <div class="stats stats--report">
        <div class="stat"><span class="stat__label">Uang yang dibayarkan</span><strong class="stat__value"><?= e(money((int) $p['net_total'])) ?></strong><span class="stat__hint"><?= count($payable) ?> anggota menerima tunai</span></div>
        <div class="stat"><span class="stat__label">Tabungan + bagi hasil</span><strong class="stat__value"><?= e(money((int) $p['savings_total'] + (int) $p['saver_total'] + (int) $p['borrower_total'])) ?></strong><span class="stat__hint">Tabungan <?= e(money((int) $p['savings_total'])) ?> + bagi hasil <?= e(money((int) $p['saver_total'] + (int) $p['borrower_total'])) ?></span></div>
        <div class="stat"><span class="stat__label">Dipotong</span><strong class="stat__value"><?= e(money((int) $p['loan_total'] + (int) $p['arisan_total'] + (int) $p['other_total'])) ?></strong><span class="stat__hint">Pinjaman <?= e(money((int) $p['loan_total'])) ?> · arisan <?= e(money((int) $p['arisan_total'])) ?> · lain <?= e(money((int) $p['other_total'])) ?></span></div>
        <div class="stat"><span class="stat__label">Sisa pembulatan</span><strong class="stat__value"><?= e(money((int) $p['rounding_total'])) ?></strong><span class="stat__hint">Tidak dibayarkan (kelipatan <?= e(number_format((int) $p['rounding_unit'], 0, ',', '.')) ?>)</span></div>
    </div>

    <?php if ($short !== []): ?>
        <div class="alert alert--warning" role="note">
            <strong><?= count($short) ?> anggota potongannya melebihi haknya</strong> (diterima 0, masih berutang <?= e(money((int) $p['shortfall_total'])) ?> secara total):
            <?= e(implode(', ', array_map(static fn (array $l): string => $l['name'] . ' ' . money((int) $l['shortfall']), $short))) ?>.
        </div>
    <?php endif; ?>

    <?= View::include('partials/report-table', ['report' => $report]) ?>
    <p class="muted report__note"><?= e($report['note']) ?></p>
</section>

<section class="card">
    <h2 class="card__title">Kebutuhan uang tunai per pecahan</h2>
    <p class="muted">Dihitung dari jumlah yang dibayarkan tiap anggota (pecahan terbesar dulu), untuk menyiapkan uang sebelum pembagian.</p>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Pecahan</th><th class="num">Lembar/keping</th><th class="num">Nilai</th></tr></thead>
            <tbody>
            <?php foreach ($denoms['counts'] as $d => $n): if ($n === 0) { continue; } ?>
                <tr><td>Rp <?= e(number_format($d, 0, ',', '.')) ?></td><td class="num"><?= e(number_format($n, 0, ',', '.')) ?></td><td class="num"><?= e(money($d * $n)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th>Jumlah</th><th></th><th class="num"><?= e(money($denoms['total'] - $denoms['leftover'])) ?></th></tr></tfoot>
        </table>
    </div>
    <?php if ($denoms['leftover'] > 0): ?><p class="muted">Sisa <?= e(money($denoms['leftover'])) ?> di bawah pecahan terkecil (Rp 100) tidak bisa dibayar tunai pas; gunakan pembulatan yang lebih besar.</p><?php endif; ?>
</section>

<section class="card no-print">
    <h2 class="card__title">Penerimaan uang <span class="muted">· <?= (int) $received ?> / <?= count($payable) ?> anggota (<?= e(money($receivedSum)) ?>)</span></h2>
    <?php if ($voided): ?>
        <p class="muted">Pencairan dibatalkan, jadi penerimaan tidak bisa dicatat.</p>
    <?php elseif ($payable === []): ?>
        <p class="empty">Tidak ada anggota yang menerima uang tunai pada pencairan ini.</p>
    <?php else: ?>
        <p class="muted">Catat tanggal anggota menerima uangnya. Catatan tidak bisa diubah atau dihapus, dan pencairan yang sudah ada penerimaannya tidak bisa dibatalkan.</p>
        <div class="table-wrap">
            <table class="table table--stack">
                <thead><tr><th>Anggota</th><th class="num">Diterima</th><th>Penerimaan</th></tr></thead>
                <tbody>
                <?php foreach ($payable as $l): ?>
                    <tr>
                        <td data-label="Anggota"><strong><?= e($l['name']) ?></strong> <span class="muted"><?= e($l['member_no']) ?></span></td>
                        <td data-label="Diterima" class="num"><?= e(money((int) $l['net_paid'])) ?></td>
                        <td data-label="Penerimaan">
                            <?php if ($l['received_on'] !== null): ?>
                                <span class="badge badge--disetujui">Diterima <?= e(date_id((string) $l['received_on'])) ?></span> <span class="muted">dicatat <?= e((string) ($l['received_by'] ?? '-')) ?><?= $l['received_note'] !== null && $l['received_note'] !== '' ? ' · ' . e((string) $l['received_note']) : '' ?></span>
                            <?php else: ?>
                                <form method="post" action="<?= e(url('/pencairan/' . (int) $p['id'] . '/terima')) ?>" class="toolbar">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="line" value="<?= (int) $l['id'] ?>">
                                    <input class="input" type="date" name="received_on" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required aria-label="Tanggal terima <?= e($l['name']) ?>">
                                    <input class="input" type="text" name="note" maxlength="255" placeholder="Catatan (opsional)" aria-label="Catatan penerimaan <?= e($l['name']) ?>">
                                    <button type="submit" class="btn btn--secondary btn--sm" data-confirm="Catat <?= e($l['name']) ?> sudah menerima <?= e(money((int) $l['net_paid'])) ?>? Catatan tidak bisa diubah atau dihapus.">Catat diterima</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if (!$voided): ?>
<section class="card no-print">
    <h2 class="card__title">Batalkan pencairan</h2>
    <?php if ($received > 0): ?>
        <p class="muted">Tidak bisa dibatalkan karena sudah ada <?= (int) $received ?> anggota yang dicatat menerima uangnya.</p>
    <?php else: ?>
        <form method="post" action="<?= e(url('/pencairan/' . (int) $p['id'] . '/batal')) ?>" class="toolbar">
            <?= csrf_field() ?>
            <div class="toolbar__field toolbar__field--text">
                <label class="sr-only" for="void_reason">Alasan pembatalan</label>
                <input id="void_reason" class="input" type="text" name="reason" minlength="5" maxlength="255" required placeholder="Alasan pembatalan (wajib), mis. potongan arisan salah">
            </div>
            <button type="submit" class="btn btn--danger" data-confirm="Batalkan pencairan ini? Pencairan tidak dihapus: ia ditandai DIBATALKAN beserta alasan Anda, lalu pencairan baru boleh disusun ulang.">Batalkan pencairan</button>
        </form>
        <p class="muted">Tidak menghapus apa pun dan tidak mengubah saldo, kas, atau pinjaman.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
