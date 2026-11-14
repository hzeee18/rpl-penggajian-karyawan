<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Domain\Company\Models\Company;
use App\Models\User;
use Database\Seeders\KaryawanMassalSeeder;
use Database\Seeders\SkenarioMultiTenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** AUDIT.md §75: seluruh data uji multi-tenant (Super Admin, MTS, BNS, SBR) dan isolasinya. */
final class SkenarioMultiTenantSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_skenario_multi_tenant_lengkap_dan_terisolasi(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-14 09:00', 'Asia/Jakarta'));
        Storage::fake('local');
        KaryawanMassalSeeder::$jumlah = 20;
        try {
            $this->seed(SkenarioMultiTenantSeeder::class);
            $this->seed(SkenarioMultiTenantSeeder::class); // kedua kali: dilewati
        } finally {
            KaryawanMassalSeeder::$jumlah = 110;
        }

        $sa = User::withoutGlobalScopes()->where('username', 'superadmin')->firstOrFail();
        $this->assertNull($sa->company_id);
        foreach (['MTS', 'BNS', 'SBR'] as $kode) {
            $c = Company::where('code', $kode)->firstOrFail();
            foreach (['admin', 'hr', 'manager', 'staf'] as $peran) {
                $this->assertTrue(User::withoutGlobalScopes()->where('username', $peran.'.'.strtolower($kode))->where('company_id', $c->id)->exists(), "{$peran}.{$kode}");
            }
        }
        // isolasi: HR BNS hanya melihat karyawan BNS (NIK N....), bukan SBR (S....) atau MTS (U.../M...)
        $hrBns = User::withoutGlobalScopes()->where('username', 'hr.bns')->firstOrFail();
        $this->actingAs($hrBns)->get('/pay/kontrak')->assertOk()->assertSee('N0001')->assertDontSee('S0001')->assertDontSee('U0001')->assertDontSee('M0001');
        // Super Admin melihat daftar semua perusahaan
        $this->actingAs($sa)->get('/perusahaan')->assertOk()->assertSee('PT Maju Terus Sejahtera')->assertSee('PT Sinar Baru Raya')->assertSee('PT Bina Nusa Sentosa');

        // hapus permanen perusahaan yang punya payroll lengkap (MTS: payroll final + slip): tidak tersisa satu baris pun
        $mts = Company::where('code', 'MTS')->firstOrFail();
        $this->actingAs($sa)->delete("/perusahaan/{$mts->id}", ['konfirmasi_kode' => 'MTS'])->assertSessionHasNoErrors()->assertRedirect();
        foreach (\Illuminate\Support\Facades\Schema::getTableListing(schemaQualified: false) as $t) {
            if ($t !== 'companies' && \Illuminate\Support\Facades\Schema::hasColumn($t, 'company_id')) {
                $this->assertSame(0, \Illuminate\Support\Facades\DB::table($t)->where('company_id', $mts->id)->count(), $t);
            }
        }
        $this->assertTrue(Company::where('code', 'BNS')->exists());
        $this->assertFalse((bool) Company::where('code', 'BNS')->value('payroll_butuh_approval'), 'default tanpa approval (AUDIT.md §77)');
    }
}
