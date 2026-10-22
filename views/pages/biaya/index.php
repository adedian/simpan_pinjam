<?php
/** Daftar biaya operasional (Kas SHU). Variabel: $rows, $sum, $me (id pengguna). */
?>
<section class="card">
    <div class="card__head">
        <h2 class="card__title">Biaya <span class="muted">· <?= count($rows) ?></span></h2>
        <a class="btn btn--primary" href="<?= e(url('/biaya/baru')) ?>">Catat biaya</a>
    </div>
    <p class="muted">Biaya operasional (buku tabungan, cetak laporan, dan sejenisnya) dibebankan ke Kas SHU. Biaya baru memengaruhi kas dan Kas SHU setelah disetujui di Validasi; draft dan yang menunggu belum dihitung.</p>

    <div class="stats stats--report">
        <div class="stat"><span class="stat__label">Disetujui</span><strong class="stat__value"><?= e(money((int) $sum['DISETUJUI'])) ?></strong><span class="stat__hint">Sudah mengurangi Kas SHU</span></div>
        <div class="stat"><span class="stat__label">Menunggu validasi</span><strong class="stat__value"><?= e(money((int) $sum['MENUNGGU_VALIDASI'])) ?></strong><span class="stat__hint">Belum dihitung</span></div>
        <div class="stat"><span class="stat__label">Draft</span><strong class="stat__value"><?= e(money((int) $sum['DRAFT'])) ?></strong><span class="stat__hint">Belum diajukan</span></div>
    </div>

    <?php if ($rows === []): ?>
        <p class="empty">Belum ada biaya yang dicatat.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Tanggal</th><th>Dokumen</th><th>Keperluan</th><th class="num">Nominal</th><th>Status</th><th>Dicatat oleh</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $mine = (int) $r['created_by'] === $me && $r['source'] === 'APLIKASI'; ?>
                <tr>
                    <td data-label="Tanggal"><?= e(date_id((string) $r['trx_date'])) ?></td>
                    <td data-label="Dokumen"><a href="<?= e(url('/transaksi/' . (int) $r['id'])) ?>"><?= e($r['doc_no']) ?></a></td>
                    <td data-label="Keperluan"><strong><?= e($r['category']) ?></strong><?= $r['description'] !== null && $r['description'] !== '' && $r['description'] !== $r['category'] ? '<br><span class="muted">' . e((string) $r['description']) . '</span>' : '' ?></td>
                    <td data-label="Nominal" class="num"><?= e(money((int) $r['amount'])) ?></td>
                    <td data-label="Status"><?= status_badge((string) $r['status']) ?></td>
                    <td data-label="Dicatat oleh"><?= $r['creator_name'] !== null ? e((string) $r['creator_name']) : '<span class="muted">' . ($r['source'] === 'IMPOR_EXCEL' ? 'impor Excel' : '-') . '</span>' ?></td>
                    <td class="cell-actions">
                        <?php if ($mine && $r['status'] === 'DRAFT'): ?>
                            <a class="btn btn--secondary btn--sm" href="<?= e(url('/biaya/' . (int) $r['id'] . '/ubah')) ?>">Ubah</a>
                            <form class="inline" method="post" action="<?= e(url('/biaya/' . (int) $r['id'] . '/ajukan')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_version" value="<?= e((string) $r['updated_at']) ?>">
                                <button type="submit" class="btn btn--primary btn--sm" data-confirm="Ajukan biaya ini ke validasi? Setelah diajukan, nominalnya tidak bisa diubah lagi.">Ajukan</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($mine && in_array($r['status'], ['DRAFT', 'MENUNGGU_VALIDASI'], true)): ?>
                            <details class="disclosure">
                                <summary class="btn btn--link btn--sm">Batalkan</summary>
                                <form class="disclosure__body" method="post" action="<?= e(url('/biaya/' . (int) $r['id'] . '/batal')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_version" value="<?= e((string) $r['updated_at']) ?>">
                                    <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'note', 'label' => 'Alasan pembatalan', 'required' => true, 'maxlength' => 200, 'hint' => 'Nomor dokumen dan riwayatnya tetap tersimpan.']]) ?>
                                    <div><button type="submit" class="btn btn--danger" data-confirm="Batalkan biaya ini? Tindakan ini tidak bisa diurungkan.">Ya, batalkan</button></div>
                                </form>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
