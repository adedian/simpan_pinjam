<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;
use App\Services\MemberLookup;

/**
 * Pencarian anggota untuk kotak pilih anggota. Hak akses dan himpunan anggota ditentukan di MemberLookup (SQL),
 * jadi mengubah parameter di browser tidak bisa memperluas hasil. Hanya nama, nomor, dan regu yang dikembalikan.
 */
final class MemberLookupController extends BaseController
{
    /** @param array<string,string> $params */
    public function search(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $context = $request->queryStr('konteks');
        if (!in_array($context, MemberLookup::CONTEXTS, true) || !MemberLookup::allowed($user, $context)) {
            return Response::json(['error' => 'Tidak berhak mencari anggota.'], 403)->withHeader('Cache-Control', 'no-store');
        }
        $opts = ['team' => (int) $request->queryStr('regu'), 'include' => (int) $request->queryStr('termasuk')];

        $result = MemberLookup::search(
            $user,
            $context,
            $request->queryStr('q'),
            $opts,
            (int) $request->queryStr('offset'),
            (int) ($request->queryStr('limit', '20')),
        );
        return Response::json($result)->withHeader('Cache-Control', 'no-store');
    }
}
