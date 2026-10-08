<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Acuan komponen visual + pratinjau menu per peran. HANYA aktif bila APP_ENV=local.
 * Pengguna yang ditampilkan adalah contoh, bukan sesi sungguhan, dan tidak membuka data apa pun.
 */
final class StyleguideController extends BaseController
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if (Config::get('app.env') !== 'local') {
            $this->abort(404);
        }

        $roles = array_keys((array) Config::get('permissions.labels', []));
        $role  = $request->str('peran', 'HEAD');
        if (!in_array($role, $roles, true)) {
            $role = 'HEAD';
        }

        return $this->view($request, 'styleguide', [
            'title'      => 'Acuan Gaya',
            'user'       => ['id' => 0, 'name' => 'Contoh Pengguna', 'roles' => [$role], 'member_id' => null, 'team_id' => null],
            'activeRole' => $role,
            'allRoles'   => (array) Config::get('permissions.labels', []),
        ]);
    }
}
