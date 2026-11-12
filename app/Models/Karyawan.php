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
        // data payroll baru (form Data Karyawan bila perusahaan memakai payroll baru, AUDIT.md §29)
        'position_id', 'resign_date', 'has_npwp', 'npwp',
    ];
    protected $hidden = ['npwp', 'bank_account_no'];
    protected $casts = ['tgl_masuk' => \App\Casts\Tanggal::class, 'is_active' => 'boolean',
        'resign_date' => \App\Casts\Tanggal::class, 'has_npwp' => 'boolean', 'npwp' => 'encrypted'];

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

    /** Jabatan modul payroll baru. */
    public function position()
    {
        return $this->belongsTo(\App\Domain\Payroll\Models\Position::class);
    }

    /** Kontrak modul payroll baru (pay_contracts). */
    public function kontrakPayroll()
    {
        return $this->hasMany(\App\Domain\Payroll\Models\Contract::class, 'employee_id')->orderByDesc('start_date')->orderByDesc('id');
    }

    public function kontrakPayrollAktif()
    {
        return $this->hasOne(\App\Domain\Payroll\Models\Contract::class, 'employee_id')->where('is_active', true);
    }

    /**
     * Karyawan berhak lembur kalau kontrak aktifnya punya komponen lembur.
     * Perusahaan dengan payroll baru: cukup punya kontrak payroll aktif (nilai lembur dihitung rumus master).
     */
    public function berhakLembur(): bool
    {
        if (\App\Domain\Payroll\ModulPayroll::aktif()) {
            return $this->kontrakPayrollAktif()->exists();
        }
        $kontrak = $this->getKontrakAktif();

        return $kontrak && $kontrak->komponen()->where('is_active', true)
            ->whereHas('component', fn ($q) => $q->where('dasar_bayar', 'lembur'))->exists();
    }
}
