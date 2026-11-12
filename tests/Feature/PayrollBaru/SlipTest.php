<?php

declare(strict_types=1);

namespace Tests\Feature\PayrollBaru;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CompanyScope;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Models\ContractComponent;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Slip\PasswordSlip;
use App\Models\Karyawan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\MultiTenant\BuatPerusahaanLain;
use Tests\TestCase;

/** Fase 7: slip PDF berpassword dan akses karyawan. */
final class SlipTest extends TestCase
{
    use BuatPerusahaanLain;
    use RefreshDatabase;
    use SiapkanPayroll;

    protected bool $seed = true;

    private User $hr;

    private User $managerHr;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 09:00', 'Asia/Jakarta'));
        Storage::fake('local');
        $this->artisan('payroll:aktifkan', ['kode' => 'DEFAULT']);
        $this->hr = User::where('username', 'hr')->firstOrFail();
        $this->managerHr = User::where('username', 'manager.hr')->firstOrFail();
        $this->hr->forceFill(['approver_user_id' => $this->managerHr->id])->save();
    }

    /** Payroll disetujui untuk Rizky dan Nadia; slip terbit lewat event + job (queue sync di tes). */
    private function payrollDisetujui(): PayrollRun
    {
        $master = $this->siapkanPayroll();
        $this->actingAs($this->hr)->post('/pay/run', ['title' => 'Payroll November 2026', 'periode' => '2026-11']);
        $run = PayrollRun::latest('id')->firstOrFail();
        $pilihan = Employee::whereIn('nama', ['Rizky Maulana', 'Nadia Putri'])->pluck('id')->all();
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/pratinjau", ['master_id' => $master->id, 'scope' => 'individual', 'pilihan' => $pilihan]);
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate")->assertSessionHasNoErrors();
        $this->actingAs($this->managerHr)->post("/pay/approval/{$run->id}/setujui")->assertSessionHasNoErrors();

        return $run->fresh();
    }

    public function test_aturan_password(): void
    {
        $this->assertSame('017890', PasswordSlip::dari('K0001', '1234567890'));
        $this->assertSame('457890', PasswordSlip::dari('EMP-2345', '12-345-67890'));
        $this->assertNull(PasswordSlip::dari('K0001', null));
        $this->assertNull(PasswordSlip::dari('K0001', '123'));
        $this->assertNull(PasswordSlip::dari('A', '1234567890'));
    }

    public function test_slip_terbit_setelah_disetujui_dengan_pdf_berpassword_di_folder_perusahaan(): void
    {
        $run = $this->payrollDisetujui();

        $slip = Payslip::with('runEmployee')->get();
        $this->assertCount(2, $slip);
        foreach ($slip as $s) {
            $this->assertStringStartsWith("payslips/{$run->company_id}/{$run->id}/", (string) $s->file_path);
            $pdf = Storage::disk('local')->get($s->file_path);
            $this->assertStringStartsWith('%PDF', $pdf);
            $this->assertStringContainsString('/Encrypt', $pdf, 'PDF harus dilindungi password');
            $this->assertNotNull($s->generated_at);
        }
    }

    public function test_karyawan_dan_hr_hanya_melihat_slip_sendiri(): void
    {
        $this->payrollDisetujui();
        $rizky = User::where('username', 'rizky')->firstOrFail();
        $slipRizky = Payslip::where('employee_id', $rizky->karyawan_id)->firstOrFail();
        $slipNadia = Payslip::where('employee_id', '!=', $rizky->karyawan_id)->firstOrFail();

        $this->actingAs($rizky)->get('/pay/slip')->assertOk()->assertSee('Payroll November 2026')->assertDontSee('Nadia Putri');
        $this->actingAs($rizky)->get("/pay/slip/{$slipRizky->id}")->assertOk()->assertSee('Take home pay');
        $this->actingAs($rizky)->get("/pay/slip/{$slipRizky->id}/unduh")->assertOk()->assertHeader('content-disposition');
        $this->actingAs($rizky)->get("/pay/slip/{$slipNadia->id}")->assertForbidden();
        $this->actingAs($rizky)->get("/pay/slip/{$slipNadia->id}/unduh")->assertForbidden();

        // HR juga hanya slip miliknya (AUDIT.md §34); rincian semua karyawan ada di Generate Payroll
        $this->actingAs($this->hr)->get('/pay/slip')->assertOk()->assertDontSee('Nadia Putri')->assertDontSee('Rizky Maulana');
        $this->actingAs($this->hr)->get("/pay/slip/{$slipNadia->id}")->assertForbidden();
    }

    public function test_manager_hanya_melihat_slip_sendiri_termasuk_manager_hr(): void
    {
        $this->payrollDisetujui();
        $slipRizky = Payslip::where('employee_id', User::where('username', 'rizky')->value('karyawan_id'))->firstOrFail();

        // Manager HR bertindak sebagai HR untuk menu lain, tetapi slip hanya miliknya sendiri
        $this->actingAs($this->managerHr)->get('/pay/slip')->assertOk()->assertDontSee('Rizky Maulana')->assertDontSee('Nadia Putri');
        $this->actingAs($this->managerHr)->get("/pay/slip/{$slipRizky->id}")->assertForbidden();
        $this->actingAs(User::where('username', 'manager.it')->firstOrFail())->get("/pay/slip/{$slipRizky->id}")->assertForbidden();
    }

    public function test_dashboard_manager_dari_payroll_baru_dan_approval_lama_dialihkan(): void
    {
        $master = $this->siapkanPayroll();
        $this->actingAs($this->hr)->post('/pay/run', ['title' => 'Payroll Menunggu Uji', 'periode' => '2026-11']);
        $run = PayrollRun::latest('id')->firstOrFail();
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/pratinjau", ['master_id' => $master->id, 'scope' => 'individual', 'pilihan' => Employee::where('nama', 'Rizky Maulana')->pluck('id')->all()]);
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate")->assertSessionHasNoErrors();

        $this->actingAs($this->managerHr)->get('/dashboard')->assertOk()
            ->assertSee('Payroll Menunggu Persetujuan Anda')->assertSee('Payroll Menunggu Uji')
            ->assertSee(route('pay.approval.show', $run->id))->assertDontSee(route('approval.index').'"', false);
        $this->actingAs(User::where('username', 'manager.it')->firstOrFail())->get('/dashboard')->assertOk()
            ->assertSee('Tidak ada payroll yang menunggu approval kamu.');

        $this->actingAs($this->managerHr)->get('/approval')->assertRedirect(route('pay.approval.index'));
        $sidebar = $this->actingAs(User::where('username', 'manager.it')->firstOrFail())->get('/pay/approval')->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('#<a href="https?://[^/"]+/(approval|absensi|cuti|lembur)" class="nav-item-link#', $sidebar);
    }

    public function test_slip_diambil_dari_snapshot_bukan_kontrak_terkini(): void
    {
        $this->payrollDisetujui();
        $rizky = User::where('username', 'rizky')->firstOrFail();
        ContractComponent::whereHas('contract', fn ($q) => $q->where('employee_id', $rizky->karyawan_id))->update(['amount' => '9999999']);

        $slip = Payslip::where('employee_id', $rizky->karyawan_id)->firstOrFail();
        $this->actingAs($rizky)->get("/pay/slip/{$slip->id}")->assertSee('Rp 5.700.000')->assertDontSee('9.999.999');
    }

    public function test_tanpa_rekening_pdf_tidak_diterbitkan(): void
    {
        Karyawan::where('nama', 'Nadia Putri')->firstOrFail()->update(['no_rekening' => null]);
        $this->payrollDisetujui();

        $nadia = Payslip::whereHas('runEmployee', fn ($q) => $q->where('full_name', 'Nadia Putri'))->firstOrFail();
        $this->assertNull($nadia->file_path);
        $this->assertStringContainsString('rekening', (string) $nadia->note);
        // slip hanya bisa dibuka pemiliknya
        $this->actingAs(User::where('karyawan_id', $nadia->employee_id)->firstOrFail())->get("/pay/slip/{$nadia->id}/unduh")->assertNotFound();
    }

    public function test_upload_tanda_tangan_perusahaan_oleh_super_admin(): void
    {
        $this->artisan('super-admin:buat', ['username' => 'super', '--password' => 'rahasia123']);
        $super = User::withoutGlobalScope(CompanyScope::class)->where('username', 'super')->firstOrFail();
        $default = Company::where('code', 'DEFAULT')->firstOrFail();

        $this->actingAs($super)->put("/perusahaan/{$default->id}", ['name' => $default->name, 'signer_name' => 'Direktur', 'is_active' => 1,
            'tanda_tangan' => UploadedFile::fake()->image('ttd.png', 200, 80)])->assertSessionHasNoErrors();

        $path = $default->fresh()->signature_path;
        $this->assertStringStartsWith("signatures/{$default->id}/", (string) $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_slip_perusahaan_lain_tidak_bisa_dibuka(): void
    {
        $this->payrollDisetujui();
        $b = $this->perusahaanLain();
        $slipA = Payslip::firstOrFail();

        $hrB = $b['users']['HR'];
        $this->actingAs($hrB)->get("/pay/slip/{$slipA->id}")->assertNotFound();
        $this->actingAs($hrB)->get('/pay/slip')->assertDontSee('Rizky Maulana');
        $this->assertNotNull(app(CurrentCompany::class));
    }
}
