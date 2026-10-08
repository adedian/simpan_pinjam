<section class="card">
    <div class="card__head">
        <h2 class="card__title">Riwayat validasi <span class="muted">· <?= (int) $pager['total'] ?></span></h2>
        <a class="btn btn--secondary" href="<?= e(url('/validasi')) ?>">Menunggu validasi</a>
    </div>
    <p class="muted">Keputusan yang diambil lewat aplikasi. Data impor Excel tidak melalui validator, jadi tidak tampil di sini.</p>

    <form class="toolbar" method="get" action="<?= e(url('/validasi/riwayat')) ?>">
        <div class="toolbar__search">
            <label class="sr-only" for="q">Cari</label>
            <input id="q" class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari anggota, nomor dokumen, atau validator" maxlength="60">
        </div>
        <div>
            <label class="sr-only" for="keputusan">Keputusan</label>
            <select id="keputusan" class="input" name="keputusan">
                <option value="">Semua keputusan</option>
                <option value="DISETUJUI"<?= $filters['decision'] === 'DISETUJUI' ? ' selected' : '' ?>>Disetujui</option>
                <option value="DITOLAK"<?= $filters['decision'] === 'DITOLAK' ? ' selected' : '' ?>>Ditolak</option>
            </select>
        </div>
        <div>
            <label class="sr-only" for="jenis">Jenis</label>
            <select id="jenis" class="input" name="jenis">
                <option value="">Semua jenis</option>
                <?php foreach ($types as $code => $label): ?>
                    <option value="<?= e($code) ?>"<?= $filters['type'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn--secondary">Cari</button>
        <?php if ($filters['q'] !== '' || $filters['type'] !== '' || $filters['decision'] !== ''): ?><a class="btn btn--link" href="<?= e(url('/validasi/riwayat')) ?>">Atur ulang</a><?php endif; ?>
    </form>

    <?php if ($rows === []): ?>
        <p class="empty">Belum ada keputusan validasi.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Waktu</th><th>No. dokumen</th><th>Jenis</th><th>Anggota</th><th class="num">Nominal</th><th>Keputusan</th><th>Validator</th><th>Catatan</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td data-label="Waktu" class="nowrap"><?= e(date('d-m-Y H:i', strtotime((string) $r['created_at']))) ?></td>
                    <td data-label="No. dokumen" class="nowrap"><a href="<?= e(url('/transaksi/' . (int) $r['transaction_id'])) ?>"><?= e($r['doc_no']) ?></a></td>
                    <td data-label="Jenis"><?= e($types[$r['type']] ?? $r['type']) ?></td>
                    <td data-label="Anggota"><?= $r['member_name'] !== null ? e($r['member_name']) . ' <span class="muted">· ' . e($r['member_no']) . '</span>' : '<span class="muted">-</span>' ?></td>
                    <td data-label="Nominal" class="num"><?= e(money(($r['reverses_id'] !== null ? -1 : 1) * (int) $r['amount'])) ?></td>
                    <td data-label="Keputusan"><?= status_badge((string) $r['to_status']) ?></td>
                    <td data-label="Validator"><?= e($r['actor_name']) ?><?= $r['creator_name'] !== null ? ' <span class="muted">· pembuat ' . e($r['creator_name']) . '</span>' : '' ?></td>
                    <td data-label="Catatan"><?= $r['note'] !== null && $r['note'] !== '' ? e($r['note']) : '<span class="muted">-</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= \App\Core\View::include('partials/pagination', ['pager' => $pager, 'basePath' => '/validasi/riwayat', 'query' => ['q' => $filters['q'], 'jenis' => $filters['type'], 'keputusan' => $filters['decision']]]) ?>
    <?php endif; ?>
</section>
