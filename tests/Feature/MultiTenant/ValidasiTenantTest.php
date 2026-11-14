<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenant;

use App\Models\Karyawan;
use App\Models\Pajak;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Fase 1c: validasi unique/exists per perusahaan dan file slip per perusahaan. */
final class ValidasiTenantTest extends TestCase
{
    use BuatPerusahaanLain;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_kode_departemen_dan_nik_boleh_sama_dengan_perusahaan_lain(): void
    {
        $b = $this->perusahaanLain();
        $hrB = $b['users']['HR'];
        $pajakId = Pajak::value('id');

        $this->actingAs($hrB)->post('/departemen', ['kode' => 'IT', 'nama' => 'IT B'])->assertSessionHasNoErrors();
        $this->actingAs($hrB)->from('/departemen')->post('/departemen', ['kode' => 'IT', 'nama' => 'IT B lagi'])->assertSessionHasErrors('kode');

        $karyawan = [
            'nik' => 'K0001', 'nama' => 'Nik Kembar', 'departemen_id' => $b['departemen']->id, 'jabatan' => 'Staf',
            'tgl_masuk' => '2026-01-05', 'pajak_id' => $pajakId, 'status_kepegawaian' => 'kontrak', 'sisa_cuti' => 12, 'is_active' => 1,
        ];
        $this->actingAs($hrB)->post('/karyawan', $karyawan)->assertSessionHasNoErrors();
        $this->actingAs($hrB)->from('/karyawan')->post('/karyawan', $karyawan)->assertSessionHasErrors('nik');

        $this->assertDatabaseHas('karyawan', ['nik' => 'K0001', 'company_id' => $b['company']->id]);
        $this->assertDatabaseHas('karyawan', ['nik' => 'K0001', 'company_id' => $this->defaultId()]);
    }

    public function test_tidak_bisa_merujuk_data_perusahaan_lain(): void
    {
        $b = $this->perusahaanLain();
        $hrB = $b['users']['HR'];
        $karyawanA = Karyawan::where('nama', 'Rizky Maulana')->firstOrFail();
        $deptA = $karyawanA->departemen_id;

        $this->actingAs($hrB)->from('/departemen')
            ->post('/departemen', ['kode' => 'BM', 'nama' => 'Dept B', 'manager_ids' => [$karyawanA->id]])
            ->assertSessionHasErrors('manager_ids.0');

        $this->actingAs($hrB)->from('/karyawan')
            ->post('/karyawan', ['nik' => 'B0099', 'nama' => 'X', 'departemen_id' => $deptA, 'jabatan' => 'X', 'tgl_masuk' => '2026-01-01',
                'pajak_id' => Pajak::value('id'), 'status_kepegawaian' => 'kontrak', 'sisa_cuti' => 12])
            ->assertSessionHasErrors('departemen_id');


        $this->assertSame(0, DB::table('departemen')->where('kode', 'BM')->count());
    }

    public function test_role_dan_user_hanya_dari_perusahaan_sendiri(): void
    {
        $b = $this->perusahaanLain('B', [Role::ADMIN]);
        $adminB = $b['users'][Role::ADMIN];
        $roleHrA = Role::where('nama', Role::HR)->value('id');

        // nama role "HR" sudah ada di DEFAULT, tetap boleh dibuat di B
        $this->actingAs($adminB)->post('/roles', ['nama' => 'HR'])->assertSessionHasNoErrors();

        $this->actingAs($adminB)->from('/users')
            ->post('/users', ['username' => 'baru.b', 'role_id' => $roleHrA, 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertSessionHasErrors('role_id');
    }
}
