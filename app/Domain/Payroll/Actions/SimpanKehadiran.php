<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payroll\Import\JenisKehadiran;
use App\Domain\Payroll\Import\KunciPeriode;
use App\Domain\Payroll\Import\ValidatorKehadiran;
use App\Domain\Payroll\Models\Attendance;
use App\Domain\Payroll\Models\Leave;
use App\Domain\Payroll\Models\Overtime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Input manual absensi/leave/lembur (tambah, ubah, hapus) dengan validasi yang sama seperti import CSV.
 * Leave manual dengan rentang dipecah per hari kerja; mengubah leave berlaku untuk satu hari.
 */
final class SimpanKehadiran
{
    public function __construct(
        private readonly ValidatorKehadiran $validator,
        private readonly KunciPeriode $kunci,
        private readonly AuditLogger $audit,
    ) {}

    /** @return class-string<Model> */
    public static function model(JenisKehadiran $jenis): string
    {
        return match ($jenis) {
            JenisKehadiran::Absensi => Attendance::class,
            JenisKehadiran::Leave => Leave::class,
            JenisKehadiran::Lembur => Overtime::class,
        };
    }

    /**
     * @param  array<string, mixed>  $payload  kunci = kolom CSV jenis ini
     * @return list<Model>
     */
    public function simpan(JenisKehadiran $jenis, array $payload, ?Model $ubah = null): array
    {
        return DB::transaction(function () use ($jenis, $payload, $ubah): array {
            if ($ubah !== null) {
                $this->pastikanTidakTerkunci($ubah);
            }
            $this->validator->reset();
            $hasil = $this->validator->periksa($jenis, $payload, $ubah?->getKey());
            if ($hasil['error'] !== []) {
                throw ValidationException::withMessages(['data' => $hasil['error']]);
            }
            if ($ubah !== null && count($hasil['rekaman']) !== 1) {
                throw ValidationException::withMessages(['data' => 'Mengubah leave hanya untuk satu hari kerja.']);
            }

            $model = self::model($jenis);
            $tersimpan = [];
            foreach ($hasil['rekaman'] as $rekaman) {
                if ($ubah !== null) {
                    $lama = $ubah->getAttributes();
                    $ubah->fill($rekaman)->save();
                    $this->audit->log('updated', $ubah, old: $lama, new: $ubah->getAttributes());
                    $tersimpan[] = $ubah;
                } else {
                    $baru = $model::create($rekaman);
                    $this->audit->log('created', $baru, new: $rekaman);
                    $tersimpan[] = $baru;
                }
            }

            return $tersimpan;
        });
    }

    public function hapus(Model $data): void
    {
        DB::transaction(function () use ($data) {
            $this->pastikanTidakTerkunci($data);
            $this->audit->log('deleted', $data, old: $data->getAttributes());
            $data->delete();
        });
    }

    private function pastikanTidakTerkunci(Model $data): void
    {
        $tanggal = $data->getAttribute('attendance_date') ?? $data->getAttribute('leave_date') ?? $data->getAttribute('overtime_date');
        if ($this->kunci->terkunci((int) $data->getAttribute('employee_id'), $tanggal->toDateString())) {
            throw ValidationException::withMessages(['data' => 'Data di periode payroll yang sudah final tidak bisa diubah.']);
        }
    }
}
