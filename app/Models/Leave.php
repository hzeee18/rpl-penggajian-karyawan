<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;

class Leave extends Model
{
    public const JENIS = ['cuti' => 'Cuti', 'izin' => 'Izin', 'sakit' => 'Sakit'];

    protected $table = 'leave';
    protected $fillable = ['karyawan_id', 'jenis', 'tgl_mulai', 'tgl_selesai', 'keterangan', 'sumber_input'];
    protected $casts = ['tgl_mulai' => \App\Casts\Tanggal::class, 'tgl_selesai' => \App\Casts\Tanggal::class];

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    /** Jumlah hari kerja (Senin-Jumat) antara dua tanggal. */
    public static function hariKerja($mulai, $selesai): int
    {
        $mulai = Carbon::parse($mulai);
        $selesai = Carbon::parse($selesai);
        if ($selesai->lt($mulai)) {
            return 0;
        }

        return collect(CarbonPeriod::create($mulai, $selesai))->filter(fn ($d) => ! $d->isWeekend())->count();
    }

    public function jumlahHari(): int
    {
        return self::hariKerja($this->tgl_mulai, $this->tgl_selesai);
    }

    /** Kurangi sisa cuti karyawan (hanya jenis cuti). */
    public function kurangiSisaCuti(): void
    {
        if ($this->jenis === 'cuti') {
            $this->karyawan->decrement('sisa_cuti', $this->jumlahHari());
        }
    }

    public function kembalikanSisaCuti(): void
    {
        if ($this->jenis === 'cuti') {
            $this->karyawan->increment('sisa_cuti', $this->jumlahHari());
        }
    }
}
