<?php

declare(strict_types=1);

namespace Tests\Feature\PayrollBaru;

use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\PeriodConfig;
use App\Domain\Payroll\Models\Position;
use App\Models\Karyawan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Fase 2: data master payroll baru (jabatan, libur, tipe leave, komponen, cut off, karyawan payroll). */
final class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('payroll:aktifkan', ['kode' => 'DEFAULT'])->assertSuccessful();
        $this->hr = User::where('username', 'hr')->firstOrFail();
    }

    public function test_menu_payroll_baru_tertutup_sampai_diaktifkan(): void
    {
        $roleHr = $this->hr->role_id;
        DB::table('privileges')->where('role_id', $roleHr)->whereIn('menu_id', DB::table('menus')->where('url', 'like', 'pay-%')->pluck('id'))->delete();

        $this->actingAs($this->hr)->get('/pay/jabatan')->assertForbidden();
        $this->actingAs($this->hr)->get('/dashboard')->assertOk()->assertDontSee('Payroll Baru');

        $this->artisan('payroll:aktifkan', ['kode' => 'DEFAULT']);
        $this->actingAs($this->hr)->get('/pay/jabatan')->assertOk();
        $this->actingAs($this->hr)->get('/dashboard')->assertSee('Payroll Baru');
        $this->actingAs(User::where('username', 'rizky')->firstOrFail())->get('/pay/jabatan')->assertForbidden();
    }

    public function test_jabatan_crud_unik_per_perusahaan_dan_hanya_dinonaktifkan(): void
    {
        $this->actingAs($this->hr)->post('/pay/jabatan', ['code' => 'STF', 'name' => 'Staf', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->from('/pay/jabatan')->post('/pay/jabatan', ['code' => 'STF', 'name' => 'Staf lagi'])->assertSessionHasErrors('code');
        $jabatan = Position::where('code', 'STF')->firstOrFail();

        $this->actingAs($this->hr)->put("/pay/jabatan/{$jabatan->id}", ['code' => 'STF', 'name' => 'Staf Senior', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->delete("/pay/jabatan/{$jabatan->id}")->assertRedirect();

        $this->assertDatabaseHas('pay_positions', ['id' => $jabatan->id, 'name' => 'Staf Senior', 'is_active' => false]);
        $this->assertSame(['created', 'updated', 'deactivated'], DB::table('audit_logs')->where('entity_type', Position::class)->orderBy('id')->pluck('action')->all());
        $this->assertSame((int) $this->hr->company_id, (int) DB::table('audit_logs')->where('entity_type', Position::class)->value('company_id'));
    }

    public function test_komponen_kode_rumus_dan_nilai_uang_tepat(): void
    {
        $this->actingAs($this->hr)->from('/pay/komponen')
            ->post('/pay/komponen', ['code' => 'Uang Makan', 'name' => 'Uang Makan', 'type' => 'earning', 'default_amount' => '100'])
            ->assertSessionHasErrors('code');

        $this->actingAs($this->hr)->post('/pay/komponen', [
            'code' => 'uang_makan', 'name' => 'Uang Makan', 'type' => 'earning', 'default_amount' => '1234567.89', 'is_taxable' => 1, 'is_contractable' => 1, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->from('/pay/komponen')
            ->post('/pay/komponen', ['code' => 'x', 'name' => 'X', 'type' => 'earning', 'default_amount' => '1.234'])->assertSessionHasErrors('default_amount');

        $komponen = Component::where('code', 'uang_makan')->firstOrFail();
        $this->assertSame('1234567.89', $komponen->default_amount);
        $this->assertSame('1234567.89', (string) DB::table('pay_components')->where('id', $komponen->id)->value('default_amount'));
        $this->assertTrue($komponen->is_taxable);

        $this->actingAs($this->hr)->delete("/pay/komponen/{$komponen->id}");
        $this->assertDatabaseHas('pay_components', ['id' => $komponen->id, 'is_active' => false]);
    }

    public function test_libur_dan_tipe_leave(): void
    {
        $this->actingAs($this->hr)->post('/pay/libur', ['holiday_date' => '2026-12-25', 'name' => 'Natal'])->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->from('/pay/libur')->post('/pay/libur', ['holiday_date' => '2026-12-25', 'name' => 'Lagi'])->assertSessionHasErrors('holiday_date');
        $id = DB::table('pay_holidays')->value('id');
        $this->actingAs($this->hr)->delete("/pay/libur/{$id}");
        $this->assertDatabaseMissing('pay_holidays', ['id' => $id]);

        $this->actingAs($this->hr)->post('/pay/tipe-leave', ['code' => 'sakit_bersurat', 'name' => 'Sakit dengan surat dokter', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pay_leave_types', ['code' => 'sakit_bersurat', 'company_id' => $this->hr->company_id]);
    }

    public function test_cut_off_lintas_bulan_dan_kalender_bulanan(): void
    {
        $this->actingAs($this->hr)->put('/pay/cutoff', ['start_day' => 26, 'end_day' => 25, 'start_in_previous_month' => 1])->assertSessionHasNoErrors();
        [$mulai, $selesai] = PeriodConfig::firstOrFail()->rentang('2026-11');
        $this->assertSame(['2026-10-26', '2026-11-25'], [$mulai->toDateString(), $selesai->toDateString()]);
        $this->assertSame(['2026-02-26', '2026-03-25'], array_map(fn ($d) => $d->toDateString(), PeriodConfig::firstOrFail()->rentang('2026-03')));

        $this->actingAs($this->hr)->put('/pay/cutoff', ['start_day' => 1, 'end_day' => 31])->assertSessionHasNoErrors();
        $this->assertSame(['2026-02-01', '2026-02-28'], array_map(fn ($d) => $d->toDateString(), PeriodConfig::firstOrFail()->rentang('2026-02')));
        $this->assertSame(1, PeriodConfig::count(), 'satu konfigurasi per perusahaan');

        $this->actingAs($this->hr)->from('/pay/cutoff')->put('/pay/cutoff', ['start_day' => 26, 'end_day' => 25])->assertSessionHasErrors('end_day');
    }

    public function test_npwp_dan_rekening_terenkripsi_dan_tidak_masuk_log(): void
    {
        $rizky = Karyawan::where('nama', 'Rizky Maulana')->firstOrFail();
        $jabatan = Position::create(['code' => 'PRG', 'name' => 'Programmer']);

        $this->actingAs($this->hr)->put("/pay/karyawan/{$rizky->id}", ['position_id' => $jabatan->id, 'has_npwp' => 1, 'npwp' => '12.345.678.9-012.345'])
            ->assertSessionHasNoErrors();

        $raw = DB::table('karyawan')->where('id', $rizky->id)->first();
        $this->assertStringNotContainsString('12.345.678', (string) $raw->npwp);
        $this->assertSame('12.345.678.9-012.345', Employee::findOrFail($rizky->id)->npwp);
        $this->assertSame($jabatan->id, (int) $raw->position_id);
        $this->assertStringNotContainsString('12.345.678', (string) DB::table('audit_logs')->pluck('new_values')->join(' '));

        // rekening: dari no_rekening (modul lama), disalin terenkripsi dan tetap sinkron
        $this->assertSame((string) $raw->no_rekening, Crypt::decryptString((string) $raw->bank_account_no));
        $rizky->update(['no_rekening' => '1112223334']);
        $this->assertSame('1112223334', Employee::findOrFail($rizky->id)->bank_account_no);
        $this->assertSame('******3334', Employee::findOrFail($rizky->id)->rekeningSamaran());
        $this->actingAs($this->hr)->get('/pay/karyawan?q=Rizky')->assertSee('******3334')->assertDontSee('1112223334');
    }
}
