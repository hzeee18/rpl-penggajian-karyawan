<?php

declare(strict_types=1);

namespace Tests\Feature\PayrollBaru;

use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\ReferenceTableVersion;
use App\Domain\Payroll\Models\RunEmployee;
use App\Domain\Payroll\Models\TaxMonthlyHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\MultiTenant\BuatPerusahaanLain;
use Tests\TestCase;

/** Fase 6: alur payroll (preview, edit sel, generate, approval, cegah ganda, kunci periode). */
final class AlurPayrollTest extends TestCase
{
    use BuatPerusahaanLain;
    use RefreshDatabase;
    use SiapkanPayroll;

    protected bool $seed = true;

    private User $hr;

    private User $managerHr;

    private Master $master;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 09:00', 'Asia/Jakarta'));
        $this->artisan('payroll:aktifkan', ['kode' => 'DEFAULT']);
        $this->hr = User::where('username', 'hr')->firstOrFail();
        $this->managerHr = User::where('username', 'manager.hr')->firstOrFail();
        $this->hr->forceFill(['approver_user_id' => $this->managerHr->id])->save();
        $this->master = $this->siapkanPayroll();
    }

    private function id(string $nama): int
    {
        return (int) Employee::where('nama', $nama)->value('id');
    }

    private function runBaru(array $karyawan): PayrollRun
    {
        $this->actingAs($this->hr)->post('/pay/run', ['title' => 'Payroll November 2026', 'periode' => '2026-11'])->assertSessionHasNoErrors();
        $run = PayrollRun::latest('id')->firstOrFail();
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/pratinjau", ['master_id' => $this->master->id, 'scope' => 'individual', 'pilihan' => $karyawan])
            ->assertSessionHasNoErrors();

        return $run->fresh();
    }

    private function sel(PayrollRun $run, int $employeeId, string $code): object
    {
        return $run->employees()->where('employee_id', $employeeId)->firstOrFail()->items()->where('code', $code)->firstOrFail();
    }

    public function test_alur_lengkap_preview_ubah_sel_generate_setujui(): void
    {
        $rizky = $this->id('Rizky Maulana');
        $run = $this->runBaru([$rizky, $this->id('Nadia Putri')]);

        $this->assertSame(['2026-10-26', '2026-11-25', 'idle', 2], [$run->period_start->toDateString(), $run->period_end->toDateString(), $run->job_status, $run->employees()->count()]);
        $this->assertSame('5700000.00', $this->sel($run, $rizky, 'gaji_pokok')->final_amount);
        $this->assertSame('50000.00', $this->sel($run, $rizky, 'lembur')->final_amount);
        $this->assertSame('690000.00', $this->sel($run, $rizky, 'uang_makan')->final_amount, '660000/22*23 hadir (26 Okt-25 Nov)');
        $this->assertSame('64400.00', $this->sel($run, $rizky, 'pph21')->final_amount, '1% fiktif dari 6440000');

        // ubah sel: override, PPh21 ikut berubah, tanpa audit log
        $re = $run->employees()->where('employee_id', $rizky)->firstOrFail();
        $audit = DB::table('audit_logs')->count();
        $this->actingAs($this->hr)->put("/pay/run/{$run->id}/karyawan/{$re->id}", ['code' => 'lembur', 'nilai' => '1.000.000'])->assertSessionHasNoErrors();
        $lembur = $this->sel($run, $rizky, 'lembur');
        $this->assertSame(['50000.00', '1000000.00', true], [$lembur->calculated_amount, $lembur->final_amount, $lembur->is_overridden]);
        $this->assertSame('73900.00', $this->sel($run, $rizky, 'pph21')->final_amount, '1% dari 7390000');
        $this->assertSame($audit, DB::table('audit_logs')->count(), 'edit sel tidak dicatat');

        // hitung ulang (preview lagi) tidak menimpa override
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/pratinjau", ['master_id' => $this->master->id, 'scope' => 'individual', 'pilihan' => [$rizky, $this->id('Nadia Putri')]]);
        $this->assertSame('1000000.00', $this->sel($run, $rizky, 'lembur')->final_amount);

        // kembalikan ke hasil rumus, lalu override lagi untuk dibawa ke generate
        $this->actingAs($this->hr)->put("/pay/run/{$run->id}/karyawan/{$re->id}", ['code' => 'lembur', 'kembalikan' => 1]);
        $this->assertSame(['50000.00', false], [$this->sel($run, $rizky, 'lembur')->final_amount, $this->sel($run, $rizky, 'lembur')->is_overridden]);
        $this->actingAs($this->hr)->put("/pay/run/{$run->id}/karyawan/{$re->id}", ['code' => 'lembur', 'nilai' => '75000']);

        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate")->assertSessionHasNoErrors();
        $run->refresh();
        $this->assertSame([PayrollRun::PENDING, $this->managerHr->id], [$run->status, (int) $run->approver_user_id]);
        $this->assertTrue((bool) $run->employees()->where('employee_id', $rizky)->value('is_blocking'));
        $this->assertSame('75000.00', $this->sel($run, $rizky, 'lembur')->final_amount, 'override dibawa ke generate');
        $this->assertNotNull($this->master->fresh()->currentVersion->locked_at, 'versi master terkunci');
        $this->assertNotNull(ReferenceTableVersion::firstOrFail()->locked_at, 'versi tabel referensi terkunci');
        $this->assertSame(1, DB::table('pay_run_reference_versions')->where('run_id', $run->id)->count());

        // setelah generate: tidak bisa diedit / dihapus
        $this->actingAs($this->hr)->put("/pay/run/{$run->id}/karyawan/{$re->id}", ['code' => 'lembur', 'nilai' => '1'])->assertSessionHasErrors('run');
        $this->actingAs($this->hr)->delete("/pay/run/{$run->id}")->assertSessionHasErrors('run');

        // hanya approver yang bisa melihat & menyetujui
        $managerIt = User::where('username', 'manager.it')->firstOrFail();
        $this->actingAs($managerIt)->get("/pay/approval/{$run->id}")->assertNotFound();
        $this->actingAs($managerIt)->post("/pay/approval/{$run->id}/setujui")->assertForbidden();

        $this->actingAs($this->managerHr)->get('/pay/approval')->assertOk()->assertSee('Payroll November 2026');
        $this->actingAs($this->managerHr)->post("/pay/approval/{$run->id}/setujui")->assertRedirect();
        $this->assertSame(PayrollRun::APPROVED, $run->fresh()->status);
        $this->assertSame(2, TaxMonthlyHistory::where('run_id', $run->id)->count());
        $this->assertSame([2026, 11], [TaxMonthlyHistory::first()->tax_year, TaxMonthlyHistory::first()->tax_month]);
        $this->assertSame(['generated', 'approved'], DB::table('audit_logs')->where('entity_type', PayrollRun::class)->orderBy('id')->pluck('action')->all());
    }

    public function test_cegah_payroll_ganda_lalu_ditolak_dan_dibuat_ulang(): void
    {
        $rizky = $this->id('Rizky Maulana');
        $pertama = $this->runBaru([$rizky]);
        $this->actingAs($this->hr)->post("/pay/run/{$pertama->id}/generate");

        $kedua = $this->runBaru([$rizky, $this->id('Nadia Putri')]);
        $this->assertSame([$this->id('Nadia Putri')], $kedua->employees()->pluck('employee_id')->all());
        $this->assertSame('sudah punya payroll aktif di periode ini', collect($kedua->skipped_employees)->firstWhere('employee_id', $rizky)['alasan']);

        // penjaga di database: unique key
        $this->expectsDatabaseUnique(fn () => RunEmployee::whereKey($kedua->employees()->value('id'))->update(['employee_id' => $rizky, 'is_blocking' => true]));

        $this->actingAs($this->managerHr)->post("/pay/approval/{$pertama->id}/tolak", ['alasan' => ''])->assertSessionHasErrors('alasan');
        $this->actingAs($this->managerHr)->post("/pay/approval/{$pertama->id}/tolak", ['alasan' => 'Lembur salah'])->assertRedirect();
        $this->assertSame(PayrollRun::REJECTED, $pertama->fresh()->status);
        $this->assertFalse((bool) $pertama->employees()->value('is_blocking'));

        $ketiga = $this->runBaru([$rizky]);
        $this->assertSame([$rizky], $ketiga->employees()->pluck('employee_id')->all(), 'karyawan bebas lagi setelah ditolak');
    }

    public function test_kunci_periode_absensi_setelah_generate(): void
    {
        $rizky = $this->id('Rizky Maulana');
        $run = $this->runBaru([$rizky]);
        $data = ['nik' => Employee::findOrFail($rizky)->nik, 'tanggal' => '2026-11-08', 'jam_masuk' => '08:00'];

        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate");
        $this->actingAs($this->hr)->from('/pay/absensi')->post('/pay/absensi', $data)->assertSessionHasErrors('data');

        $this->actingAs($this->managerHr)->post("/pay/approval/{$run->id}/tolak", ['alasan' => 'ulang']);
        $this->actingAs($this->hr)->post('/pay/absensi', $data)->assertSessionHasNoErrors();
    }

    public function test_hapus_draft_tanpa_approver_dan_error_rumus(): void
    {
        $run = $this->runBaru([$this->id('Rizky Maulana')]);
        $this->actingAs($this->hr)->delete("/pay/run/{$run->id}")->assertRedirect();
        $this->assertSame(0, PayrollRun::count());
        $this->assertSame(0, RunEmployee::count());

        $this->hr->forceFill(['approver_user_id' => null])->save();
        $run = $this->runBaru([$this->id('Rizky Maulana')]);
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate")->assertSessionHasErrors('run');
        $this->assertSame(PayrollRun::DRAFT, $run->fresh()->status);

        // karyawan tanpa kontrak dilewati dengan alasan
        $joko = $this->id('Joko Susilo');
        $run2 = $this->runBaru([$joko]);
        $this->assertSame('tidak punya kontrak aktif', collect($run2->skipped_employees)->firstWhere('employee_id', $joko)['alasan'] ?? null);
    }

    public function test_run_perusahaan_lain_tidak_bisa_dibuka(): void
    {
        $b = $this->perusahaanLain();
        $runB = app(CurrentCompany::class)->runAs((int) $b['company']->id, fn () => PayrollRun::create([
            'title' => 'Rahasia B', 'period_start' => '2026-10-26', 'period_end' => '2026-11-25', 'created_by' => $b['users']['HR']->id,
        ]));
        $this->actingAs($this->hr)->get("/pay/run/{$runB->id}")->assertNotFound();
        $this->actingAs($this->hr)->get('/pay/run')->assertDontSee('Rahasia B');
        $this->actingAs($this->hr)->delete("/pay/run/{$runB->id}")->assertNotFound();
    }

    private function expectsDatabaseUnique(\Closure $f): void
    {
        try {
            $f();
            $this->fail('unique key uq_pre_one_active_per_period seharusnya menolak');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
