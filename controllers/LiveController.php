<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;
use App\Services\LiveFeed;

/**
 * Denyut pembaruan langsung. Mengembalikan penanda perubahan (sama untuk semua) dan penanda jumlah menu
 * milik pengguna ini ("b"; angka saja). Tidak ada data keuangan.
 */
final class LiveController extends BaseController
{
    /** @param array<string,string> $params */
    public function tick(Request $request, array $params = []): Response
    {
        return Response::json(['v' => LiveFeed::version(), 'b' => (object) LiveFeed::badges(Auth::user())]);
    }
}
