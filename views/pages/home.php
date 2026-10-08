<?php
use App\Core\View;

$isGlobal = $level === 'all';
$hasTeam  = isset($team);
$hasMe    = isset($me);
$types    = \App\Models\Transaction::TYPE_LABELS;
$chart    = static fn (?array $c): string => $c === null ? '' : View::include('partials/chart', ['c' => $c]);
$stat     = static fn (string $label, int $value, string $hint = '', bool $negativeIsBad = false): string =>
    '<div class="stat"><span class="stat__label">' . e($label) . '</span><strong class="stat__value' . ($negativeIsBad && $value < 0 ? ' stat__value--negative' : '') . '">' . e(money($value)) . '</strong>'
    . ($hint !== '' ? '<span class="stat__hint">' . e($hint) . '</span>' : '') . '</div>';
$count    = static fn (string $label, int $value, string $hint = ''): string =>
    '<div class="stat"><span class="stat__label">' . e($label) . '</span><strong class="stat__value">' . e(number_format($value, 0, ',', '.')) . '</strong>'
    . ($hint !== '' ? '<span class="stat__hint">' . e($hint) . '</span>' : '') . '</div>';
?>
<section class="hero">
    <h2 class="hero__title">Halo, <?= e($user['name'] ?? '') ?></h2>
    <p class="hero__text">
        <?= e($roleLabel) ?><?= $period !== null ? ' · ' . e($period) : '' ?> · <?= e($month) ?>.
        <?php if ($level === 'none'): ?>Akun Anda belum tertaut ke data anggota, jadi belum ada angka yang bisa ditampilkan. Hubungi Head koperasi.<?php else: ?>Semua angka dihitung dari transaksi yang sudah disetujui.<?php endif; ?>
    </p>
</section>

<?php /* ---------- perlu perhatian ---------- */ ?>
<?php if (!empty($queue) && $queue['n'] > 0): ?>
    <div class="alert alert--warning" role="note"><strong><?= (int) $queue['n'] ?> transaksi</strong> menunggu validasi (<?= e(money((int) $queue['sum'])) ?>). <a href="<?= e(url('/validasi')) ?>">Buka antrean validasi</a></div>
<?php endif; ?>
<?php if (!empty($noChecker)): ?>
    <div class="alert alert--warning" role="note">Belum ada akun <strong>Pemeriksa</strong> yang aktif, jadi transaksi regu Head dan koreksinya belum bisa divalidasi. <a href="<?= e(url('/master/pengguna')) ?>">Buat di Data Pengguna</a></div>
<?php endif; ?>
<?php if (!empty($issues) || ($isGlobal && $summary['selisih'] !== 0)): ?>
    <div class="alert alert--danger" role="alert"><strong>Ada masalah integritas data</strong><?= !empty($issues) ? ' (' . (int) $issues . ' temuan)' : '' ?><?= $isGlobal && $summary['selisih'] !== 0 ? ', selisih pembukuan ' . e(money($summary['selisih'])) : '' ?>. Jangan menyetujui transaksi baru sebelum diperiksa: jalankan <code>php database/tools/check_integrity.php</code>.</div>
<?php endif; ?>
<?php if ($hasTeam && ($team['work']['DITOLAK'] > 0 || $team['work']['DRAFT'] > 0 || $team['work']['MENUNGGU_VALIDASI'] > 0)): $w = $team['work']; ?>
    <div class="alert <?= $w['DITOLAK'] > 0 ? 'alert--warning' : 'alert--info' ?>" role="note">
        Pekerjaan Anda:
        <?php if ($w['DITOLAK'] > 0): ?><a href="<?= e(url('/transaksi/riwayat?status=DITOLAK')) ?>"><strong><?= (int) $w['DITOLAK'] ?> ditolak</strong></a> (30 hari terakhir, perlu dicatat ulang)<?php endif; ?>
        <?php if ($w['DRAFT'] > 0): ?> · <a href="<?= e(url('/transaksi/riwayat?status=DRAFT')) ?>"><?= (int) $w['DRAFT'] ?> draft</a> belum diajukan<?php endif; ?>
        <?php if ($w['MENUNGGU_VALIDASI'] > 0): ?> · <a href="<?= e(url('/transaksi/riwayat?status=MENUNGGU_VALIDASI')) ?>"><?= (int) $w['MENUNGGU_VALIDASI'] ?> menunggu validasi</a><?php endif; ?>
    </div>
