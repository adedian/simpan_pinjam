<?php
/** Rincian bagi hasil satu anggota per siklus. Parameter: $detail (ProfitReport::memberDetail), $back, $printedBy, $printedAt. */
$d = $detail; $m = $d['member']; $x = $d['data']; $sum = $d['sum'];
$pct = static fn (float $v): string => number_format($v * 100, 4, ',', '.') . '%';
$rp  = static fn (float|int $v): string => e(\App\Helpers\Money::format((int) round($v), false));
?>
<section class="card report">
    <header class="report__head">
        <div>
            <h2 class="card__title">Bagi hasil · <?= e($m['name']) ?> <span class="muted">· <?= e($m['member_no']) ?></span></h2>
            <p class="muted"><?= e($m['team_name']) ?> · data sampai <?= e($x['as_of'] !== null ? month_label((string) $x['as_of']) : '-') ?> · <?= e($x['status']) ?> · dihitung <?= e(date('d-m-Y H:i', (int) strtotime((string) $x['calculated_at']))) ?></p>
            <p class="muted report__stamp">Dicetak <?= e($printedAt) ?> oleh <?= e($printedBy) ?> · <?= e(config('app.name')) ?></p>
        </div>
        <div class="actions no-print">
            <button type="button" class="btn btn--secondary" data-print>Cetak</button>
            <a class="btn btn--secondary" href="<?= e(url($back)) ?>">Kembali ke laporan</a>
        </div>
    </header>

    <?php if (!empty($x['voided'])): ?>
        <div class="alert alert--danger" role="alert"><strong>Snapshot ini DIBATALKAN</strong> pada <?= e(date('d-m-Y H:i', (int) strtotime((string) $x['voided']['at']))) ?> oleh <?= e($x['voided']['by']) ?>. Alasan: <?= e($x['voided']['reason']) ?>. Hanya riwayat; jangan dipakai sebagai dasar pembagian.</div>
    <?php endif; ?>

    <div class="stats stats--report">
        <div class="stat"><span class="stat__label">Total bagi hasil</span><strong class="stat__value"><?= e(money($sum['total'])) ?></strong><span class="stat__hint">Penabung + peminjam, setelah cadangan</span></div>
        <div class="stat"><span class="stat__label">Bagi hasil penabung</span><strong class="stat__value"><?= e(money($sum['saver'])) ?></strong><span class="stat__hint"><?= (int) $x['saver_pct'] ?>% dari bunga</span></div>
        <div class="stat"><span class="stat__label">Bagi hasil peminjam</span><strong class="stat__value"><?= e(money($sum['borrower'])) ?></strong><span class="stat__hint"><?= (int) $x['borrower_pct'] ?>% dari bunga</span></div>
        <div class="stat"><span class="stat__label">Cadangan</span><strong class="stat__value"><?= e(money((int) round($sum['reserve']))) ?></strong><span class="stat__hint"><?= $sum['exempt'] ? 'Anggota ini dikecualikan' : 'Dipotong dari bagi hasil bruto' ?></span></div>
    </div>
    <p class="muted">Bruto <?= e(money((int) round($sum['gross']))) ?> (penabung <?= e(money((int) round($sum['saver_gross']))) ?> + peminjam <?= e(money((int) round($sum['borrower_gross']))) ?>) − cadangan = bagi hasil di atas; tiap bagian dibulatkan ke rupiah.
        Posisi akhir bulan data: saldo tabungan <?= e(money($sum['savings'])) ?>, sisa pinjaman <?= e(money($sum['outstanding'])) ?>.</p>

    <div class="table-wrap table-wrap--report">
        <table class="table table--report">
            <thead>
                <tr>
                    <th>Siklus</th><th>Data akhir bulan</th>
                    <?php if ($d['global']): ?><th class="num">Bunga diterima bulan berikutnya</th><?php endif; ?>
                    <th class="num">Saldo tabungan</th><th class="num">Porsi penabung</th><th class="num">Bagian penabung</th>
                    <th class="num">Dasar pinjaman</th><th class="num">Porsi peminjam</th><th class="num">Bagian peminjam</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($d['cycles'] === []): ?>
                <tr><td colspan="<?= $d['global'] ? 9 : 8 ?>" class="empty">Belum ada bunga yang dibagikan pada periode ini.</td></tr>
            <?php endif; ?>
            <?php foreach ($d['cycles'] as $c): ?>
                <tr>
                    <td><?= (int) $c['k'] ?></td>
                    <td><?= e(month_label($c['month'])) ?></td>
                    <?php if ($d['global']): ?><td class="num"><?= e($rp($c['interest'])) ?></td><?php endif; ?>
                    <td class="num"><?= e($rp($c['savings'])) ?></td>
                    <td class="num"><?= $c['saver_paid'] ? e($pct($c['saver_share'])) : '<span class="muted">tidak dibagikan</span>' ?></td>
                    <td class="num"><?= $c['saver'] > 0 ? e(number_format($c['saver'], 2, ',', '.')) : '<span class="muted">-</span>' ?></td>
                    <td class="num"><?= e($rp($c['dasar'])) ?> <small class="muted">(sisa <?= e($c['basis']) ?>)</small></td>
                    <td class="num"><?= $c['borrower_paid'] ? e($pct($c['borrower_share'])) : '<span class="muted">-</span>' ?></td>
                    <td class="num"><?= $c['borrower'] > 0 ? e(number_format($c['borrower'], 2, ',', '.')) : '<span class="muted">-</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($d['cycles'] !== []): ?>
            <tfoot>
                <tr><th colspan="<?= $d['global'] ? 5 : 4 ?>">Jumlah bruto</th><th class="num"><?= e(number_format($sum['saver_gross'], 2, ',', '.')) ?></th><th colspan="2"></th><th class="num"><?= e(number_format($sum['borrower_gross'], 2, ',', '.')) ?></th></tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <p class="muted report__note"><?= e($d['rule']) ?> Bagian = porsi x (bunga bulan itu x persentase pool). Porsi = nilai anggota / jumlah seluruh anggota pada bulan itu<?= $d['global'] ? '' : ' (jumlah seluruh koperasi hanya terlihat oleh Superadmin)' ?>.</p>
</section>
