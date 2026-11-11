<?php

namespace App\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Payslip extends Model
{
    use BelongsToCompany;

    protected $table = 'payslip';
    protected $fillable = ['payroll_id', 'karyawan_id', 'total_bruto', 'total_potongan', 'thp', 'file_pdf'];
    protected $casts = ['total_bruto' => 'float', 'total_potongan' => 'float', 'thp' => 'float'];

    public function payroll()
    {
        return $this->belongsTo(Payroll::class);
    }

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    /** Rincian komponen slip ini. */
    public function details()
    {
        return PayrollDetail::with('component')
            ->where('payroll_id', $this->payroll_id)
            ->where('karyawan_id', $this->karyawan_id)
            ->get();
    }
}
