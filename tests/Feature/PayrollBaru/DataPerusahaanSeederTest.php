<?php

declare(strict_types=1);

namespace Tests\Feature\PayrollBaru;

use App\Domain\Company\Actions\BuatPerusahaan;
use App\Domain\Company\Models\Company;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Perintah payroll:isi-data: data contoh lengkap TANPA rumus untuk perusahaan baru yang kosong. */
final class DataPerusahaanSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-12 09:00', 'Asia/Jakarta'));
    }

    private function perusahaanBaru(string $kode): Company
    {
        return app(BuatPerusahaan::class)->handle(['code' => $kode, 'name' => "PT {$kode}"]);
    }

    public function test_mengisi_semua_data_contoh_tanpa_master_payroll(): void
    {
        $c = $this->perusahaanBaru('PTX');

        $this->artisan('payroll:isi-data', ['kode' => 'PTX', '--karyawan' => 8])->assertSuccessful();

        $id = $c->id;
        $hitung = fn (string $t) => DB::table($t)->where('company_id', $id)->count();
        $this->assertSame(8, $hitung('karyawan'));
        $this->assertSame(2, $hitung('departemen'));
        $this->assertSame(8, DB::table('pay_contracts')->where('company_id', $id)->where('is_active', true)->count());
        $this->assertSame(9, $hitung('pay_components'));
        $this->assertSame(4, $hitung('pay_leave_types'));
        $this->assertSame(5, $hitung('pay_holidays'));
        $this->assertSame(1, $hitung('pay_period_configs'));
        $this->assertGreaterThan(0, $hitung('pay_attendances'));
        $this->assertGreaterThan(0, $hitung('pay_leaves'));
        $this->assertGreaterThan(0, $hitung('pay_overtimes'));
        $this->assertSame(0, $hitung('pay_masters'), 'rumus diisi pemilik sendiri');
        $this->assertSame(['2026-10-01', '2026-11-30'], [
            DB::table('pay_attendances')->where('company_id', $id)->min('attendance_date'),
            DB::table('pay_attendances')->where('company_id', $id)->max('attendance_date'),
        ]);

        // user per role + approver HR = Manager, dan bisa login
        $users = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->where('users.company_id', $id)->pluck('roles.nama', 'users.username')->all();
        $this->assertSame(['manager.ptx' => Role::MANAGER, 'hr.ptx' => Role::HR, 'admin.ptx' => Role::ADMIN, 'staf.ptx' => Role::KARYAWAN], $users);
        $this->assertSame(DB::table('users')->where('username', 'manager.ptx')->value('id'), DB::table('users')->where('username', 'hr.ptx')->value('approver_user_id'));
        $this->post('/login', ['username' => 'hr.ptx', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/pay/kontrak')->assertOk()->assertSee('PTX0001');

        // tabel TER nasional ikut terpasang dari file resmi
        $this->assertSame(2, DB::table('pay_reference_tables')->whereNull('company_id')->whereIn('code', ['ter_bulanan', 'kategori_ptkp'])->count());

        // perusahaan lain tidak tersentuh
        $this->assertSame(0, DB::table('pay_contracts')->where('company_id', '!=', $id)->count());
    }

    public function test_menolak_perusahaan_yang_sudah_berisi_atau_tidak_ada(): void
    {
        $this->artisan('payroll:isi-data', ['kode' => 'TIDAKADA'])->assertFailed();
        $this->artisan('payroll:isi-data', ['kode' => Company::DEFAULT_CODE])->assertFailed(); // sudah punya karyawan

        $this->perusahaanBaru('PTY');
        $this->artisan('payroll:isi-data', ['kode' => 'PTY'])->assertSuccessful();
        $sebelum = DB::table('karyawan')->count();
        $this->artisan('payroll:isi-data', ['kode' => 'PTY'])->assertFailed();
        $this->assertSame($sebelum, DB::table('karyawan')->count());
    }
}
