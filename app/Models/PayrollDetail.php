<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollDetail extends Model
{
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
