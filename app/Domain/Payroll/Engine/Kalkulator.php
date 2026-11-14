<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Engine;

use App\Domain\Company\Models\Company;
use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\MasterVersion;
use App\Domain\Payroll\Models\Variable;
use App\Domain\Payroll\Run\KontrakPeriode;
use Carbon\CarbonImmutable;

/**
 * Hitung payroll satu karyawan dengan satu versi master dalam satu periode.
 *
 * Urutan: variabel bawaan -> parameter -> variabel (sort_order) -> item normal (sort_order) -> item pph21 (terakhir).
 * Nilai akhir tiap item dibulatkan half-up ke rupiah. Item yang di-override memakai nilai override sebagai
 * nilai akhir; item sesudahnya (termasuk PPh21) dihitung dari nilai override tersebut.
 */
final class Kalkulator
{
    /** Variabel bawaan yang selalu tersedia. */
    public const BAWAAN = [
        'period_start' => 'tanggal awal periode', 'period_end' => 'tanggal akhir periode',
        'join_date' => 'tanggal masuk karyawan', 'resign_date' => 'tanggal resign (kosong bila belum)',
        'ptkp_status' => 'status PTKP (TK/0, K/1, ...)', 'has_npwp' => 'punya NPWP (TRUE/FALSE)',
        'workdays' => 'hari kerja periode menurut pola hari kerja karyawan, dikurangi libur',
        'period_days' => 'jumlah hari kalender periode',
        'paid_days' => 'hari kalender periode yang tertutup kontrak dan masa kerja (karyawan baru/resign/ganti kontrak)',
        'paid_workdays' => 'hari kerja (pola karyawan, dikurangi libur) yang tertutup kontrak dan masa kerja; dasar prorata',
    ];

    private ?string $dasarProrata = null;

    /** Hanya tersedia di rumus item pph21. ytd_* = bulan sebelumnya di tahun pajak yang sama (payroll yang sudah disetujui). */
    public const BAWAAN_PPH21 = [
        'gross_taxable' => 'jumlah nilai akhir komponen pendapatan yang kena pajak',
        'tax_deduction' => 'jumlah nilai akhir komponen pengurang pajak (iuran JHT/JP/pensiun yang dibayar karyawan)',
        'ytd_gross_taxable' => 'bruto kena pajak bulan-bulan sebelumnya tahun ini',
        'ytd_tax_deduction' => 'iuran pengurang pajak bulan-bulan sebelumnya tahun ini',
        'ytd_pph21' => 'PPh21 yang sudah dipotong bulan-bulan sebelumnya tahun ini',
        'tax_months' => 'jumlah bulan pajak tahun ini (bulan sebelumnya yang tercatat + bulan ini)',
    ];

    public function __construct(private readonly MasterVersion $versi)
    {
        $this->versi->loadMissing(['variables', 'params', 'items.component']);
    }

