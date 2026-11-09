<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cek hak akses role terhadap menu (tabel privileges).
 * Pemakaian di route: ->middleware('akses:karyawan,create')
 */
class CekAkses
{
    public function handle(Request $request, Closure $next, string $menu, string $aksi = 'view'): Response
    {
        $user = $request->user();
        if (! $user || ! $user->bisa($menu, $aksi)) {
            abort(403, 'Anda tidak punya akses ke halaman ini.');
        }

        return $next($request);
    }
}
