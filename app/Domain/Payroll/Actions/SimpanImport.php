<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payroll\Import\JenisKehadiran;
use App\Domain\Payroll\Import\ValidatorKehadiran;
use App\Domain\Payroll\Models\Attendance;
use App\Domain\Payroll\Models\ImportBatch;
use App\Domain\Payroll\Models\Leave;
use App\Domain\Payroll\Models\Overtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Simpan batch import: hanya bila SEMUA baris valid (baris error harus diperbaiki atau dihapus dulu).
 * Divalidasi ulang di dalam transaksi agar data yang masuk setelah preview tetap diperiksa.
 */
final class SimpanImport
{
    public function __construct(private readonly ValidatorKehadiran $validator, private readonly AuditLogger $audit) {}

    public function handle(ImportBatch $batch): int
    {
        return DB::transaction(function () use ($batch): int {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== 'previewing') {
                throw ValidationException::withMessages(['batch' => 'Batch ini sudah disimpan atau dibatalkan.']);
            }

            $jenis = JenisKehadiran::from($batch->type);
            $model = match ($jenis) {
                JenisKehadiran::Absensi => Attendance::class,
                JenisKehadiran::Leave => Leave::class,
                JenisKehadiran::Lembur => Overtime::class,
            };

            $this->validator->reset();
            $semua = [];
            foreach ($batch->rows()->get() as $row) {
                $hasil = $this->validator->periksa($jenis, $row->payload);
                if ($hasil['error'] !== []) {
                    throw ValidationException::withMessages(['batch' => "Baris {$row->row_no} masih error: ".implode('; ', $hasil['error'])]);
                }
                array_push($semua, ...$hasil['rekaman']);
            }
            if ($semua === []) {
                throw ValidationException::withMessages(['batch' => 'Tidak ada baris untuk disimpan.']);
            }

            foreach ($semua as $rekaman) {
                $model::create($rekaman + ['import_batch_id' => $batch->id]);
            }
            $batch->forceFill(['status' => 'committed', 'committed_at' => now()])->save();
            $this->audit->log('imported', $batch, new: ['type' => $batch->type, 'baris' => $batch->total_rows, 'rekaman' => count($semua)]);

            return count($semua);
        });
    }
}