    /**
     * @param  Contract|KontrakPeriode|null  $kontrak  Contract = satu kontrak dianggap penuh sepanjang periode (simulasi master)
     * @param  array<string, string>  $override  kode item => nilai akhir
     * @param  list<array{component_id: int, code: string, name: string, type: string, is_taxable: bool, is_tax_deductible: bool, amount: string}>  $penyesuaian
     *         baris penyesuaian HR (AUDIT.md §52), dihitung sesudah item normal dan sebelum PPh21
     */
    public function hitung(Employee $employee, Contract|KontrakPeriode|null $kontrak, CarbonImmutable $mulai, CarbonImmutable $selesai, array $override = [], array $penyesuaian = []): HasilHitung
    {
        $periode = $kontrak instanceof Contract ? KontrakPeriode::penuhDari($kontrak, $mulai, $selesai) : $kontrak;
        $contract = $periode?->terakhir();
        $contract?->loadMissing('components.component');
        $nilaiKontrak = [];
        foreach ($contract?->components ?? [] as $cc) {
            $nilaiKontrak[$cc->component->code] = Desimal::normal((string) $cc->amount);
        }

        $sumber = new SumberData($employee, $contract, $mulai, $selesai);
        // KONTRAK() di rumus = nilai penuh kontrak terbaru; item "dari kontrak" = nilai prorata (AUDIT.md §51)
        $k = new KonteksKaryawan($nilaiKontrak, $sumber->libur(), $selesai->toDateString(), $sumber->hariKerja());
        $nilaiItemKontrak = $this->prorata($periode, $k, $nilaiKontrak);
        $e = new Evaluator($k);
        $snapshot = [];
        $hariPeriode = (int) $mulai->startOfDay()->diffInDays($selesai->startOfDay()) + 1;
        $hariDibayar = $periode === null ? 0 : ($periode->penuh() ? $hariPeriode : $periode->hariDibayar());
        $hariKerjaPeriode = $k->hariKerja($mulai->toDateString(), $selesai->toDateString());
        $hariKerjaDibayar = $periode === null ? 0 : ($periode->penuh() ? $hariKerjaPeriode
            : array_sum(array_map(fn ($s) => $k->hariKerja($s['mulai']->toDateString(), $s['selesai']->toDateString()), $periode->segmen)));
        if ($periode !== null && ! $periode->penuh()) {
            $snapshot['paid_days'] = (string) $hariDibayar;
            $snapshot['paid_workdays'] = (string) $hariKerjaDibayar;
            $snapshot['prorata'] = $this->dasarProrata();
        }

        $bawaan = [
            'period_start' => Nilai::teks($mulai->toDateString()), 'period_end' => Nilai::teks($selesai->toDateString()),
            'join_date' => Nilai::teks($employee->tgl_masuk?->toDateString() ?? ''), 'resign_date' => Nilai::teks($employee->resign_date?->toDateString() ?? ''),
            'ptkp_status' => Nilai::teks((string) $employee->ptkpStatus()), 'has_npwp' => Nilai::bool((bool) $employee->has_npwp),
            'workdays' => Nilai::angka((string) $hariKerjaPeriode),
            'period_days' => Nilai::angka((string) $hariPeriode), 'paid_days' => Nilai::angka((string) $hariDibayar),
            'paid_workdays' => Nilai::angka((string) $hariKerjaDibayar),
        ];
        foreach ($bawaan as $nama => $nilai) {
            $k->set($nama, $nilai);
        }
        foreach ($this->versi->params as $p) {
            $k->set($p->code, Nilai::angka((string) $p->value));
        }

        foreach ($this->versi->variables as $v) {
            try {
                $hasil = $v->kind === 'base' ? $this->base($v, $sumber, $k, $e) : $e->hitung((string) $v->formula);
            } catch (RumusException $ex) {
                throw new RumusException("Variabel {$v->code}: ".$ex->getMessage(), previous: $ex);
            }
            $k->set($v->code, $hasil);
            $snapshot[$v->code] = $hasil->sebagaiTeks();
        }

        $items = $this->versi->items->sortBy(fn ($i) => [$i->item_role === 'pph21' ? 1 : 0, $i->sort_order, $i->id])->values();
        $hasilItem = [];
        $bruto = '0';
        $iuran = '0';
        $tambahPenyesuaian = function () use (&$penyesuaian, &$hasilItem, &$bruto, &$iuran): void {
            foreach ($penyesuaian as $p) {
                $nilai = Desimal::bulat(Desimal::normal($p['amount']));
                if ($p['type'] === 'earning' && $p['is_taxable']) {
                    $bruto = Desimal::tambah($bruto, $nilai);
                }
                if ($p['type'] === 'deduction' && $p['is_tax_deductible']) {
                    $iuran = Desimal::tambah($iuran, $nilai);
                }
                $hasilItem[] = [
                    'item_id' => null, 'component_id' => $p['component_id'], 'code' => $p['code'], 'name' => $p['name'],
                    'type' => $p['type'], 'is_taxable' => $p['is_taxable'], 'sort_order' => 9000,
                    'calculated' => Desimal::uang($nilai), 'final' => Desimal::uang($nilai), 'overridden' => false,
                ];
            }
            $penyesuaian = [];
        };
        foreach ($items as $item) {
            if ($item->item_role === 'pph21') {
                $tambahPenyesuaian();
                $pajak = ['gross_taxable' => $bruto, 'tax_deduction' => $iuran] + $this->riwayatPajak($sumber);
                foreach ($pajak as $nama => $nilai) {
                    $k->set($nama, Nilai::angka($nilai));
                    $snapshot[$nama] = $nilai;
                }
            }
            try {
                $hitung = $item->calc_type === 'contract' ? $this->nilaiKontrakItem($item->type, $item->component->code, $nilaiItemKontrak, $k) : $e->angka((string) $item->formula);
            } catch (RumusException $ex) {
                throw new RumusException("Komponen {$item->code}: ".$ex->getMessage(), previous: $ex);
            }
            $hitung = Desimal::bulat($hitung);
            $diubah = array_key_exists($item->code, $override);
            $akhir = $diubah ? Desimal::bulat(Desimal::normal((string) $override[$item->code])) : $hitung;
            $k->set($item->code, Nilai::angka($akhir));

            if ($item->type === 'earning' && $item->is_taxable) {
                $bruto = Desimal::tambah($bruto, $akhir);
            }
            if ($item->type === 'deduction' && $item->is_tax_deductible && $item->item_role !== 'pph21') {
                $iuran = Desimal::tambah($iuran, $akhir);
            }
            $hasilItem[] = [
                'item_id' => (int) $item->id, 'component_id' => (int) $item->component_id, 'code' => $item->code, 'name' => $item->name,
                'type' => $item->type, 'is_taxable' => (bool) $item->is_taxable, 'sort_order' => (int) $item->sort_order,
                'calculated' => Desimal::uang($hitung), 'final' => Desimal::uang($akhir), 'overridden' => $diubah,
            ];
        }

        $tambahPenyesuaian(); // master tanpa item PPh21

        $penambah = '0';
        $pengurang = '0';
        foreach ($hasilItem as $h) {
            $h['type'] === 'earning' ? $penambah = Desimal::tambah($penambah, $h['final']) : $pengurang = Desimal::tambah($pengurang, $h['final']);
        }

        return new HasilHitung(
            $snapshot, $hasilItem, Desimal::uang($penambah), Desimal::uang($pengurang), Desimal::uang($bruto),
            Desimal::uang(Desimal::kurang($penambah, $pengurang)), $k->versiDipakai(), Desimal::uang($iuran),
        );
    }

