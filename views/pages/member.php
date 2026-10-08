<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= e($member['name']) ?> <span class="muted">· <?= e($member['member_no']) ?></span></h2>
        <div class="actions">
            <?php if (!empty($canTransactions)): ?><a class="btn btn--secondary" href="<?= e(url('/transaksi/riwayat?anggota=' . (int) $member['id'])) ?>">Riwayat transaksi</a><?php endif; ?>
            <?php if (!empty($canManage)): ?><a class="btn btn--secondary" href="<?= e(url('/master/anggota/' . (int) $member['id'] . '/ubah')) ?>">Ubah</a><?php endif; ?>
        </div>
    </div>
    <dl class="dl">
        <div><dt>Blok / alamat</dt><dd><?= e($member['address_block'] ?? '-') ?></dd></div>
        <div><dt>Regu</dt><dd><?= e($member['team_name'] ?? '-') ?></dd></div>
        <div><dt>Aktif sejak</dt><dd><?= e(date('d-m-Y', strtotime((string) $member['active_from']))) ?></dd></div>
        <div><dt>Status</dt><dd><?= $member['status'] === 'AKTIF' ? 'Aktif' : 'Nonaktif' ?><?= $member['is_manager'] ? ' · Pengelola' : '' ?></dd></div>
        <?php if (!empty($canManage) && $member['reserve_exempt']): ?><div><dt>Cadangan</dt><dd>Dikecualikan dari potongan cadangan</dd></div><?php endif; ?>
        <?php if (!empty($canManage) && !empty($member['notes'])): ?><div><dt>Catatan</dt><dd><?= e($member['notes']) ?></dd></div><?php endif; ?>
    </dl>
    <div class="stats">
        <div class="stat">
            <span class="stat__label">Saldo tabungan</span>
            <strong class="stat__value"><?= e(money((int) $member['savings_balance'])) ?></strong>
            <span class="stat__hint">Hanya transaksi yang disetujui</span>
        </div>
    </div>
</section>

<section class="card">
    <h2 class="card__title">Pinjaman</h2>
    <?php if ($loans === []): ?>
        <p class="muted">Tidak ada pinjaman.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--stack">
                <thead><tr><th>No. Pinjaman</th><th class="num">Pokok</th><th class="num">Total tagihan</th><th class="num">Terbayar</th><th class="num">Sisa</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($loans as $l): ?>
                    <tr>
                        <td data-label="No. Pinjaman"><?= !empty($canTransactions) ? '<a href="' . e(url('/transaksi/' . (int) $l['transaction_id'])) . '">' . e($l['loan_no']) . '</a>' : e($l['loan_no']) ?></td>
                        <td data-label="Pokok" class="num"><?= e(money((int) $l['principal'])) ?></td>
                        <td data-label="Total tagihan" class="num"><?= e(money((int) $l['total_due'])) ?></td>
                        <td data-label="Terbayar" class="num"><?= e(money((int) $l['paid'])) ?></td>
                        <td data-label="Sisa" class="num"><?= e(money((int) $l['outstanding'])) ?></td>
                        <td data-label="Status"><?= e($l['loan_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if (count($history) > 1 || (count($history) === 1 && $history[0]['valid_to'] !== null)): ?>
<section class="card">
    <h2 class="card__title">Riwayat regu</h2>
    <ul class="timeline">
        <?php foreach ($history as $h): ?>
            <li><strong><?= e($h['team_name']) ?></strong> <span class="muted">· <?= e(date('d-m-Y', strtotime((string) $h['valid_from']))) ?> s/d <?= $h['valid_to'] === null ? 'sekarang' : e(date('d-m-Y', strtotime((string) $h['valid_to']))) ?></span></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