<?php endif; ?>

<?php /* ---------- global: Head dan Pemeriksa ---------- */ ?>
<?php if ($isGlobal): ?>
<section class="section" id="ringkasan">
    <h2 class="section__title">Ringkasan koperasi</h2>
    <div class="stats">
        <?= $stat('Kas tersedia', $summary['kas_tersedia'], 'Siap dipinjamkan atau dikeluarkan', true) ?>
        <?= $stat('Saldo tabungan', $summary['saldo_tabungan'], 'Seluruh anggota') ?>
        <?= $stat('Piutang beredar', $summary['piutang_beredar'], 'Sisa tagihan pinjaman') ?>
        <?= $stat('Bunga dibukukan', $summary['bunga_dibukukan'], 'Dari pinjaman yang berlaku') ?>
        <?= $stat('Biaya', $summary['biaya'], 'Pengeluaran operasional') ?>
    </div>
    <p class="muted">Pembukuan seimbang: kas + piutang = tabungan + bunga − biaya.
        <?php if ($summary['selisih'] === 0): ?><span class="badge badge--disetujui">Selisih Rp 0</span><?php else: ?><span class="badge badge--ditolak">Selisih <?= e(money($summary['selisih'])) ?></span><?php endif; ?></p>
</section>

<section class="charts">
    <?= $chart($flowCharts['flow']) ?>
    <?= $chart($flowCharts['growth']) ?>
    <?= $chart($teamChart) ?>
    <?= $chart($splitChart) ?>
</section>

<section class="card">
    <h2 class="card__title">Per regu</h2>
    <?php if ($teams === []): ?>
        <p class="empty">Belum ada regu.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Regu</th><th class="num">Anggota</th><th class="num">Tabungan</th><th class="num">Sisa pinjaman</th><th class="num">Tunggakan</th></tr></thead>
            <tbody>
            <?php foreach ($teams as $t): ?>
                <tr>
                    <td data-label="Regu"><?= e($t['name']) ?></td>
                    <td data-label="Anggota" class="num"><?= (int) $t['members'] ?></td>
                    <td data-label="Tabungan" class="num"><?= e(money($t['savings'])) ?></td>
                    <td data-label="Sisa pinjaman" class="num"><?= e(money($t['outstanding'])) ?></td>
                    <td data-label="Tunggakan" class="num"><?= $t['overdue'] > 0 ? '<span class="badge badge--ditolak">' . e(money($t['overdue'])) . '</span>' : '<span class="muted">-</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th>Jumlah</th><td class="num"><?= (int) array_sum(array_column($teams, 'members')) ?></td><td class="num"><strong><?= e(money((int) array_sum(array_column($teams, 'savings')))) ?></strong></td><td class="num"><?= e(money((int) array_sum(array_column($teams, 'outstanding')))) ?></td><td class="num"><?= e(money((int) array_sum(array_column($teams, 'overdue')))) ?></td></tr></tfoot>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php /* ---------- regu saya: Ketua Regu (dan Purwati) ---------- */ ?>