    /**
     * Nilai komponen kontrak untuk item "dari kontrak": penuh bila satu kontrak menutup seluruh periode;
     * selain itu jumlah (nilai kontrak segmen x hari segmen / hari periode), hari menurut setelan perusahaan.
     *
     * @param  array<string, string>  $nilaiPenuh  nilai kontrak terbaru
     * @return array<string, string>
     */
    private function prorata(?KontrakPeriode $periode, KonteksKaryawan $k, array $nilaiPenuh): array
    {
        $dasar = $this->dasarProrata();
        if ($periode === null || $periode->penuh() || $dasar === 'tidak') {
            return $nilaiPenuh;
        }
        $hari = fn (CarbonImmutable $a, CarbonImmutable $b): int => $dasar === 'kerja'
            ? $k->hariKerja($a->toDateString(), $b->toDateString()) : (int) $a->diffInDays($b) + 1;
        $total = $hari($periode->mulai->startOfDay(), $periode->selesai->startOfDay());
        if ($total === 0) { // periode tanpa hari kerja: pakai hari kalender
            $hari = fn (CarbonImmutable $a, CarbonImmutable $b): int => (int) $a->diffInDays($b) + 1;
            $total = $hari($periode->mulai->startOfDay(), $periode->selesai->startOfDay());
        }

        $hasil = [];
        foreach ($periode->segmen as $s) {
            $n = (string) $hari($s['mulai'], $s['selesai']);
            foreach ($s['contract']->components as $cc) {
                $kode = $cc->component->code;
                $hasil[$kode] = Desimal::tambah($hasil[$kode] ?? '0', Desimal::bagi(Desimal::kali((string) $cc->amount, $n), (string) $total));
            }
        }

        return $hasil;
    }

