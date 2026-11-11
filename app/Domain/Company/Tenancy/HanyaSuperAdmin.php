<?php

declare(strict_types=1);

namespace App\Domain\Company\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman platform hanya untuk Super Admin, terlepas dari isi tabel privileges
 * (lapisan kedua: hak menu platform tidak bisa diberikan ke role perusahaan).
 */
final class HanyaSuperAdmin
{
    public function __construct(private readonly CurrentCompany $konteks) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->konteks->isSuperAdmin(), 403, 'Halaman ini khusus Super Admin.');

        return $next($request);
    }
}
