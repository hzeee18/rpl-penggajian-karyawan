<?php

declare(strict_types=1);

namespace Tests\Feature\PayrollBaru;

use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Contract;
use App\Models\Karyawan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\MultiTenant\BuatPerusahaanLain;
use Tests\TestCase;

/** Fase 3: kontrak berversi + komponen kontrak. */
final class KontrakTest extends TestCase
{
    use BuatPerusahaanLain;
    use RefreshDatabase;

    protected bool $seed = true;

    private User $hr;

    /** @var array<string, Component> */
    private array $komponen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('payroll:aktifkan', ['kode' => 'DEFAULT']);
        $this->hr = User::where('username', 'hr')->firstOrFail();
        $buat = fn ($code, $type, $default, $kontrak = true) => Component::create(['code' => $code, 'name' => ucwords(str_replace('_', ' ', $code)),
            'type' => $type, 'default_amount' => $default, 'is_taxable' => $type === 'earning', 'is_contractable' => $kontrak]);
        $this->komponen = [
            'gaji_pokok' => $buat('gaji_pokok', 'earning', '5000000.00'),
            'uang_makan' => $buat('uang_makan', 'earning', '600000.00'),
            'bpjs_tk' => $buat('bpjs_tk', 'deduction', '250000.00'),
            'lembur' => $buat('lembur', 'earning', '0.00', false),
        ];
    }

    private function karyawan(string $nama): Karyawan
    {
        return Karyawan::where('nama', $nama)->firstOrFail();
    }

    /** @param  array<string, string|null>  $nilai */
    private function form(array $nilai, array $tambahan = []): array
    {
        $komponen = [];
        foreach ($nilai as $code => $amount) {
            $komponen[$this->komponen[$code]->id] = ['pakai' => 1, 'amount' => $amount];
        }

        return $tambahan + ['contract_name' => 'PKWT ABCD', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'info_thp' => '6000000', 'komponen' => $komponen];
    }

    private function nilaiKontrak(Contract $c): array
    {
        return $c->components()->with('component')->get()->mapWithKeys(fn ($cc) => [$cc->component->code => $cc->amount])->sortKeys()->all();
    }

    public function test_kontrak_baru_menyalin_nilai_default_dan_bisa_berbeda_per_karyawan(): void
    {
        $rizky = $this->karyawan('Rizky Maulana');
        $nadia = $this->karyawan('Nadia Putri');

        $this->actingAs($this->hr)->post('/pay/kontrak', $this->form(['gaji_pokok' => '5700000', 'uang_makan' => null, 'bpjs_tk' => '250000'], ['employee_id' => $rizky->id]))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->post('/pay/kontrak', $this->form(['gaji_pokok' => '8000000', 'uang_makan' => '750000.50'], ['employee_id' => $nadia->id]))
            ->assertSessionHasNoErrors();

        $kRizky = Contract::where('employee_id', $rizky->id)->firstOrFail();
        $this->assertSame(['bpjs_tk' => '250000.00', 'gaji_pokok' => '5700000.00', 'uang_makan' => '600000.00'], $this->nilaiKontrak($kRizky));
        $this->assertSame(['gaji_pokok' => '8000000.00', 'uang_makan' => '750000.50'], $this->nilaiKontrak(Contract::where('employee_id', $nadia->id)->firstOrFail()));
        $this->assertSame('6000000.00', $kRizky->info_thp);
        $this->assertSame($this->hr->id, (int) $kRizky->created_by);

        // perubahan katalog tidak memengaruhi kontrak yang sudah dibuat
        $this->komponen['uang_makan']->update(['default_amount' => '900000.00']);
        $this->assertSame('600000.00', $this->nilaiKontrak($kRizky->fresh())['uang_makan']);
    }

    public function test_hanya_satu_kontrak_aktif_per_karyawan(): void
    {
        $rizky = $this->karyawan('Rizky Maulana');
        $this->actingAs($this->hr)->post('/pay/kontrak', $this->form(['gaji_pokok' => '5700000'], ['employee_id' => $rizky->id]));

        $this->actingAs($this->hr)->from('/pay/kontrak')->post('/pay/kontrak', $this->form(['gaji_pokok' => '1'], ['employee_id' => $rizky->id]))
            ->assertSessionHasErrors('employee_id');
        $this->assertSame(1, Contract::where('employee_id', $rizky->id)->count());

        // penjaga di database: unique active_employee_id
        $this->expectException(QueryException::class);
        Contract::create(['employee_id' => $rizky->id, 'contract_name' => 'Lolos validasi', 'start_date' => '2026-02-01', 'is_active' => true]);
    }

    public function test_perbarui_kontrak_membuat_versi_baru_dan_menonaktifkan_yang_lama(): void
    {
        $rizky = $this->karyawan('Rizky Maulana');
        $this->actingAs($this->hr)->post('/pay/kontrak', $this->form(['gaji_pokok' => '5700000', 'uang_makan' => '600000'], ['employee_id' => $rizky->id]));
        $lama = Contract::where('employee_id', $rizky->id)->firstOrFail();

        $this->actingAs($this->hr)->post("/pay/kontrak/{$lama->id}/perbarui", $this->form(['gaji_pokok' => '6000000', 'uang_makan' => '600000'], ['contract_name' => 'PKWT 2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']))
            ->assertSessionHasNoErrors();

        $baru = Contract::where('employee_id', $rizky->id)->where('is_active', true)->firstOrFail();
        $this->assertFalse($lama->fresh()->is_active);
        $this->assertSame($lama->id, (int) $baru->previous_contract_id);
        $this->assertSame('PKWT 2027', $baru->contract_name);
        $this->assertSame('6000000.00', $this->nilaiKontrak($baru)['gaji_pokok']);
        $this->assertSame('5700000.00', $this->nilaiKontrak($lama->fresh())['gaji_pokok'], 'versi lama tidak berubah');
        $this->assertSame(['created', 'deactivated', 'renewed'], DB::table('audit_logs')->where('entity_type', Contract::class)->orderBy('id')->pluck('action')->all());

        $this->actingAs($this->hr)->from('/pay/kontrak')->post("/pay/kontrak/{$lama->id}/perbarui", $this->form([]))->assertNotFound();
    }

    public function test_nonaktifkan_manual_dan_tanda_kedaluwarsa(): void
    {
        $rizky = $this->karyawan('Rizky Maulana');
        $this->actingAs($this->hr)->post('/pay/kontrak', $this->form(['gaji_pokok' => '5700000'], ['employee_id' => $rizky->id, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']));
        $kontrak = Contract::where('employee_id', $rizky->id)->firstOrFail();

        $this->assertTrue($kontrak->isExpired(new \DateTimeImmutable('2026-11-11')));
        $this->actingAs($this->hr)->get('/pay/kontrak')->assertSee('Kedaluwarsa');

        $this->actingAs($this->hr)->post("/pay/kontrak/{$kontrak->id}/nonaktifkan")->assertRedirect();
        $this->assertFalse($kontrak->fresh()->is_active);
        $this->assertSame(1, Contract::where('employee_id', $rizky->id)->count(), 'tidak dihapus');
    }

    public function test_komponen_non_kontrak_dan_data_perusahaan_lain_ditolak(): void
    {
        $rizky = $this->karyawan('Rizky Maulana');
        $this->actingAs($this->hr)->from('/pay/kontrak')->post('/pay/kontrak', $this->form(['lembur' => '100000'], ['employee_id' => $rizky->id]))
            ->assertSessionHasErrors('komponen');

        $b = $this->perusahaanLain();
        $this->actingAs($this->hr)->from('/pay/kontrak')->post('/pay/kontrak', $this->form(['gaji_pokok' => '1'], ['employee_id' => $b['karyawan']->id]))
            ->assertSessionHasErrors('employee_id');
        $this->assertSame(0, Contract::count());
    }
}