    /** Hanya penambah yang diprorata; potongan dari kontrak (mis. iuran tetap) tetap penuh. Rumus bisa memakai paid_workdays / workdays. */
    private function nilaiKontrakItem(string $type, string $kode, array $prorata, KonteksKaryawan $k): string
    {
        return $type === 'earning' ? ($prorata[$kode] ?? '0') : $k->kontrak($kode);
    }

    /** Setelan perusahaan pemilik master: kerja | tidak. */
    private function dasarProrata(): string
    {
        // keputusan pemilik (AUDIT.md §65): prorata selalu per hari kerja; nilai lama 'kalender' diperlakukan sebagai 'kerja'
        return $this->dasarProrata ??= Company::whereKey($this->versi->company_id)->value('prorata_dasar') === 'tidak' ? 'tidak' : 'kerja';
    }

    /** @return array{ytd_gross_taxable: string, ytd_tax_deduction: string, ytd_pph21: string, tax_months: string} */
    private function riwayatPajak(SumberData $sumber): array
    {
        $hasil = ['ytd_gross_taxable' => '0', 'ytd_tax_deduction' => '0', 'ytd_pph21' => '0'];
        $bulan = [];
        foreach ($sumber->baris('tax_histories') as $b) {
            $hasil['ytd_gross_taxable'] = Desimal::tambah($hasil['ytd_gross_taxable'], $b['gross_taxable']);
            $hasil['ytd_tax_deduction'] = Desimal::tambah($hasil['ytd_tax_deduction'], $b['tax_deduction']);
            $hasil['ytd_pph21'] = Desimal::tambah($hasil['ytd_pph21'], $b['pph21']);
            $bulan[$b['tax_month']] = true;
        }

        return $hasil + ['tax_months' => (string) (count($bulan) + 1)];
    }

    private function base(Variable $v, SumberData $sumber, KonteksKaryawan $k, Evaluator $e): Nilai
    {
        $nilai = [];
        $tanggal = [];
        foreach ($sumber->baris((string) $v->source) as $baris) {
            $k->denganBaris($baris);
            try {
                if (filled($v->filter_expression) && ! $e->hitung((string) $v->filter_expression)->sebagaiBool()) {
                    continue;
                }
                $nilai[] = filled($v->value_expression) ? $e->angka((string) $v->value_expression) : '1';
                $tanggal[$baris['date'] ?? count($tanggal)] = true;
            } finally {
                $k->denganBaris(null);
            }
        }

        return Nilai::angka(match ($v->aggregate) {
            'count' => (string) count($nilai),
            'count_distinct_date' => (string) count($tanggal),
            'sum' => array_reduce($nilai, fn ($c, $x) => Desimal::tambah($c, $x), '0'),
            'avg' => $nilai === [] ? '0' : Desimal::bagi(array_reduce($nilai, fn ($c, $x) => Desimal::tambah($c, $x), '0'), (string) count($nilai)),
            'min' => $nilai === [] ? '0' : array_reduce($nilai, fn ($c, $x) => $c === null || Desimal::banding($x, $c) < 0 ? $x : $c),
            'max' => $nilai === [] ? '0' : array_reduce($nilai, fn ($c, $x) => $c === null || Desimal::banding($x, $c) > 0 ? $x : $c),
            default => throw new RumusException("Agregat tidak dikenal: {$v->aggregate}"),
        });
    }
}
