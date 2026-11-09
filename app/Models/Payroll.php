<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    protected $table = 'payroll';
    protected $fillable = ['master_payroll_id', 'periode', 'tgl_generate', 'status'];
    protected $casts = ['tgl_generate' => \App\Casts\Tanggal::class];

    public function master()
    {
        return $this->belongsTo(MasterPayroll::class, 'master_payroll_id');
    }

    public function details()
    {
        return $this->hasMany(PayrollDetail::class);
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    public function approvals()
    {
        return $this->hasMany(Approval::class, 'ref_id')->where('jenis_ref', 'payroll')->latest('id');
    }

    public function bisaDigenerate(): bool
    {
        return in_array($this->status, ['draft', 'ditolak']);
    }

    public function namaPeriode(): string
    {
        return Carbon::createFromFormat('Y-m-d', $this->periode . '-01')->translatedFormat('F Y');
    }
}
