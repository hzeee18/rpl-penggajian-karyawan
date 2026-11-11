<?php

declare(strict_types=1);

namespace Tests\Feature\PayrollBaru;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Engine\Desimal;
use App\Domain\Payroll\Models\Item;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\RunEmployee;
use App\Models\User;
use Database\Seeders\PayrollDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Fase 8: seed demo 2 perusahaan dan uji akhir alur dengan aturan berbeda per perusahaan. */
final class PayrollDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 09:00', 'Asia/Jakarta'));
        PayrollDemoSeeder::$fileTerUji = null;
    }

    protected function tearDown(): void
    {
        PayrollDemoSeeder::$fileTerUji = null;
        parent::tearDown();
    }

    private function companyId(string $kode): int
    {
        return (int) Company::where('code', $kode)->value('id');
    }

    /** Preview Payroll Usual semua karyawan perusahaan (lewat HTTP sebagai HR perusahaan itu). */
    private function preview(string $kode): PayrollRun
    {
        $hr = User::withoutGlobalScopes()->where('username', 'hr.'.strtolower($kode))->firstOrFail();
        $this->actingAs($hr)->post('/pay/run', ['title' => "Payroll {$kode} November 2026", 'periode' => '2026-11'])->assertSessionHasNoErrors();
        $run = PayrollRun::latest('id')->firstOrFail();
        $this->actingAs($hr)->post("/pay/run/{$run->id}/pratinjau", ['master_id' => Master::where('code', 'usual')->value('id'), 'scope' => 'all'])->assertSessionHasNoErrors();

        return $run->fresh();
    }

    public function test_seed_dua_perusahaan_terisolasi_dengan_data_lengkap(): void
    {
        PayrollDemoSeeder::$fileTerUji = sys_get_temp_dir().'/ter-tidak-ada-'.uniqid().'.csv'; // jalur: file TER belum ada
        $this->seed(PayrollDemoSeeder::class);

        foreach (['DEMOA' => 12, 'DEMOB' => 10] as $kode => $jumlah) {
            $id = $this->companyId($kode);
            $this->assertSame($jumlah, DB::table('karyawan')->where('company_id', $id)->count());
            $this->assertSame(['Admin', 'HR', 'Manager', 'Karyawan'], DB::table('roles')->where('company_id', $id)->orderBy('id')->pluck('nama')->all());
            $this->assertSame(2, DB::table('departemen')->where('company_id', $id)->count());
            $this->assertSame($jumlah, DB::table('pay_contracts')->where('company_id', $id)->where('is_active', true)->count());
            $this->assertGreaterThan(0, DB::table('pay_leaves')->where('company_id', $id)->where('leave_type_id', DB::table('pay_leave_types')->where('company_id', $id)->where('code', 'sakit_bersurat')->value('id'))->count());
            $this->assertGreaterThan(0, DB::table('pay_attendances')->where('company_id', $id)->where('late_hours', '>', 0)->count(), 'ada terlambat');
            $this->assertSame('published', DB::table('pay_master_versions')->where('company_id', $id)->value('status'));
            $this->assertSame(5, DB::table('pay_holidays')->where('company_id', $id)->count());
        }
        $this->assertSame(1, DB::table('pay_period_configs')->where('company_id', $this->companyId('DEMOA'))->value('start_in_previous_month'));
        $this->assertSame(0, (int) DB::table('pay_period_configs')->where('company_id', $this->companyId('DEMOB'))->value('start_in_previous_month'));
        $this->assertGreaterThan(0, DB::table('pay_overtimes')->where('company_id', $this->companyId('DEMOA'))->where('note', 'Lembur akhir pekan')->count());

        // nilai komponen kontrak berbeda antar karyawan
        $gaji = DB::table('pay_contract_components')->join('pay_components', 'pay_components.id', '=', 'pay_contract_components.component_id')
            ->where('pay_components.code', 'gaji_pokok')->where('pay_contract_components.company_id', $this->companyId('DEMOA'))->pluck('amount')->unique();
        $this->assertCount(12, $gaji);

        // TER belum tersedia -> komponen PPh21 tidak dipasang
        $this->assertSame(0, DB::table('pay_items')->where('item_role', 'pph21')->count());

        // isolasi: HR DEMOA tidak melihat karyawan DEMOB
        $hrA = User::withoutGlobalScopes()->where('username', 'hr.demoa')->firstOrFail();
        $this->actingAs($hrA)->get('/pay/karyawan?q=B0001')->assertOk()->assertDontSee('B0001</td>', false);
        $this->actingAs($hrA)->get('/pay/karyawan?q=A0001')->assertOk()->assertSee('A0001</td>', false);

        // tidak berjalan dua kali
        $this->seed(PayrollDemoSeeder::class);
        $this->assertSame(2, Company::whereIn('code', ['DEMOA', 'DEMOB'])->count());
    }

    public function test_aturan_payroll_berbeda_per_perusahaan_dari_database(): void
    {
        PayrollDemoSeeder::$fileTerUji = sys_get_temp_dir().'/ter-tidak-ada-'.uniqid().'.csv';
        $this->seed(PayrollDemoSeeder::class);

        // DEMOA: uang makan = kontrak / 22 x (hadir + cuti), lembur 25.000/jam, periode lintas bulan
        $runA = $this->preview('DEMOA');
        $this->assertSame(['2026-10-26', '2026-11-25', 12], [$runA->period_start->toDateString(), $runA->period_end->toDateString(), $runA->employees()->count()]);
        foreach (RunEmployee::where('run_id', $runA->id)->with('items')->get() as $re) {
            $v = $re->variables_snapshot;
            $kontrakUm = (string) DB::table('pay_contract_components')->join('pay_components', 'pay_components.id', '=', 'pay_contract_components.component_id')
                ->where('pay_contract_components.contract_id', $re->contract_id)->where('pay_components.code', 'uang_makan')->value('amount');
            $harapan = Desimal::uang(Desimal::bulat(Desimal::kali(Desimal::bagi($kontrakUm, '22'), Desimal::tambah($v['hadir'], $v['cuti']))));
            $this->assertSame($harapan, (string) $re->items->firstWhere('code', 'uang_makan')->final_amount, $re->full_name);
            $this->assertSame(Desimal::uang(Desimal::kali('25000', $v['jam_lembur'])), (string) $re->items->firstWhere('code', 'lembur')->final_amount);
        }

        // DEMOB: lembur 1,5x (hari kerja) / 2x (akhir pekan/libur) upah per jam, potongan terlambat Rp20.000/jam, periode kalender
        $runB = $this->preview('DEMOB');
        $this->assertSame(['2026-11-01', '2026-11-30', 10], [$runB->period_start->toDateString(), $runB->period_end->toDateString(), $runB->employees()->count()]);
        $adaLibur = false;
        foreach (RunEmployee::where('run_id', $runB->id)->with('items')->get() as $re) {
            $v = $re->variables_snapshot;
            $gp = (string) $re->items->firstWhere('code', 'gaji_pokok')->final_amount;
            $harapan = Desimal::uang(Desimal::bulat(Desimal::kali(Desimal::bagi($gp, '173'), Desimal::tambah(Desimal::kali($v['jam_lembur_kerja'], '1.5'), Desimal::kali($v['jam_lembur_libur'], '2')))));
            $this->assertSame($harapan, (string) $re->items->firstWhere('code', 'lembur')->final_amount, $re->full_name);
            $this->assertSame(Desimal::uang(Desimal::kali('20000', $v['jam_telat'])), (string) $re->items->firstWhere('code', 'potongan_terlambat')->final_amount);
            $adaLibur = $adaLibur || Desimal::banding($v['jam_lembur_libur'], '0') > 0;
            $this->assertArrayNotHasKey('uang_makan', $re->items->keyBy('code')->all(), 'komponen perusahaan A tidak ada di B');
        }
        $this->assertTrue($adaLibur, 'ada lembur akhir pekan di DEMOB');

        // data run tetap terisolasi per perusahaan
        $this->assertSame(1, app(CurrentCompany::class)->runAs($this->companyId('DEMOA'), fn () => PayrollRun::count()));
    }

    public function test_dengan_file_ter_komponen_pph21_dipasang(): void
    {
        // FILE UJI dengan angka FIKTIF, bukan tarif resmi; file resmi tidak disentuh
        $file = tempnam(sys_get_temp_dir(), 'ter').'.csv';
        file_put_contents($file, "tabel,kunci,batas_bawah,batas_atas,hasil_angka,hasil_teks\n"
            ."kategori_ptkp,TK/0,,,,A\nkategori_ptkp,TK/1,,,,A\nkategori_ptkp,K/0,,,,A\nkategori_ptkp,TK/2,,,,B\nkategori_ptkp,TK/3,,,,B\n"
            ."kategori_ptkp,K/1,,,,B\nkategori_ptkp,K/2,,,,B\nkategori_ptkp,K/3,,,,C\n"
            ."ter_bulanan,A,0,,0.01,\nter_bulanan,B,0,,0.02,\nter_bulanan,C,0,,0.03,\n");
        PayrollDemoSeeder::$fileTerUji = $file;

        $this->seed(PayrollDemoSeeder::class);
        @unlink($file);

        $this->assertSame(2, DB::table('pay_reference_tables')->whereNull('company_id')->whereIn('code', ['ter_bulanan', 'kategori_ptkp'])->count());
        $this->assertSame(2, Item::withoutGlobalScopes()->where('item_role', 'pph21')->count(), 'PPh21 di master kedua perusahaan');

        $run = $this->preview('DEMOA');
        $re = RunEmployee::where('run_id', $run->id)->with('items')->firstOrFail();
        $this->assertGreaterThan(0, (float) $re->items->firstWhere('code', 'pph21')->final_amount);
    }
}
