<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KontrakKomponen extends Model
{
    protected $table = 'kontrak_komponen';
    protected $fillable = ['kontrak_id', 'payslip_component_id', 'tipe_nilai', 'nilai', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'nilai' => 'float'];

    public function kontrak()
    {
        return $this->belongsTo(Kontrak::class);
    }

    public function component()
    {
        return $this->belongsTo(PayslipComponent::class, 'payslip_component_id');
    }
}
