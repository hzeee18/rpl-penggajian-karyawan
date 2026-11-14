<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Company\Actions\HapusPerusahaan;
use Illuminate\Database\Seeder;

/**
 * Data bersih untuk dipakai/didemokan (AUDIT.md §78), dipanggil manual setelah `migrate:fresh --seed`:
 *
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=DataBersihSeeder
 *
 * Perusahaan DEFAULT dikosongkan jadi templat role saja (tidak tampil di daftar perusahaan), lalu dibuat akun `superadmin`
 * dan satu perusahaan MTS lengkap (FlowUjiSeeder + 110 karyawan KaryawanMassalSeeder). Password semua akun: password.
 */
final class DataBersihSeeder extends Seeder
{
    public function run(): void
    {
        app(HapusPerusahaan::class)->kosongkanTemplat();
        $this->call([FlowUjiSeeder::class, KaryawanMassalSeeder::class]);
    }
}
