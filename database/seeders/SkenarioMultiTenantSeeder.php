<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Company\Models\Company;
use Illuminate\Database\Seeder;

/**
 * Semua data uji dalam satu perintah (AUDIT.md §75), dipanggil manual, tidak masuk DatabaseSeeder:
 *
 *   php artisan db:seed --class=SkenarioMultiTenantSeeder
 *
 * - Super Admin platform: superadmin
 * - MTS  (PT Maju Terus Sejahtera): alur payroll lengkap + 110 karyawan massal (FlowUjiSeeder + KaryawanMassalSeeder)
 * - BNS  (PT Bina Nusa Sentosa): cut off lintas bulan 26–25, lembur Rp25.000/jam, potongan alpha
 * - SBR  (PT Sinar Baru Raya): cut off kalender bulanan, lembur 1,5x/2x upah jam, potongan terlambat
 * Akun tiap perusahaan: admin.<kode>, hr.<kode>, manager.<kode> (Manager HR = penyetuju), staf.<kode>; password: password.
 * Aman dijalankan ulang: bagian yang sudah ada dilewati. Butuh perusahaan DEFAULT (templat role) dari DatabaseSeeder.
 */
final class SkenarioMultiTenantSeeder extends Seeder
{
    /** kode => [nama, awalan NIK, jumlah karyawan, cut off, varian master] */
    public const PERUSAHAAN = [
        'BNS' => ['PT Bina Nusa Sentosa', 'N', 15, ['start_day' => 26, 'end_day' => 25, 'start_in_previous_month' => true], 'a'],
        'SBR' => ['PT Sinar Baru Raya', 'S', 12, ['start_day' => 1, 'end_day' => 31, 'start_in_previous_month' => false], 'b'],
    ];

    public function run(): void
    {
        $this->call([FlowUjiSeeder::class, KaryawanMassalSeeder::class]);

        $demo = new PayrollDemoSeeder;
        if ($this->command) {
            $demo->setCommand($this->command);
        }
        $ter = $demo->terNasional();
        foreach (self::PERUSAHAAN as $kode => [$nama, $awalan, $jumlah, $cutoff, $varian]) {
            if (Company::where('code', $kode)->exists()) {
                $this->command?->warn("Perusahaan {$kode} sudah ada, dilewati.");

                continue;
            }
            $demo->perusahaan($kode, $nama, $awalan, $jumlah, $cutoff, $ter, $varian);
            $this->command?->info("Perusahaan {$kode} dibuat. Login: admin.".strtolower($kode).', hr.'.strtolower($kode).', manager.'.strtolower($kode).', staf.'.strtolower($kode));
        }
    }
}
