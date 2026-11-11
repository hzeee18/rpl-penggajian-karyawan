<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Engine;

use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\MasterVersion;
use App\Domain\Payroll\Models\Variable;
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
        'workdays' => 'hari kerja Senin-Jumat periode dikurangi libur',
    ];

    /** Hanya tersedia di rumus item pph21. */
    public const BAWAAN_PPH21 = ['gross_taxable' => 'jumlah nilai akhir komponen penambah kena pajak'];

    public function __construct(private readonly MasterVersion $versi)
    {
        $this->versi->loadMissing(['variables', 'params', 'items.component']);
    }

    /** @param array<string, string> $override kode item => nilai akhir */
    public function hitung(Employee $employee, ?Contract $contract, CarbonImmutable $mulai, CarbonImmutable $selesai, array $override = []): HasilHitung
    {
        $contract?->loadMissing('components.component');
        $nilaiKontrak = [];
        foreach ($contract?->components ?? [] as $cc) {
            $nilaiKontrak[$cc->component->code] = Desimal::normal((string) $cc->amount);
        }

        $sumber = new SumberData($employee, $contract, $mulai, $selesai);
        $k = new KonteksKaryawan($nilaiKontrak, $sumber->libur(), $selesai->toDateString());
        $e = new Evaluator($k);
        $snapshot = [];

        $bawaan = [
            'period_start' => Nilai::teks($mulai->toDateString()), 'period_end' => Nilai::teks($selesai->toDateString()),
            'join_date' => Nilai::teks($employee->tgl_masuk?->toDateString() ?? ''), 'resign_date' => Nilai::teks($employee->resign_date?->toDateString() ?? ''),
            'ptkp_status' => Nilai::teks((string) $employee->ptkpStatus()), 'has_npwp' => Nilai::bool((bool) $employee->has_npwp),
            'workdays' => Nilai::angka($k->hariKerja($mulai->toDateString(), $selesai->toDateString())),
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
        foreach ($items as $item) {
            if ($item->item_role === 'pph21') {
                $k->set('gross_taxable', Nilai::angka($bruto));
                $snapshot['gross_taxable'] = $bruto;
            }
            try {
                $hitung = $item->calc_type === 'contract' ? $k->kontrak($item->component->code) : $e->angka((string) $item->formula);
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
            $hasilItem[] = [
                'item_id' => (int) $item->id, 'component_id' => (int) $item->component_id, 'code' => $item->code, 'name' => $item->name,
                'type' => $item->type, 'is_taxable' => (bool) $item->is_taxable, 'sort_order' => (int) $item->sort_order,
                'calculated' => Desimal::uang($hitung), 'final' => Desimal::uang($akhir), 'overridden' => $diubah,
            ];
        }

        $penambah = '0';
        $pengurang = '0';
        foreach ($hasilItem as $h) {
            $h['type'] === 'earning' ? $penambah = Desimal::tambah($penambah, $h['final']) : $pengurang = Desimal::tambah($pengurang, $h['final']);
        }

        return new HasilHitung(
            $snapshot, $hasilItem, Desimal::uang($penambah), Desimal::uang($pengurang), Desimal::uang($bruto),
            Desimal::uang(Desimal::kurang($penambah, $pengurang)), $k->versiDipakai(),
        );
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
