<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenant;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Models\Departemen;
use App\Models\Karyawan;
use App\Models\Privilege;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 1b: isolasi dasar lewat HTTP dengan data seed lama (perusahaan DEFAULT) + perusahaan B.
 * Tes kebocoran menyeluruh per model / halaman / export ada di fase 1e.
 */
final class IsolasiDasarTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const TENANT_TABLES = [
        'users', 'roles', 'privileges',
        'karyawan', 'departemen', 'departemen_manager',
        'kontrak', 'kontrak_komponen',
        'attendance', 'leave', 'overtime',
        'notifikasi', 'approval', 'approval_mapping', 'log_aktivitas', 'import_excel',
        'payslip_component', 'master_payroll', 'payroll', 'payroll_detail', 'payslip',
    ];

    /** @return array{company: Company, hr: User, karyawan: Karyawan} */
    private function perusahaanB(): array
    {
        $b = Company::create(['code' => 'B', 'name' => 'Perusahaan B']);

        return app(CurrentCompany::class)->runAs($b->id, function () use ($b) {
            $hrDefault = Role::withoutGlobalScopes()->where('company_id', $this->defaultId())->where('nama', Role::HR)->firstOrFail();
            $role = Role::create(['nama' => Role::HR]);
            foreach (Privilege::withoutGlobalScopes()->where('role_id', $hrDefault->id)->get() as $p) {
                Privilege::create(['role_id' => $role->id] + $p->only(['menu_id', 'can_view', 'can_create', 'can_update', 'can_delete', 'can_approve']));
            }
            $dept = Departemen::create(['kode' => 'BX', 'nama' => 'Departemen Rahasia B']);
            $karyawan = Karyawan::create(['nik' => 'B0001', 'nama' => 'Karyawan Rahasia B', 'departemen_id' => $dept->id]);
            $hr = User::create(['role_id' => $role->id, 'username' => 'hr.b', 'password' => 'password', 'is_active' => true]);

            return ['company' => $b, 'hr' => $hr, 'karyawan' => $karyawan];
        });
    }

    private function defaultId(): int
    {
        return (int) Company::where('code', Company::DEFAULT_CODE)->value('id');
    }

    public function test_seed_lama_tidak_menyisakan_company_id_kosong(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->whereNull('company_id')->count(), "{$table} punya company_id NULL");
        }
    }

    public function test_hr_perusahaan_lain_tidak_terlihat_dan_tidak_bisa_dibuka(): void
    {
        $b = $this->perusahaanB();
        $hrA = User::where('username', 'hr')->firstOrFail();

        $this->actingAs($hrA)->get('/karyawan')->assertOk()->assertDontSee('Karyawan Rahasia B');
        $this->actingAs($hrA)->get('/departemen')->assertOk()->assertDontSee('Departemen Rahasia B');
        $this->actingAs($hrA)->get("/karyawan/{$b['karyawan']->id}")->assertNotFound();
        $this->actingAs($hrA)->get("/karyawan/{$b['karyawan']->id}/edit")->assertNotFound();
    }

    public function test_hr_perusahaan_b_hanya_melihat_datanya(): void
    {
        $b = $this->perusahaanB();
        $rizky = Karyawan::where('nama', 'Rizky Maulana')->firstOrFail();

        $this->actingAs($b['hr'])->get('/karyawan')->assertOk()->assertSee('Karyawan Rahasia B')->assertDontSee('Rizky Maulana');
        $this->actingAs($b['hr'])->get("/karyawan/{$rizky->id}")->assertNotFound();
        $this->actingAs($b['hr'])->get("/karyawan/{$b['karyawan']->id}")->assertOk()->assertSee('Karyawan Rahasia B');
    }

    public function test_data_baru_lewat_http_masuk_ke_perusahaan_user(): void
    {
        $b = $this->perusahaanB();
        $hrA = User::where('username', 'hr')->firstOrFail(); // diambil sebelum actingAs B (setelahnya konteks = B)

        $this->actingAs($b['hr'])->post('/departemen', ['kode' => 'BK', 'nama' => 'Keuangan B'])->assertRedirect();
        $this->actingAs($hrA)->post('/departemen', ['kode' => 'AK', 'nama' => 'Akuntansi A'])->assertRedirect();

        $this->assertDatabaseHas('departemen', ['kode' => 'BK', 'company_id' => $b['company']->id]);
        $this->assertDatabaseHas('departemen', ['kode' => 'AK', 'company_id' => $this->defaultId()]);
    }

    public function test_user_perusahaan_b_bisa_login_lewat_form_dan_sesinya_bertahan(): void
    {
        $b = $this->perusahaanB();

        $this->post('/login', ['username' => 'hr.b', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->app['auth']->forgetGuards(); // request berikut memuat user dari sesi (provider tanpa scope)

        $this->get('/karyawan')->assertOk()->assertSee('Karyawan Rahasia B')->assertDontSee('Rizky Maulana');
        $this->assertSame($b['company']->id, (int) auth()->user()->company_id);
    }

    public function test_salah_password_user_perusahaan_b_hanya_memberi_notifikasi_admin_perusahaannya(): void
    {
        $b = $this->perusahaanB();
        $adminDefault = User::where('username', 'admin')->firstOrFail();
        $sebelum = DB::table('notifikasi')->where('user_id', $adminDefault->id)->count();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'hr.b', 'password' => 'salah']);
        }

        $this->assertTrue((bool) DB::table('users')->where('id', $b['hr']->id)->value('is_locked'), 'akun B terkunci');
        $this->assertSame($sebelum, DB::table('notifikasi')->where('user_id', $adminDefault->id)->count(), 'Admin DEFAULT tidak boleh menerima notifikasi user B');
    }
}
