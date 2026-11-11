<?php

namespace App\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Departemen extends Model
{
    use BelongsToCompany;

    public const KODE_HR = 'HRD';

    protected $table = 'departemen';
    protected $fillable = ['kode', 'nama', 'manager_id'];

    public function karyawan()
    {
        return $this->hasMany(Karyawan::class);
    }

    /** Manager utama (yang pertama). */
    public function manager()
    {
        return $this->belongsTo(Karyawan::class, 'manager_id');
    }

    /** Semua manager departemen ini (bisa lebih dari satu). */
    public function managers()
    {
        return $this->belongsToMany(Karyawan::class, 'departemen_manager')->using(DepartemenManager::class)->withTimestamps()->orderBy('nama');
    }

    /** Departemen yang dikelola seorang karyawan (sebagai salah satu manager). */
    public function scopeDikelola($q, ?int $karyawanId)
    {
        return $q->whereHas('managers', fn ($m) => $m->where('karyawan.id', $karyawanId ?? 0));
    }

    /** Simpan daftar manager; manager_id = yang pertama. */
    public function aturManager(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $this->managers()->sync($ids);
        $this->update(['manager_id' => $ids[0] ?? null]);
    }

    /** Nama manager, mis. "Siti Rahmawati, Joko Susilo". */
    public function namaManager(): ?string
    {
        $nama = $this->managers->pluck('nama');

        return $nama->isEmpty() ? null : $nama->join(', ');
    }
}
