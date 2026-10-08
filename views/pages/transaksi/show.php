<?php
$isSaving   = $trx['type'] === 'SIMPANAN';
$isPayment  = $trx['type'] === 'ANGSURAN' && $allocations !== null;
$isLoan     = $trx['type'] === 'PENCAIRAN_PINJAMAN' && $loanInfo !== null && $loanInfo['loan'] !== null;
$isCounted  = $trx['status'] === 'DISETUJUI';
$isReversal = $trx['reverses_id'] !== null;
$statusText = static fn (string $s): string => strip_tags(status_badge($s));
?>
<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= e($types[$trx['type']] ?? $trx['type']) ?> <span class="muted">· <?= e($trx['doc_no']) ?></span> <?= status_badge((string) $trx['status']) ?></h2>
        <?php if ($canAct && $trx['status'] === 'DRAFT'): ?>
            <a class="btn btn--secondary" href="<?= e(url($editPath)) ?>">Ubah</a>
        <?php endif; ?>
    </div>

    <?php $liveRev = $reversal['live'] ?? null; ?>
    <?php if ($isCounted && $liveRev !== null && $liveRev['status'] === 'DISETUJUI'): ?>
        <div class="alert alert--warning" role="note">Transaksi ini <strong>sudah dibalik</strong> oleh <a href="<?= e(url('/transaksi/' . (int) $liveRev['id'])) ?>"><?= e($liveRev['doc_no']) ?></a>. Pengaruhnya ke saldo sudah dihapus; catatannya tetap tersimpan.</div>
    <?php elseif ($isCounted && $liveRev !== null): ?>
        <div class="alert alert--warning" role="note">Koreksi <a href="<?= e(url('/transaksi/' . (int) $liveRev['id'])) ?>"><?= e($liveRev['doc_no']) ?></a> sedang menunggu validasi. Transaksi ini tetap dihitung dalam saldo sampai pembaliknya disetujui.</div>
    <?php elseif ($isCounted): ?>
        <div class="alert alert--success" role="note"><?= $isReversal ? 'Transaksi pembalik ini sudah disetujui dan mengurangi saldo.' : 'Transaksi ini sudah disetujui dan dihitung dalam saldo.' ?></div>
    <?php elseif (in_array($trx['status'], ['DRAFT', 'MENUNGGU_VALIDASI'], true)): ?>
        <div class="alert alert--warning" role="note">Belum memengaruhi saldo<?= $trx['status'] === 'DRAFT' ? ' sampai diajukan dan disetujui.' : ' sampai disetujui validator.' ?></div>
    <?php else: ?>
        <div class="alert alert--warning" role="note">Transaksi ini <?= $trx['status'] === 'DITOLAK' ? 'ditolak' : 'dibatalkan' ?> dan tidak dihitung dalam saldo.</div>
    <?php endif; ?>

    <dl class="dl">
        <div><dt>Anggota</dt><dd>
            <?php if ($trx['member_id'] !== null): ?>
                <a href="<?= e(url('/anggota/' . (int) $trx['member_id'])) ?>"><?= e($trx['member_name']) ?></a> <span class="muted">· <?= e($trx['member_no']) ?></span>
            <?php else: ?>-<?php endif; ?>
        </dd></div>
        <div><dt>Regu saat dicatat</dt><dd><?= e($trx['team_name'] ?? '-') ?></dd></div>
        <?php if ($isSaving): ?><div><dt>Jenis simpanan</dt><dd><?= e($kinds[$trx['kind']] ?? $trx['kind']) ?></dd></div><?php endif; ?>
        <div><dt><?= $isLoan ? 'Pokok pinjaman' : ($isPayment ? 'Jumlah dibayar' : 'Nominal') ?></dt><dd><strong><?= e(money(($isReversal ? -1 : 1) * (int) $trx['amount'])) ?></strong></dd></div>
        <div><dt><?= $isLoan ? 'Bulan pencairan' : ($isPayment ? 'Bulan pembayaran' : 'Bulan setoran') ?></dt><dd><?= e(month_label((string) $trx['month_date'])) ?></dd></div>
        <?php if ($isLoan): $ln = $loanInfo['loan']; ?>
        <div><dt>Tenor</dt><dd><?= (int) $ln['tenor_months'] ?> bulan</dd></div>
        <div><dt>Bunga</dt><dd><?= e(number_format((float) $ln['rate_pct_month'], 2, ',', '.')) ?>% per bulan flat = <strong><?= e(money((int) $ln['total_interest'])) ?></strong> (dibayar di muka)</dd></div>
        <div><dt>Total tagihan</dt><dd><strong><?= e(money((int) $ln['principal'] + (int) $ln['total_interest'])) ?></strong></dd></div>
        <?php if ($loanInfo['balance'] !== null): ?>
        <div><dt>Terbayar</dt><dd><?= e(money((int) $loanInfo['balance']['paid'])) ?></dd></div>
        <div><dt>Sisa tagihan</dt><dd><strong><?= e(money((int) $loanInfo['balance']['outstanding'])) ?></strong> <?= $loanInfo['balance']['loan_status'] === 'LUNAS' ? '<span class="badge badge--disetujui">Lunas</span>' : '' ?></dd></div>
        <?php endif; ?>
        <?php endif; ?>
        <div><dt>Tanggal</dt><dd><?= e(date_id((string) $trx['trx_date'])) ?></dd></div>
        <?php if (!empty($trx['description'])): ?><div><dt>Keterangan</dt><dd><?= e($trx['description']) ?></dd></div><?php endif; ?>
        <?php if ($isReversal): ?><div><dt>Membalik</dt><dd><a href="<?= e(url('/transaksi/' . (int) $trx['reverses_id'])) ?>"><?= e($trx['reverses_doc_no']) ?></a></dd></div><?php endif; ?>
        <div><dt>Nomor transaksi</dt><dd><?= e($trx['trx_no']) ?></dd></div>
        <div><dt>Asal data</dt><dd><?= $trx['source'] === 'IMPOR_EXCEL' ? 'Impor Excel (belum melalui validator)' : 'Aplikasi' ?></dd></div>
    </dl>

    <?php if ($canAct && in_array($trx['status'], ['DRAFT', 'MENUNGGU_VALIDASI'], true)): ?>
        <div class="actions">
            <?php if ($trx['status'] === 'DRAFT'): ?>
                <form class="inline" method="post" action="<?= e(url('/transaksi/' . (int) $trx['id'] . '/ajukan')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="_version" value="<?= e($trx['updated_at']) ?>">
                    <button type="submit" class="btn btn--primary" data-confirm="Ajukan <?= $isLoan ? 'pinjaman' : ($isPayment ? 'pembayaran' : 'simpanan') ?> ini ke validasi? Setelah diajukan, <?= $isLoan ? 'jumlah dan jadwalnya' : ($isPayment ? 'jumlah dan pembagiannya' : 'nominalnya') ?> tidak bisa diubah lagi.">Ajukan ke validasi</button>
                </form>
            <?php endif; ?>
            <details class="disclosure">
                <summary class="btn btn--danger">Batalkan transaksi</summary>
                <form class="disclosure__body" method="post" action="<?= e(url('/transaksi/' . (int) $trx['id'] . '/batal')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="_version" value="<?= e($trx['updated_at']) ?>">
                    <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'note', 'label' => 'Alasan pembatalan', 'required' => true, 'maxlength' => 200, 'hint' => 'Nomor dokumen dan riwayatnya tetap tersimpan; transaksi tidak dihapus.']]) ?>
                    <div><button type="submit" class="btn btn--danger" data-confirm="Batalkan transaksi ini? Tindakan ini tidak bisa diurungkan.">Ya, batalkan</button></div>
                </form>
            </details>
        </div>
    <?php endif; ?>
