<?php

declare(strict_types=1);

namespace Tests\Feature\PayrollBaru;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CompanyScope;
use App\Domain\Payroll\Actions\PasangMenuPayroll;
use App\Domain\Payroll\ModulPayroll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Setiap tautan menu di sidebar (dan pencarian) harus membuka halaman yang ada, untuk semua role. */
final class MenuSidebarTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** @return list<string> href tautan menu di sidebar */
    private function tautanSidebar(string $html): array
    {
        preg_match_all('/<a href="([^"]+)" class="nav-item-link/', $html, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_semua_tautan_menu_sidebar_terbuka_untuk_semua_role(): void
    {
        $this->artisan('payroll:aktifkan', ['kode' => Company::DEFAULT_CODE]);
        $this->artisan('super-admin:buat', ['username' => 'super', '--password' => 'rahasia123']);

        $roleIds = DB::table('roles')->pluck('id');
        $diuji = [];
        foreach ($roleIds as $roleId) {
            $user = User::withoutGlobalScope(CompanyScope::class)->where('role_id', $roleId)->where('is_active', true)->orderBy('id')->first();
            if ($user === null) {
                continue;
            }
            // Super Admin diarahkan dari dashboard ke halaman Perusahaan
            $tautan = $this->tautanSidebar($this->actingAs($user)->followingRedirects()->get('/dashboard')->getContent());
            $this->assertNotEmpty($tautan, "sidebar {$user->username} kosong");
            foreach ($tautan as $href) {
                $status = $this->actingAs($user)->followingRedirects()->get($href)->status();
                $this->assertSame(200, $status, "{$user->username} membuka {$href}: HTTP {$status}");
                $diuji[] = parse_url($href, PHP_URL_PATH);
            }
        }

        // semua menu Payroll Baru ikut teruji dan berada di /pay/... (Karyawan Payroll digabung ke Data Karyawan)
        foreach (array_diff(array_keys(PasangMenuPayroll::MENU), array_keys(ModulPayroll::MENU_DIGANTIKAN)) as $url) {
            $this->assertContains('/'.PasangMenuPayroll::alamat($url), $diuji, "menu {$url} tidak muncul untuk role mana pun");
        }
    }

    public function test_menu_payroll_baru_aktif_dan_pencarian_mengarah_ke_halaman_benar(): void
    {
        $this->artisan('payroll:aktifkan', ['kode' => Company::DEFAULT_CODE]);
        $hr = User::where('username', 'hr')->firstOrFail();

        $html = $this->actingAs($hr)->get('/pay/kontrak')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<a href="[^"]*/pay/kontrak" class="nav-item-link active"#', $html);

        $hasil = $this->actingAs($hr)->getJson('/cari?q=Kontrak')->json();
        $this->assertStringContainsString('/pay/kontrak', json_encode($hasil, JSON_UNESCAPED_SLASHES));
    }
}
