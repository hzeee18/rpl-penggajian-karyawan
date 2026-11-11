<?php

declare(strict_types=1);

namespace Tests\Feature\PayrollBaru;

use App\Domain\Payroll\Actions\BuatKontrak;
use App\Domain\Payroll\Engine\Kalkulator;
use App\Domain\Payroll\Engine\RumusException;
use App\Domain\Payroll\Engine\ValidasiMaster;
use App\Domain\Payroll\Models\Attendance;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Holiday;
use App\Domain\Payroll\Models\Leave;
use App\Domain\Payroll\Models\LeaveType;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\MasterVersion;
use App\Domain\Payroll\Models\Overtime;
use App\Domain\Payroll\Models\ReferenceTable;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 5: mesin rumus dengan data nyata (variabel base per sumber, cut off lintas bulan, skenario Perusahaan A).
 * PERHATIAN: tarif TER di tes ini FIKTIF (bukan angka PMK 168/2023), hanya untuk menguji mekanisme LOOKUP.
 */
final class KalkulatorTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Employee $rizky;

    private Contract $kontrak;

    /** periode cut off lintas bulan: 26 Okt s/d 25 Nov 2026 */
    private CarbonImmutable $mulai;

    private CarbonImmutable $selesai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mulai = CarbonImmutable::parse('2026-10-26');
        $this->selesai = CarbonImmutable::parse('2026-11-25');
        $komponen = [];
        foreach ([['gaji_pokok', 'earning', true, true], ['uang_makan', 'earning', true, true], ['lembur', 'earning', true, false],
            ['potongan_alpha', 'deduction', false, false], ['bpjs_tk', 'deduction', false, true], ['bpjs_kes', 'deduction', false, true],
            ['pph21', 'deduction', false, false]] as [$code, $type, $tax, $kontrak]) {
            $komponen[$code] = Component::create(['code' => $code, 'name' => ucwords(str_replace('_', ' ', $code)), 'type' => $type,
                'is_taxable' => $tax, 'is_contractable' => $kontrak, 'default_amount' => '0']);
        }
        $this->rizky = Employee::where('nama', 'Rizky Maulana')->firstOrFail();
        $this->kontrak = app(BuatKontrak::class)->handle(
            ['employee_id' => $this->rizky->id, 'contract_name' => 'PKWT ABCD', 'start_date' => '2026-01-01'],
            [$komponen['gaji_pokok']->id => '5700000', $komponen['uang_makan']->id => '600000', $komponen['bpjs_tk']->id => '250000', $komponen['bpjs_kes']->id => '50000'],
        );

        foreach (['cuti' => 'Cuti', 'izin' => 'Izin', 'sakit' => 'Sakit'] as $c => $n) {
            LeaveType::create(['code' => $c, 'name' => $n]);
        }
        Holiday::create(['holiday_date' => '2026-11-10', 'name' => 'Hari libur uji']); // Selasa

        // hari kerja periode: 23 (Senin-Jumat 26 Okt-25 Nov) - 1 libur = 22
        $hariKerja = collect(CarbonPeriod::create($this->mulai, $this->selesai))->filter(fn ($d) => ! $d->isWeekend() && $d->toDateString() !== '2026-11-10')
            ->map(fn ($d) => $d->toDateString())->values();
        $cuti = ['2026-10-26', '2026-10-27'];         // 2 cuti (di bulan sebelumnya: cut off lintas bulan)
        $sakit = ['2026-11-02'];                       // 1 sakit
        $alpha = ['2026-11-03'];                       // 1 tanpa keterangan
        $tipe = LeaveType::pluck('id', 'code');
        foreach ($hariKerja as $tgl) {
            if (in_array($tgl, $cuti, true) || in_array($tgl, $sakit, true)) {
                Leave::create(['employee_id' => $this->rizky->id, 'leave_type_id' => $tipe[in_array($tgl, $cuti, true) ? 'cuti' : 'sakit'], 'leave_date' => $tgl]);
            } elseif (! in_array($tgl, $alpha, true)) {
                Attendance::create(['employee_id' => $this->rizky->id, 'attendance_date' => $tgl, 'late_hours' => $tgl === '2026-11-04' ? '0.5' : '0']);
            }
        }
        Attendance::create(['employee_id' => $this->rizky->id, 'attendance_date' => '2026-10-25', 'late_hours' => '0']); // di luar periode
        Overtime::create(['employee_id' => $this->rizky->id, 'overtime_date' => '2026-11-07', 'duration_hours' => '3']);   // Sabtu
        Overtime::create(['employee_id' => $this->rizky->id, 'overtime_date' => '2026-11-12', 'duration_hours' => '1.5']);
        Overtime::create(['employee_id' => $this->rizky->id, 'overtime_date' => '2026-11-26', 'duration_hours' => '9']);   // di luar periode

        // tabel referensi FIKTIF untuk uji mekanisme
        $kategori = $this->tabel('kategori_ptkp_uji', [['TK/0', null, null, null, 'A'], ['K/0', null, null, null, 'A'], ['K/1', null, null, null, 'B']]);
        $ter = $this->tabel('ter_uji', [['A', '0', '5400000', '0', null], ['A', '5400000.01', '7000000', '0.01', null], ['A', '7000000.01', null, '0.02', null], ['B', '0', null, '0.005', null]]);
    }

    /** @param list<array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?string}> $baris */
    private function tabel(string $kode, array $baris): ReferenceTable
    {
        $t = ReferenceTable::create(['code' => $kode, 'name' => $kode]);
        $v = $t->versions()->create(['version_no' => 1, 'status' => 'published', 'published_at' => now()]);
        foreach ($baris as $i => [$k, $dari, $sampai, $angka, $teks]) {
            $v->rows()->create(['key_value' => $k, 'range_from' => $dari, 'range_to' => $sampai, 'result_number' => $angka, 'result_text' => $teks, 'sort_order' => $i]);
        }

        return $t;
    }

    private function versiPerusahaanA(): MasterVersion
    {
        $master = Master::create(['code' => 'usual', 'name' => 'Payroll Usual']);
        $v = $master->versions()->create(['version_no' => 1, 'status' => 'draft']);
        $v->params()->create(['code' => 'pembagi_uang_makan', 'name' => 'Pembagi uang makan', 'value' => '22']);
        $v->params()->create(['code' => 'tarif_lembur', 'name' => 'Tarif lembur per jam', 'value' => '25000']);
        $urut = 0;
        $var = fn (array $d) => $v->variables()->create($d + ['name' => $d['code'], 'sort_order' => ++$urut]);
        $var(['code' => 'hadir', 'kind' => 'base', 'source' => 'attendances', 'aggregate' => 'count', 'filter_expression' => 'row.weekday <= 5']);
        $var(['code' => 'cuti', 'kind' => 'base', 'source' => 'leaves', 'aggregate' => 'count', 'filter_expression' => "row.type = 'cuti'"]);
        $var(['code' => 'leave_semua', 'kind' => 'base', 'source' => 'leaves', 'aggregate' => 'count_distinct_date']);
        $var(['code' => 'jam_lembur', 'kind' => 'base', 'source' => 'overtimes', 'aggregate' => 'sum', 'value_expression' => 'row.hours']);
        $var(['code' => 'jam_telat', 'kind' => 'base', 'source' => 'attendances', 'aggregate' => 'sum', 'value_expression' => 'row.late_hours', 'filter_expression' => 'row.late_hours > 0']);
        $var(['code' => 'alpha', 'kind' => 'derived', 'formula' => 'MAX(workdays - hadir - leave_semua, 0)']);
        $var(['code' => 'kategori_ter', 'kind' => 'derived', 'formula' => "LOOKUP_TEXT('kategori_ptkp_uji', ptkp_status)"]);

        $c = Component::pluck('id', 'code');
        $urut = 0;
        $item = fn (string $code, string $type, bool $tax, string $calc, ?string $f = null, string $role = 'normal') => $v->items()->create([
            'component_id' => $c[$code], 'code' => $code, 'name' => $code, 'type' => $type, 'is_taxable' => $tax, 'calc_type' => $calc,
            'formula' => $f, 'item_role' => $role, 'sort_order' => ++$urut,
        ]);
        $item('pph21', 'deduction', false, 'formula', "ROUND(gross_taxable * LOOKUP('ter_uji', kategori_ter, gross_taxable))", 'pph21'); // urutan pertama, tetap dievaluasi terakhir
        $item('gaji_pokok', 'earning', true, 'contract');
        $item('uang_makan', 'earning', true, 'formula', "CONTRACT('uang_makan') / pembagi_uang_makan * (hadir + cuti)");
        $item('lembur', 'earning', true, 'formula', 'tarif_lembur * jam_lembur');
        $item('potongan_alpha', 'deduction', false, 'formula', 'gaji_pokok / workdays * alpha');
        $item('bpjs_tk', 'deduction', false, 'contract');
        $item('bpjs_kes', 'deduction', false, 'contract');

        return $v;
    }

    public function test_skenario_perusahaan_a(): void
    {
        $v = $this->versiPerusahaanA();
        $this->assertSame([], app(ValidasiMaster::class)->periksa($v));

        $h = (new Kalkulator($v))->hitung($this->rizky, $this->kontrak, $this->mulai, $this->selesai);

        // 22 hari kerja: 18 hadir, 2 cuti, 1 sakit, 1 alpha
        $this->assertSame(['hadir' => '18', 'cuti' => '2', 'leave_semua' => '3', 'jam_lembur' => '4.5', 'jam_telat' => '0.5', 'alpha' => '1', 'kategori_ter' => 'A', 'gross_taxable' => '6357955'],
            array_intersect_key($h->variabel, array_flip(['hadir', 'cuti', 'leave_semua', 'jam_lembur', 'jam_telat', 'alpha', 'kategori_ter', 'gross_taxable'])));

        $nilai = collect($h->item)->pluck('final', 'code')->all();
        $this->assertSame([
            'gaji_pokok' => '5700000.00',
            'uang_makan' => '545455.00',    // 600000 / 22 * 20 = 545454.54 -> 545455
            'lembur' => '112500.00',        // 25000 * 4.5
            'potongan_alpha' => '259091.00', // 5700000 / 22 * 1 = 259090.9 -> 259091
            'bpjs_tk' => '250000.00',
            'bpjs_kes' => '50000.00',
            'pph21' => '63580.00',          // 6357955 * 0.01 = 63579.55 (tarif fiktif) -> 63580
        ], $nilai);
        $this->assertSame('pph21', collect($h->item)->last()['code'], 'PPh21 dievaluasi paling akhir');
        $this->assertSame('6357955.00', $h->totalPenambah);
        $this->assertSame('6357955.00', $h->brutoKenaPajak);
        $this->assertSame('622671.00', $h->totalPengurang);
        $this->assertSame('5735284.00', $h->thp);
        $this->assertCount(2, $h->versiReferensi);
    }

    public function test_override_tidak_tertimpa_dan_mengalir_ke_pph21(): void
    {
        $h = (new Kalkulator($this->versiPerusahaanA()))->hitung($this->rizky, $this->kontrak, $this->mulai, $this->selesai, ['lembur' => '1000000']);

        $lembur = collect($h->item)->firstWhere('code', 'lembur');
        $this->assertSame(['112500.00', '1000000.00', true], [$lembur['calculated'], $lembur['final'], $lembur['overridden']]);
        $this->assertSame('7245455.00', $h->brutoKenaPajak);
        $this->assertSame('144909.00', collect($h->item)->firstWhere('code', 'pph21')['final'], '7245455 * 0.02 (rentang TER berikutnya)');
    }

    public function test_komponen_kontrak_yang_tidak_ada_bernilai_nol(): void
    {
        $v = $this->versiPerusahaanA();
        $this->kontrak->components()->whereHas('component', fn ($q) => $q->where('code', 'bpjs_kes'))->delete();

        $h = (new Kalkulator($v))->hitung($this->rizky, $this->kontrak->fresh(), $this->mulai, $this->selesai);
        $this->assertSame('0.00', collect($h->item)->firstWhere('code', 'bpjs_kes')['final']);
    }

    public function test_validasi_rujukan_urutan_sumber_dan_tabel(): void
    {
        $v = $this->versiPerusahaanA();
        $v->variables()->create(['code' => 'sebelum', 'name' => 'x', 'kind' => 'derived', 'formula' => 'sesudah + 1', 'sort_order' => 50]);
        $v->variables()->create(['code' => 'sesudah', 'name' => 'x', 'kind' => 'derived', 'formula' => 'sebelum + 1', 'sort_order' => 51]);
        $v->variables()->create(['code' => 'diri', 'name' => 'x', 'kind' => 'derived', 'formula' => 'diri * 2', 'sort_order' => 52]);
        $v->variables()->create(['code' => 'field_salah', 'name' => 'x', 'kind' => 'base', 'source' => 'overtimes', 'aggregate' => 'sum', 'value_expression' => 'row.late_hours', 'sort_order' => 53]);
        $v->variables()->create(['code' => 'sintaks', 'name' => 'x', 'kind' => 'derived', 'formula' => 'hadir +* 2', 'sort_order' => 54]);
        $v->variables()->create(['code' => 'tarif_lembur', 'name' => 'sama dengan parameter', 'kind' => 'derived', 'formula' => '1', 'sort_order' => 55]);
        $lain = Component::create(['code' => 'lain_lain', 'name' => 'Lain', 'type' => 'earning', 'default_amount' => '0']);
        $v->items()->create(['component_id' => $lain->id, 'code' => 'x_tabel', 'name' => 'x', 'type' => 'earning',
            'calc_type' => 'formula', 'formula' => "LOOKUP('tidak_ada', 'A', 1) + CONTRACT('tidak_ada')", 'sort_order' => 99]);

        $error = implode("\n", app(ValidasiMaster::class)->periksa($v->fresh()));
        $this->assertStringContainsString("Variabel sebelum: merujuk 'sesudah' yang belum didefinisikan di atasnya", $error);
        $this->assertStringContainsString("Variabel diri: merujuk 'diri'", $error);
        $this->assertStringContainsString('field row.late_hours tidak tersedia di sumber ini', $error);
        $this->assertStringContainsString('Variabel sintaks:', $error);
        $this->assertStringContainsString('Variabel tarif_lembur: kode sudah dipakai Parameter', $error);
        $this->assertStringContainsString("tabel referensi 'tidak_ada' tidak ada", $error);
        $this->assertStringContainsString("komponen 'tidak_ada' di CONTRACT() tidak ada", $error);
    }

    public function test_kesalahan_saat_hitung_menyebut_komponennya(): void
    {
        $v = $this->versiPerusahaanA();
        $v->items()->where('code', 'potongan_alpha')->update(['formula' => 'gaji_pokok / (workdays - workdays)']);

        $this->expectException(RumusException::class);
        $this->expectExceptionMessage('Komponen potongan_alpha: Pembagian dengan nol');
        (new Kalkulator($v->fresh()))->hitung($this->rizky, $this->kontrak, $this->mulai, $this->selesai);
    }
}
