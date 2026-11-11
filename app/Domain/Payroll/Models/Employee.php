<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Casts\Tanggal;
use App\Domain\Company\Tenancy\BelongsToCompany;
use App\Models\Departemen;
use App\Models\Pajak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Karyawan dari sisi payroll baru (tabel lama `karyawan`, keputusan AUDIT.md §9 butir 1).
 * Pemetaan kolom: employee_no/nik = nik, full_name = nama, join_date = tgl_masuk,
 * ptkp_status = pajak.status_ptkp, bank_name = nama_bank.
 * npwp dan bank_account_no terenkripsi; jangan dicatat di log dan jangan ditampilkan penuh.
 *
 * Model ini hanya mengubah kolom milik payroll baru; data inti karyawan tetap dikelola modul lama.
 */
final class Employee extends Model
{
    use BelongsToCompany;

    protected $table = 'karyawan';

    protected $fillable = ['position_id', 'resign_date', 'has_npwp', 'npwp'];

    protected $hidden = ['npwp', 'bank_account_no', 'no_rekening'];

    protected function casts(): array
    {
        return [
            'tgl_masuk' => Tanggal::class,
            'resign_date' => Tanggal::class,
            'has_npwp' => 'boolean',
            'is_active' => 'boolean',
            'npwp' => 'encrypted',
            'bank_account_no' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** @return BelongsTo<Departemen, $this> */
    public function departemen(): BelongsTo
    {
        return $this->belongsTo(Departemen::class);
    }

    /** @return BelongsTo<Pajak, $this> */
    public function pajak(): BelongsTo
    {
        return $this->belongsTo(Pajak::class);
    }

    public function employeeNo(): string
    {
        return (string) $this->getAttribute('nik');
    }

    public function ptkpStatus(): ?string
    {
        return $this->pajak?->status_ptkp;
    }

    /** Nomor rekening disamarkan untuk tampilan, mis. ******7890. */
    public function rekeningSamaran(): ?string
    {
        $no = $this->getAttribute('bank_account_no');

        return $no === null || $no === '' ? null : str_repeat('*', max(0, strlen($no) - 4)).substr($no, -4);
    }
}
