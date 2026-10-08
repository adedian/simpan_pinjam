<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth;
use App\Services\SettingsService;

final class SettingController extends BaseController
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $groups = [];
        foreach (SettingsService::DEFINITIONS as $key => $def) {
            $groups[$def['group']][$key] = $def;
        }
        $old = $_SESSION['_old'] ?? [];
        return $this->view($request, 'settings', [
            'title'  => 'Pengaturan',
            'groups' => $groups,
            'values' => $old !== [] ? ($old['s'] ?? []) : SettingsService::values(),
            'fromOld' => $old !== [],
        ]);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $input = is_array($request->post['s'] ?? null) ? $request->post['s'] : [];
        [$errors, $clean] = SettingsService::validate($input);
        if ($errors !== []) {
            return $this->backWithErrors('/sistem/pengaturan', $errors, ['s' => $input]);
        }
        $changed = SettingsService::save($request, Auth::user(), $clean);
        Session::flash('success', $changed === [] ? 'Tidak ada perubahan.' : count($changed) . ' pengaturan disimpan.');
        return $this->redirect('/sistem/pengaturan');
    }
}
