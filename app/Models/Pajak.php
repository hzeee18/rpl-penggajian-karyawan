<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pajak extends Model
{
    protected $table = 'pajak';
    protected $fillable = ['status_ptkp', 'nilai_ptkp', 'golongan_ter_id'];
    protected $casts = ['nilai_ptkp' => 'float'];

    public function golongan()
    {
        return $this->belongsTo(GolonganTer::class, 'golongan_ter_id');
    }

    /** PPh 21 bulanan metode TER: bruto x tarif TER golongan. */
    public function hitungPPh21(float $bruto, string $tanggal): float
    {
        $persen = TarifTer::tarifBerlaku($this->golongan_ter_id, $bruto, $tanggal);

        return round($bruto * $persen / 100);
    }
}
