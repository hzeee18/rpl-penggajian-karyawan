<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Models\Attendance;
use App\Domain\Payroll\Models\Contract;
use App\Models\Departemen;
use App\Models\Karyawan;
use App\Models\User;
use Database\Seeders\FlowUjiSeeder;
use Database\Seeders\KaryawanMassalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** AUDIT.md §72: karyawan dummy massal di perusahaan UJI. */
final class KaryawanMassalSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_karyawan_massal_ditambahkan_ke_uji_dan_aman_dijalankan_ulang(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-14 09:00', 'Asia/Jakarta'));
        Storage::fake('local');
        $this->seed(FlowUjiSeeder::class);
        KaryawanMassalSeeder::$jumlah = 30; // test lebih cepat; default 110
        try {
            $this->seed(KaryawanMassalSeeder::class);
            $this->seed(KaryawanMassalSeeder::class); // kedua kali: tidak mengubah apa pun
        } finally {
            KaryawanMassalSeeder::$jumlah = 110;
        }

        $company = Company::where('code', 'MTS')->firstOrFail();
        app(CurrentCompany::class)->runAs((int) $company->id, function () {
            $this->assertSame(30, Karyawan::where('nik', 'like', 'M%')->count());
            $this->assertSame(42, Karyawan::count(), '12 karyawan uji tetap ada');
            $this->assertSame(30, Contract::whereIn('employee_id', Karyawan::where('nik', 'like', 'M%')->pluck('id'))->count());
            $this->assertTrue(Departemen::where('kode', 'GDG')->exists());
            $this->assertGreaterThan(500, Attendance::count());
        });
        $this->assertSame(30, User::withoutGlobalScopes()->where('company_id', $company->id)->where('username', 'like', 'm___.mts')->count());
    }
}
