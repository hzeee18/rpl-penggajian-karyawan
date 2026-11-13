<?php

declare(strict_types=1);

namespace Tests\Feature\PayrollBaru;

use App\Domain\Company\Models\Company;
use App\Domain\Payroll\Actions\BuatKontrak;
use App\Domain\Payroll\Actions\KelolaMasterPayroll;
use App\Domain\Payroll\Engine\Kalkulator;
use App\Domain\Payroll\Engine\ValidasiMaster;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Item;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\MasterVersion;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\TaxMonthlyHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Pph21TahunanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PPh21 masa Desember / resign:
 * - sumber data `tax_histories` dan variabel bawaan ytd_* (riwayat bulan sebelumnya, tahun pajak yang sama, perusahaan sendiri);
 * - iuran pengurang pajak (`is_tax_deductible`) dihitung dan tercatat;
 * - pilihan siap pakai "PPh21 Desember/resign otomatis" (tabel nasional ptkp_setahun, biaya_jabatan, pasal17).
 * PERHATIAN: PTKP, tarif progresif, dan biaya jabatan di tes ini FIKTIF / contoh, hanya untuk menguji mekanisme.
 */
final class RiwayatPajakTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Employee $karyawan;

    private Contract $kontrak;

    private CarbonImmutable $mulai;

    private CarbonImmutable $selesai;

    /** @var array<string, Component> */
    private array $komponen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mulai = CarbonImmutable::parse('2026-12-01');
        $this->selesai = CarbonImmutable::parse('2026-12-31');

        foreach ([['gaji_pokok', 'earning', true, false], ['jht', 'deduction', false, true], ['pph21', 'deduction', false, false]] as [$code, $type, $tax, $iuran]) {
            $this->komponen[$code] = Component::create(['code' => $code, 'name' => strtoupper($code), 'type' => $type, 'is_taxable' => $tax,
                'is_tax_deductible' => $iuran, 'is_contractable' => $code !== 'pph21', 'default_amount' => '0']);
        }
        $this->karyawan = Employee::where('nama', 'Rizky Maulana')->firstOrFail();
        $this->kontrak = app(BuatKontrak::class)->handle(
            ['employee_id' => $this->karyawan->id, 'contract_name' => 'PKWT', 'start_date' => '2026-01-01'],
            [$this->komponen['gaji_pokok']->id => '12000000', $this->komponen['jht']->id => '240000'],
        );

        // Januari-November 2026: bruto 12 jt, iuran 240 rb, PPh21 TER 250 rb per bulan -> 132 jt, 2,64 jt, 2,75 jt
        for ($bulan = 1; $bulan <= 11; $bulan++) {
            $awal = CarbonImmutable::create(2026, $bulan, 1);
            $this->riwayat($this->buatRun($awal->toDateString(), $awal->endOfMonth()->toDateString()), 2026, $bulan, '12000000', '250000');
        }
        // tidak boleh ikut: tahun pajak lalu, bulan berjalan (payroll lain di Desember), perusahaan lain
        $this->riwayat($this->buatRun('2025-12-01', '2025-12-31'), 2025, 12, '99000000', '9000000');
        $this->riwayat($this->buatRun('2026-12-01', '2026-12-15'), 2026, 12, '5000000', '100000');
        $lain = Company::create(['code' => 'LAIN', 'name' => 'Perusahaan Lain']);
        $this->riwayat($this->buatRun('2026-05-01', '2026-05-31', (int) $lain->id), 2026, 5, '77000000', '7000000', (int) $lain->id);
    }

    private function buatRun(string $mulai, string $selesai, ?int $companyId = null): PayrollRun
    {
        return PayrollRun::forceCreate(array_filter([
            'company_id' => $companyId, 'title' => "Payroll {$mulai}", 'period_start' => $mulai, 'period_end' => $selesai,
            'status' => PayrollRun::APPROVED, 'created_by' => User::query()->value('id'),
        ], fn ($v) => $v !== null));
    }

    private function riwayat(PayrollRun $run, int $tahun, int $bulan, string $bruto, string $pph, ?int $companyId = null): void
    {
        TaxMonthlyHistory::forceCreate(array_filter([
            'company_id' => $companyId, 'employee_id' => $this->karyawan->id, 'run_id' => $run->id,
            'tax_year' => $tahun, 'tax_month' => $bulan, 'gross_taxable' => $bruto, 'tax_deduction' => '240000', 'pph21' => $pph,
        ], fn ($v) => $v !== null));
    }

    /** Tabel nasional dari file UJI berisi angka FIKTIF/contoh (file resmi tidak disentuh). */
    private function pasangTabelNasional(): void
    {
        $csv = "tabel,kunci,batas_bawah,batas_atas,hasil_angka,hasil_teks\n";
        foreach (['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'] as $s) {
            $csv .= "ptkp_setahun,{$s},,,54000000,\n";
        }
        $csv .= "biaya_jabatan,persen,,,0.05,\nbiaya_jabatan,maks_bulanan,,,500000,\n";
        $csv .= "pasal17,batas,0,60000000,0,\npasal17,tarif,0,60000000,0.05,\npasal17,dasar,0,60000000,0,\n";
        $csv .= "pasal17,batas,60000001,,60000000,\npasal17,tarif,60000001,,0.15,\npasal17,dasar,60000001,,3000000,\n";
        $file = tempnam(sys_get_temp_dir(), 'pph').'.csv';
        file_put_contents($file, $csv);
        $this->assertTrue((new Pph21TahunanSeeder)->pasang($file));
        @unlink($file);
    }

    /** Master "Payroll Desember" lewat use case (seperti form HR): gaji kontrak, iuran JHT kontrak, PPh21 Desember otomatis. */
    private function masterDesemberOtomatis(): MasterVersion
    {
        $kelola = app(KelolaMasterPayroll::class);
        $versi = $kelola->buatMaster(['code' => 'desember', 'name' => 'Payroll Desember'])->versions()->firstOrFail();
        $item = fn ($code, $calc, $urut) => $kelola->simpanItem($versi, ['component_id' => $this->komponen[$code]->id, 'code' => $code,
            'calc_type' => $calc, 'formula' => null, 'item_role' => 'normal', 'sort_order' => $urut]);
        $item('gaji_pokok', 'contract', 1);
        $item('jht', 'contract', 2);
        $item('pph21', Item::CARA_PPH21_DESEMBER, 3);

        return $versi->fresh();
    }

    public function test_sumber_tax_histories_hanya_bulan_sebelumnya_di_tahun_yang_sama_dan_perusahaan_sendiri(): void
    {
        $v = Master::create(['code' => 'uji', 'name' => 'Uji'])->versions()->create(['version_no' => 1, 'status' => 'draft']);
        $v->variables()->create(['code' => 'bruto_lalu', 'name' => 'x', 'kind' => 'base', 'source' => 'tax_histories', 'aggregate' => 'sum', 'value_expression' => 'row.gross_taxable', 'sort_order' => 1]);
        $v->variables()->create(['code' => 'iuran_lalu', 'name' => 'x', 'kind' => 'base', 'source' => 'tax_histories', 'aggregate' => 'sum', 'value_expression' => 'row.tax_deduction', 'sort_order' => 2]);
        $v->variables()->create(['code' => 'pph_lalu', 'name' => 'x', 'kind' => 'base', 'source' => 'tax_histories', 'aggregate' => 'sum', 'value_expression' => 'row.pph21', 'sort_order' => 3]);
        $v->variables()->create(['code' => 'bulan_lalu', 'name' => 'x', 'kind' => 'base', 'source' => 'tax_histories', 'aggregate' => 'count', 'filter_expression' => 'row.tax_month >= 1', 'sort_order' => 4]);
        $v->items()->create(['component_id' => $this->komponen['gaji_pokok']->id, 'code' => 'gaji_pokok', 'name' => 'Gaji', 'type' => 'earning', 'is_taxable' => true, 'calc_type' => 'contract', 'item_role' => 'normal', 'sort_order' => 1]);
        $this->assertSame([], app(ValidasiMaster::class)->periksa($v->fresh()));

        $h = (new Kalkulator($v->fresh()))->hitung($this->karyawan, $this->kontrak, $this->mulai, $this->selesai);
        $this->assertSame(['bruto_lalu' => '132000000', 'iuran_lalu' => '2640000', 'pph_lalu' => '2750000', 'bulan_lalu' => '11'],
            array_intersect_key($h->variabel, array_flip(['bruto_lalu', 'iuran_lalu', 'pph_lalu', 'bulan_lalu'])));

        // periode November tidak membaca November sendiri
        $h = (new Kalkulator($v->fresh()))->hitung($this->karyawan, $this->kontrak, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
        $this->assertSame(['120000000', '10'], [$h->variabel['bruto_lalu'], $h->variabel['bulan_lalu']]);
    }

    public function test_pph21_desember_otomatis_pasal17_dikurangi_yang_sudah_dipotong(): void
    {
        $this->pasangTabelNasional();
        $v = $this->masterDesemberOtomatis();
        $pph = $v->items->firstWhere('code', 'pph21');
        $this->assertSame(['formula', Item::RUMUS_PPH21_DESEMBER, 'pph21', Item::CARA_PPH21_DESEMBER], [$pph->calc_type, $pph->formula, $pph->item_role, $pph->caraOtomatis()]);
        $this->assertTrue($v->items->firstWhere('code', 'jht')->is_tax_deductible, 'penanda pengurang pajak disalin dari katalog');
        $this->assertSame([], app(ValidasiMaster::class)->periksa($v));

        $h = (new Kalkulator($v))->hitung($this->karyawan, $this->kontrak, $this->mulai, $this->selesai);

        $this->assertSame(['gross_taxable' => '12000000', 'tax_deduction' => '240000', 'ytd_gross_taxable' => '132000000',
            'ytd_tax_deduction' => '2640000', 'ytd_pph21' => '2750000', 'tax_months' => '12'],
            array_intersect_key($h->variabel, array_flip(['gross_taxable', 'tax_deduction', 'ytd_gross_taxable', 'ytd_tax_deduction', 'ytd_pph21', 'tax_months'])));
        // bruto setahun 144 jt; biaya jabatan min(7,2 jt; 500 rb x 12) = 6 jt; iuran 2,88 jt; PTKP 54 jt -> PKP 81.120.000
        // pajak setahun = 3 jt + (81,12 jt - 60 jt) x 15% = 6.168.000; dikurangi Jan-Nov 2,75 jt = 3.418.000
        $this->assertSame('3418000.00', collect($h->item)->firstWhere('code', 'pph21')['final']);
        $this->assertSame('240000.00', $h->pengurangPajak);
    }

    public function test_lebih_potong_menghasilkan_pph21_negatif_dan_tetap_dicatat(): void
    {
        $this->pasangTabelNasional();
        TaxMonthlyHistory::where('tax_year', 2026)->where('tax_month', '<', 12)->update(['pph21' => '600000']); // Jan-Nov 6,6 jt

        $h = (new Kalkulator($this->masterDesemberOtomatis()))->hitung($this->karyawan, $this->kontrak, $this->mulai, $this->selesai);

        $this->assertSame('-432000.00', collect($h->item)->firstWhere('code', 'pph21')['final'], '6.168.000 - 6.600.000');
        $this->assertSame('12192000.00', $h->thp, 'lebih potong menambah take home pay');
    }

    public function test_master_desember_tidak_bisa_terbit_tanpa_tabel_nasional(): void
    {
        $error = implode("\n", app(ValidasiMaster::class)->periksa($this->masterDesemberOtomatis()));

        $this->assertStringContainsString("tabel referensi 'ptkp_setahun' tidak ada", $error);
    }

    public function test_field_di_luar_whitelist_dan_kode_bawaan_ditolak(): void
    {
        $v = Master::create(['code' => 'uji', 'name' => 'Uji'])->versions()->create(['version_no' => 1, 'status' => 'draft']);
        $v->variables()->create(['code' => 'salah', 'name' => 'x', 'kind' => 'base', 'source' => 'tax_histories', 'aggregate' => 'sum', 'value_expression' => 'row.hours', 'sort_order' => 1]);
        $v->variables()->create(['code' => 'ytd_pph21', 'name' => 'x', 'kind' => 'derived', 'formula' => '1', 'sort_order' => 2]);

        $error = implode("\n", app(ValidasiMaster::class)->periksa($v->fresh()));
        $this->assertStringContainsString('field row.hours tidak tersedia di sumber ini', $error);
        $this->assertStringContainsString('ytd_pph21', $error);
    }
}
