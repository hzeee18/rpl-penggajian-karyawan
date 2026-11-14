<?php

namespace App\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollDetail extends Model
{
    use BelongsToCompany;

    protected $table = 'payroll_detail';
    protected $fillable = ['payroll_id', 'karyawan_id', 'payslip_component_id', 'jumlah', 'nominal'];
    protected $casts = ['jumlah' => 'float', 'nominal' => 'float'];

    public function payroll()
    {
        return $this->belongsTo(Payroll::class);
    }

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function component()
    {
        return $this->belongsTo(PayslipComponent::class, 'payslip_component_id');
    }
}
