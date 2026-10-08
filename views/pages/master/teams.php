<section class="card">
    <div class="card__head">
        <h2 class="card__title">Regu <span class="muted">· <?= count($teams) ?></span></h2>
        <a class="btn btn--primary" href="<?= e(url('/master/ketua-regu/baru')) ?>">Tambah regu</a>
    </div>
    <p class="muted">Saldo dan jumlah anggota dihitung dari keanggotaan regu saat ini, hanya dari transaksi yang sudah disetujui.</p>

    <div class="table-wrap">
        <table class="table table--stack">
            <thead>
                <tr><th>Regu</th><th>Ketua</th><th class="num">Anggota</th><th class="num">Saldo tabungan</th><th class="num">Sisa pinjaman</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($teams as $t): ?>
                <tr>
                    <td data-label="Regu"><strong><?= e($t['name']) ?></strong></td>
                    <td data-label="Ketua"><a href="<?= e(url('/anggota/' . (int) $t['leader_member_id'])) ?>"><?= e($t['leader_name']) ?></a> <span class="muted"><?= e($t['leader_no']) ?></span></td>
                    <td data-label="Anggota" class="num"><?= (int) $t['member_count'] ?></td>
                    <td data-label="Saldo tabungan" class="num"><?= e(money((int) $t['savings_total'])) ?></td>
                    <td data-label="Sisa pinjaman" class="num"><?= e(money((int) $t['outstanding_total'])) ?></td>
                    <td data-label="Status"><span class="badge badge--<?= $t['is_active'] ? 'disetujui' : 'draft' ?>"><?= $t['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                    <td class="cell-actions"><a class="btn btn--secondary btn--sm" href="<?= e(url('/master/ketua-regu/' . (int) $t['id'] . '/ubah')) ?>">Ubah</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
