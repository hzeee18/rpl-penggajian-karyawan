<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Payroll;
use App\Models\PayslipComponent;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiManagerRumusTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_payroll_diajukan_ke_semua_manager_hr_dan_cukup_satu_yang_memutuskan(): void
    {
        PayrollService::$tanpaPdf = true;
        $p = Payroll::where('periode', now()->format('Y-m'))->firstOrFail();
        $this->actingAs(User::where('username', 'hr')->first());
        app(ApprovalService::class)->ajukanPayroll($p);

        $daftar = Approval::where('jenis_ref', 'payroll')->where('ref_id', $p->id)->get();
        $this->assertCount(2, $daftar, 'dua Manager HR sama-sama dapat pengajuan');

        $hr2 = User::where('username', 'manager.hr2')->first();
        app(ApprovalService::class)->setujui($daftar->firstWhere('approver_id', $hr2->id), $hr2);

        $this->assertSame('final', $p->fresh()->status);
        $this->assertSame(['dilewati', 'disetujui'], Approval::where('jenis_ref', 'payroll')->where('ref_id', $p->id)->pluck('status')->sort()->values()->all());
    }

    public function test_rumus_komponen_ditolak_kalau_melingkar_atau_salah(): void
    {
        $hr = User::where('username', 'hr')->first();
        $this->actingAs($hr)->post('/komponen', ['kode' => 'TJ', 'nama' => 'Tunjangan', 'jenis' => 'penambah', 'berlaku' => 'kontrak', 'rumus' => 'UM * 10%', 'status_berlaku' => 'aktif'])
            ->assertSessionHasNoErrors();
        $um = PayslipComponent::where('kode', 'UM')->first();
        $this->actingAs($hr)->put('/komponen/' . $um->id, ['kode' => 'UM', 'nama' => 'Uang Makan', 'jenis' => 'penambah', 'rumus' => 'TJ + 1', 'status_berlaku' => 'aktif'])
            ->assertSessionHasErrors('rumus');
        $this->actingAs($hr)->put('/komponen/' . $um->id, ['kode' => 'UM', 'nama' => 'Uang Makan', 'jenis' => 'penambah', 'rumus' => 'GP * (15%', 'status_berlaku' => 'aktif'])
            ->assertSessionHasErrors('rumus');
    }

    public function test_rumus_dipakai_saat_hitung_gaji(): void
    {
        PayslipComponent::where('kode', 'UT')->update(['rumus' => '123.000']);
        $svc = app(PayrollService::class);
        $periode = now()->format('Y-m');
        $rekap = $svc->rekap($periode);
        $b = $rekap['baris']->first(fn ($b) => $b['karyawan']->kontrakAktif->komponen->contains(fn ($kk) => $kk->component->kode === 'UT'));
        $hasil = collect($svc->hitungKaryawan($b['karyawan'], $b['rekap'], $rekap['master'], $rekap['akhir']))->first(fn ($h) => $h['c']->kode === 'UT');
        $this->assertEquals(123000, $hasil['nominal']);
    }
}
