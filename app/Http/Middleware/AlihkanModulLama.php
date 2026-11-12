<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Payroll\ModulPayroll;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman modul lama yang sudah digantikan modul payroll baru (AUDIT.md §29): bila perusahaan memakai
 * payroll baru, arahkan ke halaman pengganti. Perusahaan yang belum beralih tidak terpengaruh.
 * Pemakaian: ->middleware('modul-lama:kontrak') (kunci dari ModulPayroll::MENU_DIGANTIKAN).
 */
final class AlihkanModulLama
{
    public function handle(Request $request, Closure $next, string $menu): Response
    {
        if (ModulPayroll::aktif()) {
            return redirect()->route(ModulPayroll::MENU_DIGANTIKAN[$menu])
                ->with('sukses', 'Halaman ini sudah diganti modul Payroll Baru.');
        }

        return $next($request);
    }
}
