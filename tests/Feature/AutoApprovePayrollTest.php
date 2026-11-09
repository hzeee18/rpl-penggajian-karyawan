<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Payroll;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoApprovePayrollTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function siapkanDraft(): Payroll
    {
        PayrollService::$tanpaPdf = true;
        $p = Payroll::where('periode', '2026-11')->firstOrFail();
        Approval::where('jenis_ref', 'payroll')->where('ref_id', $p->id)->delete();
        $p->update(['status' => 'draft']);

        return $p;
    }

    public function test_manager_hr_generate_langsung_final(): void
    {
        $this->siapkanDraft();
        $this->actingAs(User::where('username', 'manager.hr')->first())
            ->post('/payroll/generate', ['periode' => '2026-11'])
            ->assertSessionHas('sukses', fn ($m) => str_contains($m, 'langsung disetujui'));

        $p = Payroll::where('periode', '2026-11')->first();
        $this->assertSame('final', $p->status);
        $this->assertSame('disetujui', Approval::where('jenis_ref', 'payroll')->where('ref_id', $p->id)->whereHas('approver', fn ($q) => $q->where('username', 'manager.hr'))->value('status'));
    }

    public function test_hr_generate_tetap_menunggu_manager_hr(): void
    {
        $this->siapkanDraft();
        $this->actingAs(User::where('username', 'hr')->first())
            ->post('/payroll/generate', ['periode' => '2026-11'])
            ->assertSessionHas('sukses', fn ($m) => str_contains($m, 'dikirim ke Manager HR'));

        $this->assertSame('menunggu', Payroll::where('periode', '2026-11')->value('status'));
    }
}
