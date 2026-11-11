<?php

namespace App\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Karyawan extends Model
{
    use BelongsToCompany;

    protected $table = 'karyawan';
    protected $fillable = [
        'departemen_id', 'pajak_id', 'nik', 'nama', 'jabatan', 'tgl_masuk', 'no_rekening',
        'jenis_kelamin', 'email', 'telepon', 'alamat', 'nama_bank', 'status_kepegawaian', 'sisa_cuti', 'is_active',
    ];
    protected $casts = ['tgl_masuk' => \App\Casts\Tanggal::class, 'is_active' => 'boolean'];

    public function departemen()
    {
        return $this->belongsTo(Departemen::class);
    }

    public function pajak()
    {
        return $this->belongsTo(Pajak::class);
    }

    public function user()
    {
        return $this->hasOne(User::class);
    }

    public function kontrak()
    {
        return $this->hasMany(Kontrak::class)->latest('tgl_mulai');
    }

    public function attendance()
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaves()
    {
        return $this->hasMany(Leave::class);
    }

    public function overtime()
    {
        return $this->hasMany(Overtime::class);
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    public function kontrakAktif()
    {
        return $this->hasOne(Kontrak::class)->where('is_active', true)->where('status_approval', 'disetujui');
    }

    public function getKontrakAktif(): ?Kontrak
    {
        return $this->kontrakAktif()->first();
    }

    /** Masa kerja dalam bulan. */
    public function hitungMasaKerja(): int
    {
        return $this->tgl_masuk ? (int) $this->tgl_masuk->diffInMonths(Carbon::today()) : 0;
    }

    /** Karyawan berhak lembur kalau kontrak aktifnya punya komponen lembur. */
    public function berhakLembur(): bool
    {
        $kontrak = $this->getKontrakAktif();

        return $kontrak && $kontrak->komponen()->where('is_active', true)
            ->whereHas('component', fn ($q) => $q->where('dasar_bayar', 'lembur'))->exists();
    }
}
