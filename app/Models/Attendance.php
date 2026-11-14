<?php

namespace App\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use BelongsToCompany;

    public const STATUS = ['hadir' => 'Hadir', 'terlambat' => 'Terlambat', 'alfa' => 'Alfa'];

    protected $table = 'attendance';
    protected $fillable = ['karyawan_id', 'tanggal', 'jam_masuk', 'jam_keluar', 'status'];
    protected $casts = ['tanggal' => \App\Casts\Tanggal::class];

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }
}
