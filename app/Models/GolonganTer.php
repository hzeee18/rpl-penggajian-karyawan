<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GolonganTer extends Model
{
    protected $table = 'golongan_ter';
    protected $fillable = ['kode', 'nama', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function tarif()
    {
        return $this->hasMany(TarifTer::class)->orderBy('lapisan');
    }

    public function pajak()
    {
        return $this->hasMany(Pajak::class);
    }

    /** Lapisan tarif yang berlaku pada tanggal tertentu. */
    public function lapisanBerlaku(string $tanggal)
    {
        return $this->tarif()->where('berlaku_mulai', '<=', $tanggal)
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $tanggal))
            ->get();
    }
}
