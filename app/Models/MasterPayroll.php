<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan payroll per periode: tanggal mulai-selesai, hari kerja, cut-off,
 * pembagi & pengali lembur. Satu baris per periode (YYYY-MM).
 */
class MasterPayroll extends Model
{
    protected $table = 'master_payroll';
    protected $fillable = ['periode', 'tgl_mulai', 'tgl_selesai', 'tgl_cutoff', 'hari_kerja', 'pembagi_lembur', 'pengali_jam1', 'pengali_jam_berikut', 'dibuat_oleh'];
    protected $casts = [
        'pengali_jam1' => 'float', 'pengali_jam_berikut' => 'float',
        'tgl_mulai' => \App\Casts\Tanggal::class, 'tgl_selesai' => \App\Casts\Tanggal::class,
    ];

    public function pembuat()
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function payroll()
    {
        return $this->hasOne(Payroll::class, 'periode', 'periode');
    }

    /** Pengaturan terbaru (dipakai sebagai default periode baru). */
    public static function aktif(): self
    {
        return static::latest('id')->first() ?? static::create([]);
    }

    /** Pengaturan untuk satu periode; dibuat otomatis dari pengaturan terakhir kalau belum ada. */
    public static function untuk(string $periode): self
    {
        $ada = static::where('periode', $periode)->first();
        if ($ada) {
            return $ada;
        }
        $dasar = static::aktif();
        [$mulai, $selesai] = $dasar->hitungRentang($periode);

        return static::create([
            'periode' => $periode, 'tgl_mulai' => $mulai, 'tgl_selesai' => $selesai,
            'tgl_cutoff' => $dasar->tgl_cutoff, 'hari_kerja' => Leave::hariKerja($mulai, $selesai),
            'pembagi_lembur' => $dasar->pembagi_lembur, 'pengali_jam1' => $dasar->pengali_jam1,
            'pengali_jam_berikut' => $dasar->pengali_jam_berikut, 'dibuat_oleh' => auth()->id(),
        ]);
    }

    /** Rentang default dari tanggal cut-off. Cut-off 25 => 2026-11 = 26 Okt s/d 25 Nov; 0 => sebulan penuh. */
    public function hitungRentang(string $periode): array
    {
        $bulan = Carbon::createFromFormat('Y-m-d', $periode . '-01')->startOfDay();
        if ($this->tgl_cutoff < 1 || $this->tgl_cutoff > 28) {
            return [$bulan->copy()->startOfMonth(), $bulan->copy()->endOfMonth()->startOfDay()];
        }

        return [$bulan->copy()->subMonthNoOverflow()->day($this->tgl_cutoff)->addDay(), $bulan->copy()->day($this->tgl_cutoff)];
    }

    /** Rentang tanggal periode: pakai tanggal yang diatur HR kalau ada. */
    public function rentang(string $periode): array
    {
        $row = $this->periode === $periode ? $this : static::where('periode', $periode)->first();
        if ($row && $row->tgl_mulai && $row->tgl_selesai) {
            return [$row->tgl_mulai->copy(), $row->tgl_selesai->copy()];
        }

        return $this->hitungRentang($periode);
    }

    /** Hari kerja periode (pakai pengaturan periode kalau ada). */
    public static function hariKerja(string $periode): int
    {
        return (int) (static::where('periode', $periode)->value('hari_kerja') ?? static::aktif()->hari_kerja);
    }
}
