<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth;
use App\Services\FormToken;
use App\Services\ProfitConfig;
use App\Services\RuleViolation;

/**
 * Komponen pembagian bunga (nama, persentase, komponen dana tambahan), berlaku mulai bulan tertentu. Hanya Superadmin
 * (izin settings.manage, dipaksa di route). Versi lama tidak pernah diubah; lihat ProfitConfig.
 */
final class ProfitConfigController extends BaseController
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $versions = ProfitConfig::versions();
        $latest   = $versions[count($versions) - 1];
        $min      = ProfitConfig::minEffectiveFrom();
        $next     = date('Y-m-01', strtotime('+1 month'));
        $old      = Session::get('_old', []);
        $fromOld  = isset($old['items']) && is_array($old['items']);

        $rows = [];
        if ($fromOld) {
            foreach (array_values($old['items']) as $r) {
                $rows[] = ['code' => (string) ($r['code'] ?? ''), 'name' => (string) ($r['name'] ?? ''), 'pct' => (string) ($r['pct'] ?? '')];
            }
        } else {
            foreach ($latest['items'] as $i) {
                $rows[] = ['code' => $i['code'], 'name' => $i['name'], 'pct' => rtrim(rtrim(number_format($i['pct'] / 100, 2, ',', ''), '0'), ',')];
            }
            // dua baris dana kosong untuk pengguna tanpa JavaScript (dengan JS dibuang, diganti tombol "Tambah"); baris kosong diabaikan server
            $rows[] = ['code' => '', 'name' => '', 'pct' => ''];
            $rows[] = ['code' => '', 'name' => '', 'pct' => ''];
        }
        return $this->view($request, 'sistem/pembagian-bunga', [
            'title'    => 'Komponen Bunga',
            'scripts'  => ['js/profit-config.js'],
            'versions' => array_reverse($versions),
            'rows'     => $rows,
            'min'      => $min,
            'effective' => $fromOld ? (string) ($old['effective_from'] ?? '') : ($next >= $min ? $next : $min),
            'note'     => $fromOld ? (string) ($old['note'] ?? '') : '',
            'formId'   => FormToken::issue(),
        ]);
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        if (!FormToken::consume($request->str('_form_id'))) {
            Session::flash('warning', 'Formulir ini sudah pernah dikirim, jadi tidak diproses lagi. Periksa riwayat versi di bawah sebelum mengirim ulang.');
            return $this->redirect('/sistem/pembagian-bunga');
        }
        $rows = is_array($request->post['items'] ?? null) ? $request->post['items'] : [];
        [$errors, $items] = ProfitConfig::normalize($rows);

        // Bulan berlaku diisi dengan <input type="month"> (YYYY-MM).
        $month = trim((string) ($request->post['effective_from'] ?? ''));
        $date  = preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month . '-01' : '';
        if ($date === '' || \DateTime::createFromFormat('!Y-m-d', $date) === false || \DateTime::createFromFormat('!Y-m-d', $date)->format('Y-m-d') !== $date) {
            $errors['effective_from'] = 'Bulan berlaku wajib diisi.';
        }
        if ($errors === []) {
            try {
                ProfitConfig::save($request, $user, $date, $items, $request->str('note'), !empty($request->post['confirm_past']));
            } catch (RuleViolation $e) {
                $errors = $e->errors;
            }
        }
        if ($errors !== []) {
            return $this->backWithErrors('/sistem/pembagian-bunga', $errors, ['items' => $rows, 'effective_from' => $month, 'note' => $request->str('note')]);
        }
        Session::flash('success', 'Komponen pembagian bunga disimpan, berlaku mulai ' . month_label($date) . '. Bulan sebelumnya tidak berubah.');
        return $this->redirect('/sistem/pembagian-bunga');
    }
}