</section>

<?php if ($isLoan): ?>
<section class="card">
    <h2 class="card__title">Jadwal cicilan</h2>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Cicilan ke</th><th>Jatuh tempo</th><th class="num">Tagihan</th><?php if ($loanInfo['balance'] !== null): ?><th class="num">Terbayar</th><th class="num">Sisa</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($loanInfo['installments'] as $in): ?>
                <tr>
                    <td data-label="Cicilan ke"><?= (int) $in['seq'] ?></td>
                    <td data-label="Jatuh tempo"><?= e(month_label((string) $in['due_month'])) ?></td>
                    <td data-label="Tagihan" class="num"><?= e(money((int) $in['amount_due'])) ?></td>
                    <?php if ($loanInfo['balance'] !== null): ?>
                    <td data-label="Terbayar" class="num"><?= e(money((int) $in['paid_amount'])) ?></td>
                    <td data-label="Sisa" class="num"><?= (int) $in['remaining_amount'] > 0 ? e(money((int) $in['remaining_amount'])) : '<span class="muted">Lunas</span>' ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!$isCounted): ?><p class="muted">Jadwal ini baru berlaku setelah pencairan disetujui.</p><?php endif; ?>
</section>

<?php endif; ?>
<?php if ($isPayment): ?>
<section class="card">
    <h2 class="card__title">Pembagian ke cicilan</h2>
    <p class="muted">Dibagikan otomatis ke cicilan paling tua dulu, tanpa denda. Boleh sebagian dan boleh melunasi lebih awal.<?= $trx['status'] === 'DRAFT' ? ' Pembagian dihitung ulang saat diajukan, memakai keadaan terbaru.' : '' ?></p>
    <?php if ($allocations === []): ?>
        <p class="empty">Tidak ada pembagian.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th>Pinjaman</th><th>Cicilan ke</th><th>Jatuh tempo</th><th class="num">Dibayar</th></tr></thead>
            <tbody>
            <?php foreach ($allocations as $al): ?>
                <tr>
                    <td data-label="Pinjaman"><a href="<?= e(url('/transaksi/' . (int) $al['loan_transaction_id'])) ?>"><?= e($al['loan_no']) ?></a></td>
                    <td data-label="Cicilan ke"><?= (int) $al['seq'] ?></td>
                    <td data-label="Jatuh tempo"><?= e(month_label((string) $al['due_month'])) ?></td>
                    <td data-label="Dibayar" class="num"><?= e(money((int) $al['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th colspan="3">Jumlah</th><td class="num"><strong><?= e(money(array_sum(array_map('intval', array_column($allocations, 'amount'))))) ?></strong></td></tr></tfoot>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php endif; ?>
<?php if ($validation !== null): $vd = $validation['verdict']; ?>
<section class="card" id="validasi">
    <h2 class="card__title">Validasi</h2>
    <?php if ($vd['head_related']): ?>
        <div class="alert alert--warning" role="note">Transaksi ini milik Head, regu Head, atau dibuat Head: <strong>hanya Pemeriksa</strong> yang boleh memvalidasi.</div>
    <?php endif; ?>
    <?php if ($validation['noChecker']): ?>
        <div class="alert alert--danger" role="alert">Belum ada akun Pemeriksa yang aktif, jadi transaksi ini tidak bisa divalidasi siapa pun. Head perlu membuat akun Pemeriksa di Data Pengguna.</div>
    <?php endif; ?>
    <?php if ($isReversal && $validation['impact'] !== null): $im = $validation['impact']; $cashAfter = $validation['cash'] + $im['cash_delta']; ?>
        <div class="alert alert--warning" role="note">Ini <strong>transaksi pembalik</strong> untuk <a href="<?= e(url('/transaksi/' . (int) $trx['reverses_id'])) ?>"><?= e($trx['reverses_doc_no']) ?></a>. Alasan koreksi: <?= e((string) ($trx['description'] ?? '-')) ?></div>
        <dl class="dl">
            <div><dt>Kas tersedia sekarang</dt><dd><?= e(money($validation['cash'])) ?></dd></div>
            <div><dt>Perubahan kas</dt><dd><?= e(($im['cash_delta'] >= 0 ? '+' : '') . money($im['cash_delta'])) ?></dd></div>
            <div><dt>Kas sesudahnya</dt><dd><strong><?= e(money($cashAfter)) ?></strong> <?= $cashAfter < 0 ? '<span class="badge badge--ditolak">Kas tidak cukup</span>' : '' ?></dd></div>
            <?php if ($im['savings_delta'] !== 0 && $im['member_savings'] !== null): $savAfter = $im['member_savings'] + $im['savings_delta']; ?>
            <div><dt>Saldo tabungan anggota</dt><dd><?= e(money($im['member_savings'])) ?> &rarr; <strong><?= e(money($savAfter)) ?></strong> <?= $savAfter < 0 ? '<span class="badge badge--ditolak">Saldo negatif</span>' : '' ?></dd></div>
            <?php endif; ?>
            <?php if ($trx['type'] === 'PENCAIRAN_PINJAMAN'): ?>
            <div><dt>Akibat</dt><dd>Pinjaman tidak lagi berlaku: sisa tagihan dan bunga yang dibukukan dihapus dari saldo.</dd></div>
            <?php endif; ?>
        </dl>
    <?php elseif ($validation['cash'] !== null && $trx['type'] === 'PENCAIRAN_PINJAMAN'): $after = $validation['cash'] - (int) $trx['amount']; ?>
        <dl class="dl">
            <div><dt>Kas tersedia</dt><dd><?= e(money($validation['cash'])) ?></dd></div>
            <div><dt>Pencairan</dt><dd><?= e(money((int) $trx['amount'])) ?></dd></div>
            <div><dt>Kas sesudahnya</dt><dd><strong><?= e(money($after)) ?></strong> <?= $after < 0 ? '<span class="badge badge--ditolak">Kas tidak cukup</span>' : '' ?></dd></div>
        </dl>
    <?php endif; ?>

    <?php if (!$vd['eligible']): ?>
        <div class="alert alert--warning" role="note">Anda tidak bisa memvalidasi transaksi ini. <?= e((string) $vd['reason']) ?></div>
    <?php else: ?>
        <p class="muted">Periksa isi transaksi di atas. Setelah <strong>disetujui</strong>, transaksi langsung dihitung dalam saldo dan tidak bisa diubah. Yang <strong>ditolak</strong> final; pembuatnya harus mencatat ulang.</p>
        <div class="actions">
            <form class="inline" method="post" action="<?= e(url('/validasi/' . (int) $trx['id'] . '/setujui')) ?>">
                <?= csrf_field() ?><input type="hidden" name="_version" value="<?= e($trx['updated_at']) ?>">
                <button type="submit" class="btn btn--primary" data-confirm="<?= $isReversal ? 'Setujui pembalik ' . e($trx['doc_no']) . ' sebesar ' . e(money((int) $trx['amount'])) . '? Setelah disetujui, pengaruh transaksi asal dihapus dari saldo dan tidak bisa diurungkan.' : 'Setujui ' . e($trx['doc_no']) . ' sebesar ' . e(money((int) $trx['amount'])) . '? Setelah disetujui, transaksi masuk saldo dan tidak bisa diubah.' ?>">Setujui</button>
            </form>
            <details class="disclosure">
                <summary class="btn btn--danger">Tolak</summary>
                <form class="disclosure__body" method="post" action="<?= e(url('/validasi/' . (int) $trx['id'] . '/tolak')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="_version" value="<?= e($trx['updated_at']) ?>">
                    <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'note', 'label' => 'Alasan penolakan', 'required' => true, 'maxlength' => 200, 'hint' => 'Akan terlihat oleh pembuat transaksi.']]) ?>
                    <div><button type="submit" class="btn btn--danger" data-confirm="Tolak transaksi ini? Keputusan ini final.">Ya, tolak</button></div>
                </form>
            </details>
        </div>
    <?php endif; ?>