<?php if ($hasTeam): ?>
<section class="section" id="regu-saya">
    <h2 class="section__title">Regu saya<?= $team['name'] !== '' ? ' · ' . e($team['name']) : '' ?></h2>
    <div class="stats">
        <?= $count('Anggota aktif', $team['count']) ?>
        <?= $stat('Tabungan regu', (int) $team['savings']) ?>
        <?= $stat('Sisa pinjaman', (int) $team['outstanding']) ?>
        <?= $stat('Tunggakan', (int) $team['overdue'], $team['overdue'] > 0 ? 'Cicilan lewat jatuh tempo' : 'Tidak ada tunggakan') ?>
    </div>
    <?php if (\App\Core\Gate::allows($user, 'transaction.create')): ?>
    <div class="actions">
        <a class="btn btn--primary" href="<?= e(url('/transaksi/simpanan/baru')) ?>">Catat simpanan</a>
        <a class="btn btn--secondary" href="<?= e(url('/transaksi/angsuran/baru')) ?>">Catat angsuran</a>
        <a class="btn btn--secondary" href="<?= e(url('/transaksi/pinjaman/baru')) ?>">Catat pinjaman</a>
        <a class="btn btn--secondary" href="<?= e(url('/transaksi/angsuran/tagihan')) ?>">Lihat tagihan</a>
    </div>
    <?php endif; ?>
</section>

<?php if (!$isGlobal): ?>
<section class="charts">
    <?= $chart($flowCharts['flow']) ?>
    <?= $chart($flowCharts['growth']) ?>
    <?= $chart($team['chart']) ?>
</section>
<?php else: ?>
<section class="charts"><?= $chart($team['chart']) ?></section>
<?php endif; ?>

<section class="card">
    <h2 class="card__title">Anggota regu</h2>
    <?php if ($team['members'] === []): ?>
        <p class="empty">Belum ada anggota di regu ini.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Anggota</th><th class="num">Tabungan</th><th class="num">Sisa pinjaman</th><th class="num">Tunggakan</th></tr></thead>
            <tbody>
            <?php foreach ($team['members'] as $m): ?>
                <tr>
                    <td data-label="Anggota"><a href="<?= e(url('/anggota/' . (int) $m['id'])) ?>"><?= e($m['name']) ?></a> <span class="muted">· <?= e($m['member_no']) ?></span><?= $m['status'] !== 'AKTIF' ? ' <span class="tag">Nonaktif</span>' : '' ?></td>
                    <td data-label="Tabungan" class="num"><?= e(money($m['savings'])) ?></td>
                    <td data-label="Sisa pinjaman" class="num"><?= $m['outstanding'] > 0 ? e(money($m['outstanding'])) : '<span class="muted">-</span>' ?></td>
                    <td data-label="Tunggakan" class="num"><?= $m['overdue'] > 0 ? '<span class="badge badge--ditolak">' . e(money($m['overdue'])) . '</span>' : '<span class="muted">-</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php /* ---------- saya: Anggota ---------- */ ?>
<?php if ($hasMe): ?>
<section class="section" id="saya">
    <h2 class="section__title">Tabungan dan pinjaman saya</h2>
    <div class="stats">
        <?= $stat('Saldo tabungan', $me['savings']) ?>
        <?= $stat('Sisa pinjaman', $me['outstanding'], $me['loans'] === [] ? 'Tidak ada pinjaman berlaku' : '') ?>
        <?= $stat('Cicilan berikutnya', $me['next']['remaining'] ?? 0, $me['next'] !== null ? 'Jatuh tempo ' . month_label($me['next']['due_month']) : 'Tidak ada tagihan') ?>
        <?= $stat('Tunggakan', $me['overdue'], $me['overdue'] > 0 ? 'Cicilan lewat jatuh tempo' : 'Tidak ada tunggakan') ?>
    </div>
</section>

<section class="charts">
    <?= $chart($flowCharts['flow']) ?>
    <?= $chart($flowCharts['growth']) ?>
</section>

<section class="card">
    <h2 class="card__title">Rincian simpanan</h2>
    <?php if ($me['byKind'] === []): ?>
        <p class="empty">Belum ada simpanan yang disetujui.</p>
    <?php else: $kindLabels = \App\Services\SavingService::KIND_LABELS; ?>
        <dl class="dl">
            <?php foreach ($me['byKind'] as $kind => $total): ?>
                <div><dt><?= e($kindLabels[$kind] ?? $kind) ?></dt><dd><?= e(money((int) $total)) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
