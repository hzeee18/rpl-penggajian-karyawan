<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Riwayat bruto kena pajak dan PPh21 per bulan (bahan perhitungan Desember di fase 2). */
final class TaxMonthlyHistory extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_tax_monthly_histories';

    protected $fillable = ['employee_id', 'run_id', 'tax_year', 'tax_month', 'ter_category', 'ter_rate', 'gross_taxable', 'tax_deduction', 'pph21'];

    protected function casts(): array
    {
        return ['gross_taxable' => 'decimal:2', 'tax_deduction' => 'decimal:2', 'pph21' => 'decimal:2', 'ter_rate' => 'decimal:6'];
    }
}
