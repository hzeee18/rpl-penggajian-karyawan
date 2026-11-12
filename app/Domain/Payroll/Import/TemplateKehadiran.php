<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Import;

use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\LeaveType;
use Carbon\CarbonImmutable;

/**
 * Isi template CSV import kehadiran untuk perusahaan aktif: header tetap + baris contoh memakai NIK karyawan
 * aktif dan kode tipe leave milik perusahaan itu, tanggal hari kerja terakhir. Bila perusahaan belum punya
 * data, dipakai contoh bawaan JenisKehadiran::contoh(). Data perusahaan lain tidak terbaca (scope tenant).
 */
final class TemplateKehadiran
{
    public function isi(JenisKehadiran $jenis): string
    {
        $baris = [$jenis->kolom(), ...$this->contoh($jenis)];

        return implode("\n", array_map(fn (array $b) => implode(',', array_map($this->sel(...), $b)), $baris))."\n";
    }

    /** @return list<list<string>> */
    private function contoh(JenisKehadiran $jenis): array
    {
        // karyawan aktif, utamakan yang punya kontrak payroll aktif
        $nik = Employee::where('is_active', true)->orderByRaw('exists (select 1 from pay_contracts c where c.employee_id = karyawan.id and c.is_active = 1) desc')
            ->orderBy('nik')->limit(2)->pluck('nik')->all();
        if ($nik === []) {
            return [$jenis->contoh()];
        }
        $nik[1] ??= $nik[0];
        [$h1, $h2] = $this->hariKerjaTerakhir();

        return match ($jenis) {
            JenisKehadiran::Absensi => [
                [$nik[0], $h1, '08:00', '17:00', '0'],
                [$nik[1], $h1, '08:30', '17:00', '0.5'],
            ],
            JenisKehadiran::Leave => [
                [$nik[0], LeaveType::where('is_active', true)->orderBy('id')->value('code') ?? 'cuti', $h2, $h2, 'Contoh keterangan'],
            ],
            JenisKehadiran::Lembur => [
                [$nik[0], $h1, '2', 'Contoh lembur'],
            ],
        };
    }

    /** @return array{0: string, 1: string} dua hari kerja (Senin-Jumat) terakhir sampai hari ini, terbaru dulu */
    private function hariKerjaTerakhir(): array
    {
        $hari = [];
        for ($d = CarbonImmutable::today(); count($hari) < 2; $d = $d->subDay()) {
            if (! $d->isWeekend()) {
                $hari[] = $d->toDateString();
            }
        }

        return [$hari[0], $hari[1]];
    }

    private function sel(string $nilai): string
    {
        return preg_match('/[",\n]/', $nilai) ? '"'.str_replace('"', '""', $nilai).'"' : $nilai;
    }
}