</section>

<section class="card">
    <h2 class="card__title">Pinjaman saya</h2>
    <?php if ($me['loans'] === []): ?>
        <p class="empty">Tidak ada pinjaman yang berlaku.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Pinjaman</th><th class="num">Total tagihan</th><th class="num">Terbayar</th><th class="num">Sisa</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($me['loans'] as $l): ?>
                <tr>
                    <td data-label="Pinjaman"><a href="<?= e(url('/transaksi/' . (int) $l['transaction_id'])) ?>"><?= e($l['loan_no']) ?></a></td>
                    <td data-label="Total tagihan" class="num"><?= e(money((int) $l['total_due'])) ?></td>
                    <td data-label="Terbayar" class="num"><?= e(money((int) $l['paid'])) ?></td>
                    <td data-label="Sisa" class="num"><strong><?= e(money((int) $l['outstanding'])) ?></strong></td>
                    <td data-label="Status"><?= $l['loan_status'] === 'LUNAS' ? '<span class="badge badge--disetujui">Lunas</span>' : '<span class="badge badge--menunggu-validasi">Aktif</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php /* ---------- tunggakan teratas (semua peran, sesuai cakupan) ---------- */ ?>
<?php if ($level !== 'none' && !$hasMe): ?>
<section class="card">
    <div class="card__head">
        <h2 class="card__title">Tunggakan teratas</h2>
        <a class="btn btn--secondary btn--sm" href="<?= e(url('/transaksi/angsuran/tagihan')) ?>">Semua tagihan</a>
    </div>
    <?php if ($dues === []): ?>
        <p class="empty">Tidak ada tunggakan. Semua cicilan yang jatuh tempo sudah dibayar.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Anggota</th><th>Regu</th><th class="num">Tunggakan</th><th class="num">Sisa pinjaman</th></tr></thead>
            <tbody>
            <?php foreach ($dues as $r): ?>
                <tr>
                    <td data-label="Anggota"><a href="<?= e(url('/anggota/' . (int) $r['id'])) ?>"><?= e($r['name']) ?></a> <span class="muted">· <?= e($r['member_no']) ?></span></td>
                    <td data-label="Regu"><?= e((string) ($r['team_name'] ?? '-')) ?></td>
                    <td data-label="Tunggakan" class="num"><span class="badge badge--ditolak"><?= e(money((int) $r['overdue'])) ?></span></td>
                    <td data-label="Sisa pinjaman" class="num"><?= e(money((int) $r['outstanding'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php /* ---------- transaksi terbaru ---------- */ ?>
<?php if ($level !== 'none'): ?>
<section class="card">
    <div class="card__head">
        <h2 class="card__title">Transaksi terbaru</h2>
        <a class="btn btn--secondary btn--sm" href="<?= e(url('/transaksi/riwayat')) ?>">Semua riwayat</a>
    </div>
    <?php if ($recent === []): ?>
        <p class="empty">Belum ada transaksi.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Dokumen</th><th>Jenis</th><th>Anggota</th><th class="num">Nominal</th><th>Status</th><th>Tanggal</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td data-label="Dokumen"><a href="<?= e(url('/transaksi/' . (int) $r['id'])) ?>"><?= e($r['doc_no']) ?></a><?= $r['reverses_id'] !== null ? '<span class="tag">Pembalik</span>' : '' ?></td>
                    <td data-label="Jenis"><?= e($types[$r['type']] ?? $r['type']) ?></td>
                    <td data-label="Anggota"><?= e((string) ($r['member_name'] ?? '-')) ?></td>
                    <td data-label="Nominal" class="num"><?= e(money(($r['reverses_id'] !== null ? -1 : 1) * (int) $r['amount'])) ?></td>
                    <td data-label="Status"><?= status_badge((string) $r['status']) ?></td>
                    <td data-label="Tanggal"><?= e(date_id((string) $r['trx_date'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
