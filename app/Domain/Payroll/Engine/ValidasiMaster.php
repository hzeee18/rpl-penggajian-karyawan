<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Engine;

use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\MasterVersion;
use App\Domain\Payroll\Models\ReferenceTable;

/**
 * Validasi isi versi master sebelum disimpan/diterbitkan:
 * - sintaks rumus; kode unik di antara variabel, parameter, komponen, dan tidak memakai nama bawaan;
 * - rumus hanya merujuk yang sudah didefinisikan DI ATASNYA (sort_order) -> tidak ada rujukan sirkular;
 * - field baris (row.x) sesuai whitelist sumber; komponen di CONTRACT() dan tabel di LOOKUP() harus ada;
 * - paling banyak satu item pph21.
 */
final class ValidasiMaster
{
    /** @return list<string> daftar kesalahan (kosong = valid) */
    public function periksa(MasterVersion $versi): array
    {
        $versi->load(['variables', 'params', 'items.component']);
        $error = [];
        $dikenal = array_fill_keys(array_keys(Kalkulator::BAWAAN), true);
        $kodeKomponen = Component::pluck('code')->all();
        $tabel = ReferenceTable::where('is_active', true)->pluck('code')->all();
        $dipakai = [];

        $cekKode = function (string $kode, string $jenis) use (&$dipakai, &$error): void {
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $kode)) {
                $error[] = "{$jenis} {$kode}: kode hanya huruf kecil, angka, garis bawah, diawali huruf";
            }
            if (isset(Kalkulator::BAWAAN[$kode]) || isset(Kalkulator::BAWAAN_PPH21[$kode])) {
                $error[] = "{$jenis} {$kode}: kode sama dengan variabel bawaan";
            }
            if (isset($dipakai[$kode])) {
                $error[] = "{$jenis} {$kode}: kode sudah dipakai {$dipakai[$kode]}";
            }
            $dipakai[$kode] = $jenis;
        };

        $cekRumus = function (string $rumus, string $label, array $boleh, array $field = []) use (&$error, $kodeKomponen, $tabel): void {
            try {
                $r = Parser::rujukan($rumus);
            } catch (RumusException $e) {
                $error[] = "{$label}: ".$e->getMessage();

                return;
            }
            foreach ($r['var'] as $nama) {
                if (str_starts_with($nama, 'row.')) {
                    if (! isset($field[substr($nama, 4)])) {
                        $error[] = "{$label}: field {$nama} tidak tersedia".($field === [] ? ' (hanya untuk rumus filter/nilai variabel base)' : ' di sumber ini');
                    }
                } elseif (! isset($boleh[$nama])) {
                    $error[] = "{$label}: merujuk '{$nama}' yang belum didefinisikan di atasnya";
                }
            }
            foreach ($r['kontrak'] as $kode) {
                if (! in_array($kode, $kodeKomponen, true)) {
                    $error[] = "{$label}: komponen '{$kode}' di CONTRACT() tidak ada di katalog";
                }
            }
            foreach ($r['tabel'] as $kode) {
                if (! in_array($kode, $tabel, true)) {
                    $error[] = "{$label}: tabel referensi '{$kode}' tidak ada";
                }
            }
        };

        foreach ($versi->params as $p) {
            $cekKode($p->code, 'Parameter');
            $dikenal[$p->code] = true;
        }

        foreach ($versi->variables as $v) {
            $cekKode($v->code, 'Variabel');
            $label = "Variabel {$v->code}";
            if ($v->kind === 'base') {
                $field = SumberData::FIELD[$v->source] ?? null;
                if ($field === null || $v->aggregate === null) {
                    $error[] = "{$label}: variabel base wajib punya sumber dan agregat";
                } else {
                    foreach (['filter_expression' => 'filter', 'value_expression' => 'nilai'] as $kolom => $nama) {
                        if (filled($v->{$kolom})) {
                            $cekRumus((string) $v->{$kolom}, "{$label} ({$nama})", $dikenal, $field);
                        }
                    }
                }
            } elseif (blank($v->formula)) {
                $error[] = "{$label}: variabel derived wajib punya rumus";
            } else {
                $cekRumus((string) $v->formula, $label, $dikenal);
            }
            $dikenal[$v->code] = true; // baru boleh dirujuk oleh yang di bawahnya
        }

        $pph = $versi->items->where('item_role', 'pph21');
        if ($pph->count() > 1) {
            $error[] = 'Hanya boleh satu komponen PPh21';
        }
        $items = $versi->items->sortBy(fn ($i) => [$i->item_role === 'pph21' ? 1 : 0, $i->sort_order, $i->id]);
        foreach ($items as $i) {
            $cekKode($i->code, 'Komponen');
            $label = "Komponen {$i->code}";
            if ($i->calc_type === 'formula') {
                $boleh = $dikenal + ($i->item_role === 'pph21' ? array_fill_keys(array_keys(Kalkulator::BAWAAN_PPH21), true) : []);
                blank($i->formula) ? $error[] = "{$label}: wajib punya rumus" : $cekRumus((string) $i->formula, $label, $boleh);
            } elseif (! $i->component?->is_contractable) {
                $error[] = "{$label}: komponen tidak bisa dimasukkan ke kontrak, gunakan rumus";
            }
            $dikenal[$i->code] = true;
        }

        return array_values(array_unique($error));
    }
}
