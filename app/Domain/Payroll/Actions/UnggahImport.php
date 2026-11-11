<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Import\JenisKehadiran;
use App\Domain\Payroll\Models\ImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SplFileObject;

/** Unggah CSV -> simpan ke import_batches + import_batch_rows (status previewing) lalu validasi. */
final class UnggahImport
{
    public const MAKS_BARIS = 5000;

    public function __construct(private readonly ValidasiUlangImport $validasi) {}

    public function handle(JenisKehadiran $jenis, UploadedFile $file): ImportBatch
    {
        $baris = $this->baca($jenis, $file);

        return DB::transaction(function () use ($jenis, $file, $baris): ImportBatch {
            $batch = ImportBatch::create([
                'type' => $jenis->value, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'status' => 'previewing', 'created_by' => Auth::id(),
            ]);
            foreach ($baris as $no => $payload) {
                $batch->rows()->create(['row_no' => $no, 'payload' => $payload, 'is_valid' => false]);
            }

            return $this->validasi->handle($batch);
        });
    }

    /** @return array<int, array<string, string>> nomor baris di file => payload */
    private function baca(JenisKehadiran $jenis, UploadedFile $file): array
    {
        $f = new SplFileObject($file->getRealPath());
        $pertama = (string) $f->fgets();
        $pemisah = substr_count($pertama, ';') > substr_count($pertama, ',') ? ';' : ',';
        $f->rewind();
        $f->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $f->setCsvControl($pemisah, '"', '');

        $kolom = $jenis->kolom();
        $hasil = [];
        foreach ($f as $i => $row) {
            if ($row === [null] || $row === false) {
                continue;
            }
            $row = array_map(fn ($v) => trim((string) $v), $row);
            if ($i === 0) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]); // BOM Excel
                if (array_map('strtolower', array_slice($row, 0, count($kolom))) !== $kolom) {
                    throw ValidationException::withMessages(['file' => 'Header kolom harus: '.implode(',', $kolom).'. Unduh template.']);
                }

                continue;
            }
            if (implode('', $row) === '') {
                continue;
            }
            $hasil[$i + 1] = array_combine($kolom, array_slice(array_pad($row, count($kolom), ''), 0, count($kolom)));
            if (count($hasil) > self::MAKS_BARIS) {
                throw ValidationException::withMessages(['file' => 'Maksimal '.self::MAKS_BARIS.' baris per file.']);
            }
        }

        if ($hasil === []) {
            throw ValidationException::withMessages(['file' => 'File tidak berisi data.']);
        }

        return $hasil;
    }
}
