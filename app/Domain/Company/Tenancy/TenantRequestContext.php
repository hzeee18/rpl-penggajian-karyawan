<?php

declare(strict_types=1);

namespace App\Domain\Company\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menandai bahwa kode berjalan di dalam request web, sehingga tanpa user login
 * query bertenant kosong (fail closed), bukan mode sistem console.
 * Dipasang PALING DEPAN di grup web agar route model binding juga ikut terbatasi.
 */
final class TenantRequestContext
{
    public function __construct(private readonly CurrentCompany $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->enterHttpRequest();

        try {
            return $next($request);
        } finally {
            $this->context->leaveHttpRequest();
        }
    }
}
