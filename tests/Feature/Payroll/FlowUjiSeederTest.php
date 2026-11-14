<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Models\RunAdjustment;
use App\Domain\Payroll\Models\RunEmployee;
use App\Domain\Payroll\Models\WorkPattern;
use App\Models\User;
use Database\Seeders\FlowUjiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** AUDIT.md §67: data uji alur end-to-end di perusahaan UJI. */
final class FlowUjiSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_data_uji_alur_lengkap_dan_bisa_dijalankan_ulang(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-14 09:00', 'Asia/Jakarta'));
        Storage::fake('local');
        $this->seed(FlowUjiSeeder::class);
        $this->seed(FlowUjiSeeder::class); // kedua kali: tidak mengubah apa pun

        $company = Company::where('code', 'MTS')->firstOrFail();
        app(CurrentCompany::class)->runAs((int) $company->id, function () {
            $this->assertSame(['final', 'draft', 'terbuka'], PayPeriod::orderBy('period_month')->get()->map->status()->all());
            $this->assertSame(PayrollRun::APPROVED, PayrollRun::where('title', 'Payroll Oktober 2026')->value('status'));
            $nov = PayrollRun::where('title', 'Payroll November 2026')->firstOrFail();
            $this->assertSame(PayrollRun::DRAFT, $nov->status);
            $this->assertSame(2, RunAdjustment::where('run_id', $nov->id)->count());
            $this->assertGreaterThan(0, Payslip::count(), 'slip Oktober terbit');
            $this->assertSame('1,2,3,4,5,6,7', WorkPattern::where('code', 'operasional')->value('work_days'));
            // prorata: karyawan baru masuk 10 Nov dan resign 15 Nov ada di preview November dengan paid_workdays < workdays
            $baru = RunEmployee::where('run_id', $nov->id)->where('full_name', 'Rani Karyawan Baru')->firstOrFail();
            $this->assertArrayHasKey('paid_workdays', $baru->variables_snapshot);
            $this->assertNotNull(RunEmployee::where('run_id', $nov->id)->where('full_name', 'Sapto Karyawan Resign')->first());
            // BPJS dihitung dari gaji pokok kontrak (AUDIT.md §73): Kes 1%, TK 2%, tanpa batas upah bawaan
            $adi = RunEmployee::where('run_id', $nov->id)->where('full_name', 'Adi Pratama')->firstOrFail()->items->keyBy('code');
            $this->assertSame('50000.00', (string) $adi['bpjs_kes']->final_amount);
            $this->assertSame('100000.00', (string) $adi['bpjs_tk']->final_amount);
            $this->assertFalse((bool) \App\Domain\Payroll\Models\Component::where('code', 'bpjs_kes')->value('is_contractable'));
        });

        foreach (['admin.mts', 'hr.mts', 'manager.mts', 'staf.mts'] as $u) {
            $this->assertTrue(User::withoutGlobalScopes()->where('username', $u)->where('company_id', $company->id)->exists(), $u);
        }
        $hr = User::withoutGlobalScopes()->where('username', 'hr.mts')->firstOrFail();
        $nov = app(CurrentCompany::class)->runAs((int) $company->id, fn () => PayrollRun::where('title', 'Payroll November 2026')->value('id'));
        $this->actingAs($hr)->get("/pay/run/{$nov}")->assertOk()->assertSee('Persiapan sebelum generate')->assertSee('Bonus kinerja Q3');
    }
}
