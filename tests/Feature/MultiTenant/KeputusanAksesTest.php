<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenant;

use App\Domain\Company\Tenancy\CompanyScope;
use App\Domain\Payroll\Models\PeriodConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Keputusan pemilik (AUDIT.md §26): Manajemen Menu khusus Super Admin; payroll hanya dibuat role HR. */
final class KeputusanAksesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_manajemen_menu_hanya_super_admin(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->assertFalse($admin->bisa('menus'));
        $this->actingAs($admin)->get('/menus')->assertForbidden();
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertDontSee('Manajemen Menu');

        $this->artisan('super-admin:buat', ['username' => 'super', '--password' => 'rahasia123']);
        $super = User::withoutGlobalScope(CompanyScope::class)->where('username', 'super')->firstOrFail();
        $this->actingAs($super)->get('/menus')->assertOk()->assertSee('Manajemen Menu');
    }

    public function test_payroll_hanya_dibuat_role_hr_bukan_hak_warisan_manager_hr(): void
    {
        $this->artisan('payroll:aktifkan', ['kode' => 'DEFAULT']);
        PeriodConfig::create(['start_day' => 1, 'end_day' => 31, 'start_in_previous_month' => false]);
        $managerHr = User::where('username', 'manager.hr')->firstOrFail();
        $hr = User::where('username', 'hr')->firstOrFail();

        $this->assertTrue($managerHr->bisa('pay-run', 'create'), 'Manager HR mewarisi hak HR di modul lama');
        $this->actingAs($managerHr)->post('/pay/run', ['title' => 'X', 'periode' => '2026-11'])->assertForbidden();
        $this->actingAs($hr)->post('/pay/run', ['title' => 'Payroll HR', 'periode' => '2026-11'])->assertSessionHasNoErrors()->assertRedirect();
    }
}
