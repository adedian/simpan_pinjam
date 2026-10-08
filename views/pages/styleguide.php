<?php /** @var array<string,string> $allRoles */ /** @var string $activeRole */ ?>
<section class="card">
    <h2 class="card__title">Pratinjau menu per peran</h2>
    <p class="muted">Menu dibangun dari <code>config/menu.php</code> dan <code>config/permissions.php</code>. Item bertanda P<em>n</em> belum dibangun (aktif pada phase tersebut).</p>
    <div class="chips">
        <?php foreach ($allRoles as $key => $label): ?>
            <a class="chip<?= $key === $activeRole ? ' is-active' : '' ?>" href="<?= e(url('/styleguide?peran=' . $key)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</section>

<section class="card">
    <h2 class="card__title">Angka uang</h2>
    <p class="muted">Seluruh nominal berupa bilangan bulat rupiah, rata kanan, angka tabular. Contoh format saja, bukan data koperasi.</p>
    <div class="stats">
        <div class="stat">
            <span class="stat__label">Saldo Global</span>
            <strong class="stat__value"><?= e(money(1234567)) ?></strong>
            <span class="stat__hint">Hanya transaksi berstatus Disetujui</span>
        </div>
        <div class="stat">
            <span class="stat__label">Kas Tersedia</span>
            <strong class="stat__value"><?= e(money(0)) ?></strong>
        </div>
        <div class="stat">
            <span class="stat__label">Selisih</span>
            <strong class="stat__value stat__value--negative"><?= e(money(-50000)) ?></strong>
        </div>
    </div>
</section>

<section class="card">
    <h2 class="card__title">Status transaksi</h2>
    <p class="badges">
        <?php foreach (['DRAFT', 'MENUNGGU_VALIDASI', 'DISETUJUI', 'DITOLAK', 'DIBATALKAN'] as $s): ?>
            <?= status_badge($s) ?>
        <?php endforeach; ?>
    </p>
</section>

<section class="card">
    <h2 class="card__title">Tombol dan peringatan</h2>
    <p class="actions">
        <button type="button" class="btn btn--primary">Setujui</button>
        <button type="button" class="btn btn--secondary">Simpan draft</button>
        <button type="button" class="btn btn--danger">Tolak</button>
        <button type="button" class="btn btn--primary" disabled>Nonaktif</button>
    </p>
    <div class="alert alert--success" role="status">Transaksi berhasil disetujui.</div>
    <div class="alert alert--warning" role="status">Ada 3 transaksi menunggu validasi.</div>
    <div class="alert alert--danger" role="alert">Kas tidak mencukupi untuk pencairan ini.</div>
</section>

<section class="card">
    <h2 class="card__title">Formulir</h2>
    <div class="form">
        <div class="field">
            <label for="sg-nominal">Nominal</label>
            <input id="sg-nominal" class="input input--money" type="text" inputmode="numeric" placeholder="0" autocomplete="off">
            <small class="field__hint">Tanpa desimal. Contoh: 1.000.000</small>
        </div>
        <div class="field">
            <label for="sg-ket">Keterangan</label>
            <input id="sg-ket" class="input" type="text" placeholder="Opsional">
        </div>
        <div class="field field--error">
            <label for="sg-err">Tenor</label>
            <select id="sg-err" class="input"><option>Pilih tenor</option><option>1 bulan</option><option>5 bulan</option></select>
            <small class="field__error">Tenor wajib dipilih.</small>
        </div>
    </div>
</section>

<section class="card">
    <h2 class="card__title">Tabel</h2>
    <p class="muted">Di layar kecil baris berubah menjadi kartu bertumpuk (label diambil dari atribut <code>data-label</code>).</p>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead>
                <tr><th>No. Transaksi</th><th>Anggota</th><th class="num">Nominal</th><th>Status</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td data-label="No. Transaksi">TRX-2026-000001</td>
                    <td data-label="Anggota">Contoh Anggota A</td>
                    <td data-label="Nominal" class="num"><?= e(money(1000000)) ?></td>
                    <td data-label="Status"><?= status_badge('DISETUJUI') ?></td>
                </tr>
                <tr>
                    <td data-label="No. Transaksi">TRX-2026-000002</td>
                    <td data-label="Anggota">Contoh Anggota B</td>
                    <td data-label="Nominal" class="num"><?= e(money(250000)) ?></td>
                    <td data-label="Status"><?= status_badge('MENUNGGU_VALIDASI') ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</section>
