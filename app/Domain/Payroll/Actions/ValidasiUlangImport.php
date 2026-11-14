<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Import\JenisKehadiran;
use App\Domain\Payroll\Import\ValidatorKehadiran;
use App\Domain\Payroll\Models\ImportBatch;

/**
 * Validasi ulang semua baris batch berurutan (bentrok/duplikat di dalam file bergantung pada baris lain,
 * jadi setiap perbaikan/hapus baris memvalidasi ulang seluruh batch).
 */
final class ValidasiUlangImport
{
    public function __construct(private readonly ValidatorKehadiran $validator) {}

    public function handle(ImportBatch $batch): ImportBatch
    {
        $jenis = JenisKehadiran::from($batch->type);
        $this->validator->reset();
        $valid = 0;
        $rows = $batch->rows()->get();

        foreach ($rows as $row) {
            $hasil = $this->validator->periksa($jenis, $row->payload);
            $ok = $hasil['error'] === [];
            $valid += $ok ? 1 : 0;
            $row->forceFill(['is_valid' => $ok, 'error_message' => $ok ? null : mb_substr(implode('; ', $hasil['error']), 0, 500)])->save();
        }

        $batch->forceFill(['total_rows' => $rows->count(), 'valid_rows' => $valid])->save();

        return $batch;
    }
}
