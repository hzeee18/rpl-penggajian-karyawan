<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    protected $table = 'approval';
    protected $fillable = ['approval_mapping_id', 'approver_id', 'diajukan_oleh', 'jenis_ref', 'ref_id', 'level', 'status', 'catatan', 'tgl_approval'];
    protected $casts = ['tgl_approval' => \App\Casts\Tanggal::class];

    public function mapping()
    {
        return $this->belongsTo(ApprovalMapping::class, 'approval_mapping_id');
    }

    public function pengaju()
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** Objek yang di-approve (Kontrak / Payroll). */
    public function ref()
    {
        return match ($this->jenis_ref) {
            'kontrak' => Kontrak::find($this->ref_id),
            'payroll' => Payroll::find($this->ref_id),
            default => null,
        };
    }
}