</section>

<?php endif; ?>
<?php if (($reversal['attempts'] ?? []) !== [] || ($reversal['can'] ?? false) || ($reversal['blocked'] ?? null) !== null): ?>
<section class="card" id="koreksi">
    <h2 class="card__title">Koreksi</h2>
    <?php if ($reversal['attempts'] !== []): ?>
        <ul class="timeline">
            <?php foreach ($reversal['attempts'] as $at): ?>
                <li>
                    <a href="<?= e(url('/transaksi/' . (int) $at['id'])) ?>"><strong><?= e($at['doc_no']) ?></strong></a> <?= status_badge((string) $at['status']) ?>
                    <span class="muted">· <?= e(date('d-m-Y H:i', strtotime((string) $at['created_at']))) ?><?= $at['creator_name'] !== null ? ' · oleh ' . e($at['creator_name']) : '' ?></span>
                    <?php if (!empty($at['description'])): ?><br><span class="muted">Alasan: <?= e($at['description']) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($reversal['blocked'] !== null): ?>
        <div class="alert alert--warning" role="note">Koreksi belum bisa diajukan. <?= e($reversal['blocked']) ?></div>
    <?php elseif ($reversal['can']): $imp = \App\Services\ReversalService::impact((string) $trx['type'], (int) $trx['amount']); ?>
        <p class="muted">Transaksi yang sudah disetujui tidak bisa diubah atau dihapus. Salah catat dikoreksi dengan <strong>transaksi pembalik</strong>: nominalnya sama persis dan harus disetujui validator. Bila disetujui, kas <strong><?= $imp['cash_delta'] >= 0 ? 'bertambah' : 'berkurang' ?> <?= e(money(abs($imp['cash_delta']))) ?></strong><?= $imp['savings_delta'] !== 0 ? ' dan saldo tabungan anggota <strong>' . ($imp['savings_delta'] > 0 ? 'bertambah ' : 'berkurang ') . e(money(abs($imp['savings_delta']))) . '</strong>' : '' ?>. Untuk koreksi sebagian: balik, lalu catat ulang dengan nominal yang benar.</p>
        <details class="disclosure">
            <summary class="btn btn--secondary">Ajukan koreksi (pembalik)</summary>
            <form class="disclosure__body" method="post" action="<?= e(url('/transaksi/' . (int) $trx['id'] . '/koreksi')) ?>">
                <?= csrf_field() ?>
                <?= \App\Core\View::include('partials/field', ['f' => ['name' => 'note', 'label' => 'Alasan koreksi', 'required' => true, 'maxlength' => 200, 'hint' => 'Contoh: salah anggota, salah nominal. Akan terlihat oleh validator.']]) ?>
                <div><button type="submit" class="btn btn--danger" data-confirm="Ajukan pembalik untuk <?= e($trx['doc_no']) ?>? Transaksi asal tetap berlaku sampai pembalik disetujui.">Ya, ajukan pembalik</button></div>
            </form>
        </details>
    <?php endif; ?>
</section>

<?php endif; ?>
<section class="card">
    <h2 class="card__title">Riwayat status</h2>
    <ul class="timeline">
        <li><strong>Dibuat</strong> <span class="muted">· <?= e(date('d-m-Y H:i', strtotime((string) $trx['created_at']))) ?><?= $trx['creator_name'] !== null ? ' · oleh ' . e($trx['creator_name']) : '' ?></span></li>
        <?php foreach ($validations as $h): ?>
            <li>
                <strong><?= e($statusText((string) $h['to_status'])) ?></strong>
                <span class="muted">· <?= e(date('d-m-Y H:i', strtotime((string) $h['created_at']))) ?> · <?= $h['actor_name'] !== null ? 'oleh ' . e($h['actor_name']) : 'impor historis' ?></span>
                <?php if (!empty($h['note'])): ?><br><span class="muted">Catatan: <?= e($h['note']) ?></span><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
