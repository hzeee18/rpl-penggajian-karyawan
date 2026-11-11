<?php

declare(strict_types=1);

namespace App\Domain\Company\Tenancy;

use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Konteks perusahaan aktif (keputusan pemilik 2026-11-11, AUDIT.md §14).
 *
 * Urutan penentuan:
 * 1. Bingkai eksplisit terdalam:
 *    - runAs($id)                  : satu perusahaan (job, perintah artisan per perusahaan, seeder, login).
 *    - lintasPerusahaan($alasan)   : baca SEMUA perusahaan. Hanya untuk migration (otomatis) dan
 *                                    pemanggilan eksplisit yang alasannya ditulis. Create tetap ditolak.
 * 2. User login                    : company_id user (dibaca dengan Auth::hasUser(), tanpa memicu query user).
 * 3. Request web tanpa user        : tidak ada konteks.
 * 4. Fallback                      : hanya dipasang test harness (tests/TestCase.php) = perusahaan DEFAULT.
 * 5. Selain itu (artisan, scheduler, tinker, queue) tanpa konteks: tidak ada konteks.
 *
 * "Tidak ada konteks" = query bertenant kosong dan create ditolak (fail closed).
 * Super Admin (lintas perusahaan lewat UI) ditambahkan di fase 1d.
 */
#[Scoped]
final class CurrentCompany
{
    /** @var list<array{0: 'company', 1: int}|array{0: 'lintas', 1: string}> */
    private array $frames = [];

    private int $httpDepth = 0;

    /** @var (Closure(): ?int)|null */
    private ?Closure $fallback = null;

    public function id(): ?int
    {
        $frame = end($this->frames);
        if ($frame !== false) {
            return $frame[0] === 'company' ? $frame[1] : null;
        }

        if (Auth::hasUser()) {
            $companyId = Auth::user()?->getAttribute('company_id');

            return $companyId === null ? null : (int) $companyId;
        }

        if ($this->httpDepth > 0) {
            return null;
        }

        return $this->fallback !== null ? ($this->fallback)() : null;
    }

    /** True = baca tidak dibatasi perusahaan (hanya di dalam lintasPerusahaan / migration). */
    public function isLintasPerusahaan(): bool
    {
        $frame = end($this->frames);

        return $frame !== false && $frame[0] === 'lintas';
    }

    /**
     * Jalankan dalam konteks satu perusahaan.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(int $companyId, Closure $callback): mixed
    {
        $this->frames[] = ['company', $companyId];

        try {
            return $callback();
        } finally {
            array_pop($this->frames);
        }
    }

    /**
     * Baca lintas perusahaan. WAJIB disertai alasan; pemanggil juga memberi komentar di kode.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function lintasPerusahaan(string $alasan, Closure $callback): mixed
    {
        $this->masukLintas($alasan);

        try {
            return $callback();
        } finally {
            $this->keluarLintas();
        }
    }

    /** Untuk proses yang mulai dan selesainya ditandai event (migration). Pasangkan dengan keluarLintas(). */
    public function masukLintas(string $alasan): void
    {
        if (trim($alasan) === '') {
            throw new InvalidArgumentException('Alasan baca lintas perusahaan wajib diisi.');
        }

        $this->frames[] = ['lintas', $alasan];
    }

    public function keluarLintas(): void
    {
        $frame = end($this->frames);
        if ($frame !== false && $frame[0] === 'lintas') {
            array_pop($this->frames);
        }
    }

    /** Dipanggil middleware TenantRequestContext di awal/akhir setiap request web. */
    public function enterHttpRequest(): void
    {
        $this->httpDepth++;
    }

    public function leaveHttpRequest(): void
    {
        $this->httpDepth = max(0, $this->httpDepth - 1);
    }

    /** @param  (Closure(): ?int)|null  $resolver  dievaluasi saat dibutuhkan (lazy) */
    public function useFallback(?Closure $resolver): void
    {
        $this->fallback = $resolver;
    }
}
