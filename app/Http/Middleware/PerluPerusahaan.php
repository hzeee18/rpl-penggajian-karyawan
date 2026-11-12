<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Company\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Data master per perusahaan yang dikelola Super Admin (AUDIT.md §34): Super Admin harus memilih perusahaan
 * dulu di menu Perusahaan, karena datanya milik satu perusahaan. User perusahaan tidak terpengaruh.
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
