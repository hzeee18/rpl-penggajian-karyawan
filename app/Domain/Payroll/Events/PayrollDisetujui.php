<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Events;

/** Payroll disetujui Manager. Pendengar (Fase 7) menerbitkan slip dan PDF-nya. Membawa konteks perusahaan. */
final readonly class PayrollDisetujui
{
    public function __construct(public int $runId, public int $companyId) {}
}
