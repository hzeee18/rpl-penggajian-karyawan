<?php

namespace App\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Overtime extends Model
{
    use BelongsToCompany;

    protected $table = 'overtime';
    protected $fillable = ['karyawan_id', 'tanggal', 'jam_mulai', 'jam_selesai', 'durasi', 'keterangan', 'diinput_oleh'];
    protected $casts = ['tanggal' => \App\Casts\Tanggal::class, 'durasi' => 'float'];

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function penginput()
    {
        return $this->belongsTo(User::class, 'diinput_oleh');
    }

    /** Durasi dalam jam (boleh lewat tengah malam). */
    public static function hitungDurasi(string $mulai, string $selesai): float
    {
        $a = Carbon::createFromFormat('H:i', substr($mulai, 0, 5));
        $b = Carbon::createFromFormat('H:i', substr($selesai, 0, 5));
        if ($b->lte($a)) {
            $b->addDay();
        }

        return round($a->diffInMinutes($b) / 60, 2);
    }

    /** Upah lembur: tarif per jam x (jam pertama x pengali1 + jam berikutnya x pengali2). */
    public function hitungUpah(float $tarifPerJam, MasterPayroll $master): float
    {
        $jam1 = min($this->durasi, 1);
        $sisa = max($this->durasi - 1, 0);

        return $tarifPerJam * ($jam1 * $master->pengali_jam1 + $sisa * $master->pengali_jam_berikut);
    }
}
