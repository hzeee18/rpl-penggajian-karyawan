<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Tabel referensi NASIONAL (company_id NULL) untuk PPh21 masa Desember / resign (pilihan "PPh21 Desember/resign
 * otomatis" di master payroll, lihat Item::RUMUS_PPH21_DESEMBER), dari file resmi yang diisi pemilik:
 * database/seeders/data/pph21_tahunan.csv (format sama dengan ter_pph21.csv). Angka TIDAK dikarang di sini.
 *   - ptkp_setahun  : kunci = status PTKP (TK/0, K/1, ...), hasil_angka = PTKP setahun.
 *   - biaya_jabatan : kunci `persen` (mis. 0.05) dan `maks_bulanan`, hasil_angka.
 *   - pasal17       : rentang PKP setahun (batas_bawah..batas_atas, atas kosong = tak terbatas), tiga kunci per lapisan:
 *                     `batas` = batas bawah lapisan, `tarif` = tarif lapisan, `dasar` = pajak kumulatif s/d batas bawah.
 * Dipanggil manual: php artisan db:seed --class=Pph21TahunanSeeder. Idempoten (versi baru lewat menu oleh Super Admin).
 */
final class Pph21TahunanSeeder extends Seeder
{
    public const FILE = 'seeders/data/pph21_tahunan.csv';

    public const TABEL = [
        'ptkp_setahun' => 'PTKP setahun per status',
        'biaya_jabatan' => 'Biaya jabatan (persen dan maksimal per bulan)',
        'pasal17' => 'Tarif progresif PPh Pasal 17 (batas, tarif, pajak kumulatif)',
    ];

    public function run(): void
    {
        if ($this->pasang()) {
            $this->command?->info('Tabel nasional ptkp_setahun, biaya_jabatan, dan pasal17 tersedia.');
        } else {
            $this->command?->warn('File '.self::FILE.' belum ada: tabel PPh21 tahunan dilewati.');
        }
    }

    /** Pasang tabel nasional dari $file (null = file resmi). Kembalikan true bila tabel tersedia. */
    public function pasang(?string $file = null): bool
    {
        return TerPph21Seeder::pasangCsv($file ?? database_path(self::FILE), self::FILE, self::TABEL, 'seeder: tabel referensi nasional PPh21 tahunan');
    }
}
