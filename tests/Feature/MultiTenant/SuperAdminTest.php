<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenant;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CompanyScope;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Fase 1d: Super Admin (platform), pemilihan perusahaan, hak akses per perusahaan. */
final class SuperAdminTest extends TestCase
{
    use BuatPerusahaanLain;
    use RefreshDatabase;

    protected bool $seed = true;

    private function buatSuperAdmin(): User
    {
        $this->artisan('super-admin:buat', ['username' => 'super', '--password' => 'rahasia123'])->assertSuccessful();

        return User::withoutGlobalScope(CompanyScope::class)->where('username', 'super')->firstOrFail();
    }

    public function test_perintah_membuat_akun_platform_tanpa_perusahaan(): void
    {
        $super = $this->buatSuperAdmin();

        $this->assertNull($super->company_id);
        $this->assertSame('Super Admin', $super->role->nama);
        $this->assertNull($super->role->company_id);
        $this->artisan('super-admin:buat', ['username' => 'super'])->assertFailed();
    }

    public function test_login_lalu_diarahkan_ke_perusahaan_dan_tanpa_pilihan_tidak_melihat_data(): void
    {
        $this->buatSuperAdmin();

        $this->post('/login', ['username' => 'super', 'password' => 'rahasia123'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect(route('perusahaan.index'));
        $this->get('/perusahaan')->assertOk()->assertSee('DEFAULT');

        $this->get('/users')->assertOk()->assertDontSee('rizky');
        $this->get('/karyawan')->assertForbidden();
    }

    public function test_memilih_perusahaan_lalu_bekerja_di_perusahaan_itu_saja(): void
    {
        $super = $this->buatSuperAdmin();
        $b = $this->perusahaanLain('B', [Role::ADMIN]);
        $default = Company::where('code', 'DEFAULT')->firstOrFail();

        // daftar user berhalaman: cari lewat ?q=, lalu cek nama karyawan di baris (kata kunci ikut tampil di kotak cari)
        $this->actingAs($super)->post("/perusahaan/{$default->id}/pilih")->assertRedirect();
        $this->get('/users?q=rizky')->assertOk()->assertSee('Rizky Maulana');
        $this->get('/users?q=admin.b')->assertOk()->assertDontSee('admin.b</', false);
        $this->get('/perusahaan')->assertSee('Super Admin · DEFAULT');

        $this->post("/perusahaan/{$b['company']->id}/pilih");
        $this->get('/users?q=admin.b')->assertOk()->assertSee('admin.b</', false);
        $this->get('/users?q=rizky')->assertOk()->assertDontSee('Rizky Maulana');

        $this->post('/perusahaan/keluar');
        $this->get('/users?q=rizky')->assertOk()->assertDontSee('Rizky Maulana');
        $this->get('/users?q=admin.b')->assertOk()->assertDontSee('admin.b</', false);
    }

    public function test_perusahaan_baru_mendapat_role_hak_akses_dan_approval_bawaan(): void
    {
        $super = $this->buatSuperAdmin();

        $this->actingAs($super)->post('/perusahaan', ['code' => 'PTC', 'name' => 'PT Contoh'])->assertSessionHasNoErrors();

        $baru = Company::where('code', 'PTC')->firstOrFail();
        $roles = DB::table('roles')->where('company_id', $baru->id)->orderBy('id')->pluck('nama')->all();
        $this->assertSame([Role::ADMIN, Role::HR, Role::MANAGER, Role::KARYAWAN], $roles);
        // hak bawaan disalin dari DEFAULT (lalu modul payroll baru ditambahkan, AUDIT.md §29)
        $pasangan = fn (int $cid) => DB::table('privileges')->join('roles', 'roles.id', '=', 'privileges.role_id')->join('menus', 'menus.id', '=', 'privileges.menu_id')
            ->where('privileges.company_id', $cid)->get(['roles.nama', 'menus.url'])->map(fn ($r) => "{$r->nama}:{$r->url}")->all();
        $this->assertSame([], array_diff($pasangan($this->defaultId()), $pasangan((int) $baru->id)));
        $this->assertSame(DB::table('approval_mapping')->where('company_id', $this->defaultId())->count(), DB::table('approval_mapping')->where('company_id', $baru->id)->count());
    }

    public function test_perusahaan_baru_langsung_memakai_payroll_baru(): void
    {
        $super = $this->buatSuperAdmin();
        $hak = fn (string $kode, string $url) => DB::table('privileges')->join('roles', 'roles.id', '=', 'privileges.role_id')
            ->where('roles.company_id', Company::where('code', $kode)->value('id'))->where('roles.nama', Role::HR)
            ->where('privileges.menu_id', DB::table('menus')->where('url', $url)->value('id'))->value('privileges.can_view');

        $this->actingAs($super)->post('/perusahaan', ['code' => 'PTP', 'name' => 'PT Payroll'])->assertSessionHasNoErrors();

        $this->assertTrue(Company::where('code', 'PTP')->firstOrFail()->payroll_baru);
        $this->assertTrue((bool) $hak('PTP', 'pay-run'));
        $this->assertFalse((bool) $hak('PTP', 'kontrak'), 'menu Kontrak lama disembunyikan');
        $this->assertFalse(Company::where('code', 'DEFAULT')->firstOrFail()->payroll_baru, 'perusahaan lain tidak ikut berubah');
        $this->assertTrue((bool) $hak('DEFAULT', 'kontrak'));
        $this->assertNull($hak('DEFAULT', 'pay-run'));
    }

    public function test_hak_pajak_dan_menu_perusahaan(): void
    {
        $super = $this->buatSuperAdmin();
        $hr = User::where('username', 'hr')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->assertTrue($super->bisa('pajak', 'update'));
        $this->assertTrue($hr->bisa('pajak'));
        $this->assertFalse($hr->bisa('pajak', 'update'), 'tabel TER nasional hanya diubah Super Admin');
        $this->actingAs($hr)->put('/pajak/tarif/'.DB::table('tarif_ter')->value('id'), [])->assertForbidden();

        $this->actingAs($admin)->get('/perusahaan')->assertForbidden();
    }

    public function test_admin_perusahaan_tidak_bisa_memakai_role_super_admin(): void
    {
        $this->buatSuperAdmin();
        $admin = User::where('username', 'admin')->firstOrFail();
        $roleSuper = DB::table('roles')->whereNull('company_id')->where('nama', 'Super Admin')->value('id');

        $this->actingAs($admin)->get('/roles')->assertOk()->assertDontSee('Super Admin');
        $this->actingAs($admin)->from('/users')
            ->post('/users', ['username' => 'naik.pangkat', 'role_id' => $roleSuper, 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertSessionHasErrors('role_id');
    }

    public function test_menu_perusahaan_tidak_bisa_diberikan_atau_dihapus_admin_perusahaan(): void
    {
        $this->buatSuperAdmin();
        $admin = User::where('username', 'admin')->firstOrFail();
        $hr = User::where('username', 'hr')->firstOrFail();
        $menuPerusahaan = DB::table('menus')->where('url', 'perusahaan')->value('id');

        $this->actingAs($admin)->put("/roles/{$hr->role_id}/hak-akses", ['akses' => [$menuPerusahaan => ['view' => 1, 'create' => 1]]]);
        $this->assertFalse(DB::table('privileges')->where('role_id', $hr->role_id)->where('menu_id', $menuPerusahaan)->exists());
        $this->actingAs($admin)->get('/menus?q=perusahaan')->assertForbidden(); // Manajemen Menu khusus Super Admin (AUDIT.md §26)
        $this->actingAs($admin)->delete("/menus/{$menuPerusahaan}")->assertForbidden();

        // lapisan kedua: walau hak menu dipaksa masuk tabel, halaman tetap khusus Super Admin
        DB::table('privileges')->insert(['company_id' => $this->defaultId(), 'role_id' => $hr->role_id, 'menu_id' => $menuPerusahaan,
            'can_view' => true, 'can_create' => true, 'can_update' => true, 'can_delete' => true, 'can_approve' => false]);
        $this->actingAs($hr)->get('/perusahaan')->assertForbidden();
        $this->actingAs($hr)->post('/perusahaan', ['code' => 'X', 'name' => 'X'])->assertForbidden();
    }

    public function test_akun_super_admin_terkunci_tanpa_notifikasi_ke_admin_perusahaan(): void
    {
        $super = $this->buatSuperAdmin();
        $sebelum = DB::table('notifikasi')->count();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'super', 'password' => 'salah']);
        }

        $this->assertTrue((bool) DB::table('users')->where('id', $super->id)->value('is_locked'));
        $this->assertSame($sebelum, DB::table('notifikasi')->count());
    }
}
