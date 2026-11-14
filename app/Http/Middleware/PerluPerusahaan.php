<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Company\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pengaman halaman data master perusahaan: bila diakses akun platform (Super Admin) tanpa perusahaan terpilih,
 * arahkan ke menu Perusahaan. Sejak AUDIT.md §40 Super Admin tidak punya hak menu ini (dikelola Admin perusahaan),
 * jadi lapisan ini hanya cadangan bila hak diberikan lagi. User perusahaan tidak terpengaruh.
 */
final class PerluPerusahaan
{
    public function handle(Request $request, Closure $next): Response
    {
        $konteks = app(CurrentCompany::class);
        if ($konteks->isSuperAdmin() && $konteks->id() === null) {
            return redirect()->route('perusahaan.index')
                ->with('gagal', 'Pilih perusahaan dulu (tombol Pilih) sebelum mengelola data master perusahaan.');
        }

        return $next($request);
    }
}
