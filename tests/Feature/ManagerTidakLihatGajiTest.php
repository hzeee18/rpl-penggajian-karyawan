<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Karyawan;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Manager divisi (bukan Manager HR) tidak boleh melihat nominal gaji anggota timnya. */
class ManagerTidakLihatGajiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_manager_divisi_tidak_bisa_buka_slip_anggota(): void
    {
        $mgr = User::where('username', 'manager.it')->first();
        $rizky = Karyawan::where('nama', 'Rizky Maulana')->first();
        $slip = Payslip::where('karyawan_id', $rizky->id)->whereHas('payroll', fn ($q) => $q->where('status', 'final'))->first();
        $this->actingAs($mgr)->get('/slip/'.$slip->id)->assertForbidden();
        $this->actingAs($mgr)->get('/slip/'.$slip->id.'/unduh')->assertForbidden();
        $this->actingAs($mgr)->get('/slip')->assertOk()->assertDontSee($rizky->nama);
        $this->actingAs($mgr)->getJson('/cari?q=rizky')->assertOk()->assertDontSee('Slip ');
        foreach (['/laporan', '/payroll', '/laporan/'.$slip->payroll_id] as $url) {
            $this->actingAs($mgr)->get($url)->assertForbidden();
        }
    }

    public function test_tim_saya_detail_karyawan_dan_approval_kontrak_tanpa_nominal_gaji(): void
    {
        $mgr = User::where('username', 'manager.it')->first();
        $rizky = Karyawan::with('kontrakAktif')->where('nama', 'Rizky Maulana')->first();
        $gp = number_format($rizky->kontrakAktif->gaji_pokok, 0, ',', '.');

        $this->actingAs($mgr)->get('/karyawan?lihat='.$rizky->id)->assertOk()->assertDontSee($gp);
        $this->actingAs($mgr)->get('/karyawan/'.$rizky->id)->assertOk()->assertDontSee($gp)->assertSee('Rahasia');
        $this->actingAs($mgr)->get('/dashboard')->assertOk()->assertDontSee('Estimasi upah lembur');

        $a = Approval::where('jenis_ref', 'kontrak')->where('approver_id', $mgr->id)->where('status', 'menunggu')->first();
        $this->actingAs($mgr)->get('/approval/'.$a->id)->assertOk()->assertSee('Rahasia')->assertDontSee('8.000.000');
    }

    public function test_manager_hr_tetap_bisa_lihat_slip_semua(): void
    {
        $slip = Payslip::whereHas('payroll', fn ($q) => $q->where('status', 'final'))->first();
        $this->actingAs(User::where('username', 'manager.hr')->first())->get('/slip/'.$slip->id)->assertOk();
    }
}
