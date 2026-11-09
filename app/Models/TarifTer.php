<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TarifTer extends Model
{
    protected $table = 'tarif_ter';
    protected $fillable = ['golongan_ter_id', 'batas_bawah', 'batas_atas', 'persen', 'lapisan', 'berlaku_mulai', 'berlaku_sampai', 'dasar_hukum'];
    protected $casts = ['berlaku_mulai' => \App\Casts\Tanggal::class, 'berlaku_sampai' => \App\Casts\Tanggal::class, 'persen' => 'float', 'batas_bawah' => 'float', 'batas_atas' => 'float'];

    public function golongan()
    {
        return $this->belongsTo(GolonganTer::class, 'golongan_ter_id');
    }

    /** Persen tarif TER untuk golongan, bruto, dan tanggal periode. */
    public static function tarifBerlaku(int $golonganId, float $bruto, string $tanggal): float
    {
        $tarif = static::where('golongan_ter_id', $golonganId)
            ->where('berlaku_mulai', '<=', $tanggal)
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $tanggal))
            ->where('batas_bawah', '<=', $bruto)
            ->where(fn ($q) => $q->whereNull('batas_atas')->orWhere('batas_atas', '>=', $bruto))
            ->orderByDesc('lapisan')
            ->first();

        return $tarif ? (float) $tarif->persen : 0.0;
    }
}
