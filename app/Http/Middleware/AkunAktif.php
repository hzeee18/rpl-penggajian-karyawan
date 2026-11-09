<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Keluarkan user yang dinonaktifkan / dikunci saat sesinya masih jalan. */
class AkunAktif
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && (! $user->is_active || $user->is_locked)) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['username' => 'Akun Anda tidak aktif atau terkunci. Hubungi Admin.']);
        }

        return $next($request);
    }
}
