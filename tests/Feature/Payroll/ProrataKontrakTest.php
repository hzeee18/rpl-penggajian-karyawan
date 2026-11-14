<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Actions\NonaktifkanKontrak;
use App\Domain\Payroll\Actions\PerbaruiKontrak;
use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\PayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AUDIT.md §51: kontrak dipilih dari tanggal berlakunya; komponen penambah "dari kontrak" diprorata bila
 * kontrak/masa kerja tidak menutup seluruh periode. Periode uji 26 Okt - 25 Nov 2026 = 31 hari, 23 hari kerja.
 * Rizky: gaji pokok 5.700.000, bpjs_tk (potongan) 250.000, kontrak mulai 1 Jan 2026.
 */
final class ProrataKontrakTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanPayroll;

    protected bool $seed = true;

    private User $hr;

    private Master $master;

    private int $rizky;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 09:00', 'Asia/Jakarta'));
        $this->artisan('payroll:aktifkan', ['kode' => 'DEFAULT']);
        $this->hr = User::where('username', 'hr')->firstOrFail();
        $this->master = $this->siapkanPayroll();
        $this->rizky = (int) Employee::where('nama', 'Rizky Maulana')->value('id');
    }

    private function preview(): PayrollRun
    {
        $this->actingAs($this->hr)->post('/pay/run', ['title' => 'Payroll November 2026', 'periode' => '2026-11'])->assertSessionHasNoErrors();
        $run = PayrollRun::latest('id')->firstOrFail();
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/pratinjau", ['master_id' => $this->master->id, 'scope' => 'individual', 'pilihan' => [$this->rizky]])
            ->assertSessionHasNoErrors();

        return $run->fresh();
    }

    private function nilai(PayrollRun $run, string $code): string
    {
        return (string) $run->employees()->where('employee_id', $this->rizky)->firstOrFail()->items()->where('code', $code)->value('final_amount');
    }

    private function dasar(string $dasar): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($admin)->put('/pay/cutoff/prorata', ['prorata_dasar' => $dasar])->assertSessionHasNoErrors();
    }

    public function test_kontrak_penuh_tidak_berubah(): void
    {
        $run = $this->preview();
        $this->assertSame(['5700000.00', '250000.00'], [$this->nilai($run, 'gaji_pokok'), $this->nilai($run, 'bpjs_tk')]);
        $this->assertArrayNotHasKey('paid_days', $run->employees()->firstOrFail()->variables_snapshot);
    }

    public function test_karyawan_baru_masuk_di_tengah_periode_dibayar_prorata_hari_kerja(): void
    {
        Employee::whereKey($this->rizky)->update(['tgl_masuk' => '2026-11-10']); // 10-25 Nov = 16 hari kalender, 12 hari kerja
        $run = $this->preview();

        $this->assertSame('2973913.00', $this->nilai($run, 'gaji_pokok'), '5.700.000 x 12/23 hari kerja (bawaan: per hari kerja)');
        $this->assertSame('250000.00', $this->nilai($run, 'bpjs_tk'), 'potongan dari kontrak tidak diprorata');
        $snapshot = $run->employees()->firstOrFail()->variables_snapshot;
        $this->assertSame(['16', '12', 'kerja'], [$snapshot['paid_days'], $snapshot['paid_workdays'], $snapshot['prorata']]);
    }

    public function test_dasar_hari_kerja_dan_tanpa_prorata(): void
    {
        Employee::whereKey($this->rizky)->update(['tgl_masuk' => '2026-11-10']);
        $this->dasar('kerja');
        $this->assertSame('2973913.00', $this->nilai($this->preview(), 'gaji_pokok'), '5.700.000 x 12/23 hari kerja');

        PayrollRun::query()->get()->each(fn ($r) => $this->actingAs($this->hr)->delete("/pay/run/{$r->id}"));
        $this->dasar('tidak');
        $this->assertSame('5700000.00', $this->nilai($this->preview(), 'gaji_pokok'));

        $this->actingAs($this->hr)->put('/pay/cutoff/prorata', ['prorata_dasar' => 'kerja'])->assertForbidden();
        $this->actingAs(User::where('username', 'admin')->firstOrFail())->put('/pay/cutoff/prorata', ['prorata_dasar' => 'kalender'])->assertSessionHasErrors('prorata_dasar');
        $this->actingAs(User::where('username', 'admin')->firstOrFail())->put('/pay/cutoff/prorata', ['prorata_dasar' => 'bulanan'])->assertSessionHasErrors('prorata_dasar');
    }

    public function test_resign_di_tengah_periode(): void
    {
        Employee::whereKey($this->rizky)->update(['resign_date' => '2026-11-15']); // 26 Okt - 15 Nov = 15 dari 23 hari kerja
        $this->assertSame('3717391.00', $this->nilai($this->preview(), 'gaji_pokok'));
    }

    public function test_ganti_kontrak_di_tengah_periode_dibayar_per_segmen(): void
    {
        $lama = Contract::where('employee_id', $this->rizky)->active()->firstOrFail();
        $gp = DB::table('pay_components')->where('code', 'gaji_pokok')->value('id');
        $bpjs = DB::table('pay_components')->where('code', 'bpjs_tk')->value('id');
        $baru = app(PerbaruiKontrak::class)->handle($lama, ['contract_name' => 'PKWT naik gaji', 'start_date' => '2026-11-11'], [$gp => '6200000', $bpjs => '300000']);

        $run = $this->preview();
        // 26 Okt - 10 Nov (12 hari kerja) x 5,7 jt + 11 - 25 Nov (11 hari kerja) x 6,2 jt, dibagi 23
        $this->assertSame('5939130.00', $this->nilai($run, 'gaji_pokok'));
        $this->assertSame('300000.00', $this->nilai($run, 'bpjs_tk'), 'potongan memakai kontrak terbaru');
        $this->assertSame($baru->id, (int) $run->employees()->firstOrFail()->contract_id);
    }

    public function test_kontrak_berakhir_di_tengah_periode_atau_sebelum_periode(): void
    {
        Contract::where('employee_id', $this->rizky)->update(['end_date' => '2026-11-20']); // 26 Okt - 20 Nov = 20 dari 23 hari kerja
        $this->assertSame('4956522.00', $this->nilai($this->preview(), 'gaji_pokok'));

        PayrollRun::query()->get()->each(fn ($r) => $this->actingAs($this->hr)->delete("/pay/run/{$r->id}"));
        Contract::where('employee_id', $this->rizky)->update(['end_date' => '2026-10-20']);
        $run = $this->preview();
        $this->assertSame(0, $run->employees()->count());
        $this->assertSame('tidak punya kontrak yang berlaku di periode ini', collect($run->skipped_employees)->firstWhere('employee_id', $this->rizky)['alasan'] ?? null);
    }

    public function test_kontrak_dinonaktifkan_manual_tidak_dipakai(): void
    {
        app(NonaktifkanKontrak::class)->handle(Contract::where('employee_id', $this->rizky)->active()->firstOrFail());
        $run = $this->preview();
        $this->assertSame(0, $run->employees()->count());
    }

    public function test_kontrak_yang_mulai_setelah_periode_tidak_dipakai(): void
    {
        Contract::where('employee_id', $this->rizky)->update(['start_date' => '2026-12-01']);
        $this->assertSame(0, $this->preview()->employees()->count());
    }
}
