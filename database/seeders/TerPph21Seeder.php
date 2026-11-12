<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Models\ReferenceTable;
use App\Domain\Payroll\Models\ReferenceTableVersion;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Tabel referensi NASIONAL (company_id NULL) `ter_bulanan` dan `kategori_ptkp` dari file resmi yang diisi
 * pemilik (database/seeders/data/ter_pph21.csv). Angka TIDAK dikarang di sini. Dipanggil manual:
 *   php artisan db:seed --class=TerPph21Seeder
 * Idempoten: bila kedua tabel sudah ada, tidak ada yang diubah (versi baru dibuat Super Admin lewat menu).
 */
final class TerPph21Seeder extends Seeder
{
    public const FILE = 'seeders/data/ter_pph21.csv';

    public const TABEL = ['ter_bulanan' => 'Tarif Efektif Rata-rata (TER) bulanan', 'kategori_ptkp' => 'Kategori TER per status PTKP'];

    public function run(): void
    {
        if ($this->pasang()) {
            $this->command?->info('Tabel nasional ter_bulanan dan kategori_ptkp tersedia.');
        } else {
            $this->command?->warn('File '.self::FILE.' belum ada: tabel TER dilewati.');
        }
    }

    /** Pasang tabel nasional dari $file (null = file resmi). Kembalikan true bila tabel tersedia. */
    public function pasang(?string $file = null): bool
    {
        if (ReferenceTable::whereNull('company_id')->whereIn('code', array_keys(self::TABEL))->count() === count(self::TABEL)) {
            return true;
        }
        $file ??= database_path(self::FILE);
        if (! is_file($file)) {
            return false;
        }

        // format: tabel,kunci,batas_bawah,batas_atas,hasil_angka,hasil_teks
        $baris = array_map('str_getcsv', array_filter(array_map('trim', file($file) ?: [])));
        $header = array_map('strtolower', array_shift($baris) ?? []);
        if ($header !== ['tabel', 'kunci', 'batas_bawah', 'batas_atas', 'hasil_angka', 'hasil_teks']) {
            throw new RuntimeException('Header '.self::FILE.' harus: tabel,kunci,batas_bawah,batas_atas,hasil_angka,hasil_teks');
        }

        // lintas perusahaan: tabel nasional (company_id NULL) dipakai semua perusahaan
        app(CurrentCompany::class)->lintasPerusahaan('seeder: tabel referensi nasional TER/PTKP', function () use ($baris) {
            foreach (self::TABEL as $kode => $nama) {
                if (ReferenceTable::whereNull('company_id')->where('code', $kode)->exists()) {
                    continue;
                }
                $isi = array_values(array_filter($baris, fn ($b) => ($b[0] ?? '') === $kode));
                if ($isi === []) {
                    throw new RuntimeException("Tidak ada baris tabel {$kode} di ".self::FILE);
                }
                $tabel = ReferenceTable::create(['company_id' => null, 'code' => $kode, 'name' => $nama, 'description' => 'Sumber: '.self::FILE]);
                $versi = ReferenceTableVersion::create(['company_id' => null, 'reference_table_id' => $tabel->id, 'version_no' => 1, 'status' => 'published', 'published_at' => now()]);
                foreach ($isi as $i => [, $kunci, $dari, $sampai, $angka, $teks]) {
                    $versi->rows()->create(['company_id' => null, 'key_value' => $kunci ?: null, 'range_from' => $dari !== '' ? $dari : null,
                        'range_to' => $sampai !== '' ? $sampai : null, 'result_number' => $angka !== '' ? $angka : null, 'result_text' => $teks !== '' ? $teks : null, 'sort_order' => $i]);
                }
            }
        });

        return true;
    }
}
