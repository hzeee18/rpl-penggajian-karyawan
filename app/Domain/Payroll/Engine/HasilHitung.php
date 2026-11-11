<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Engine;

/** Hasil perhitungan payroll satu karyawan. Semua nominal string desimal 2 digit. */
final readonly class HasilHitung
{
    /**
     * @param  array<string, string>  $variabel  kode => nilai (snapshot)
     * @param  list<array{item_id: int, component_id: int, code: string, name: string, type: string, is_taxable: bool, sort_order: int, calculated: string, final: string, overridden: bool}>  $item
     * @param  array<int, int>  $versiReferensi  reference_table_id => version_id
     */
    public function __construct(
        public array $variabel,
        public array $item,
        public string $totalPenambah,
        public string $totalPengurang,
        public string $brutoKenaPajak,
        public string $thp,
        public array $versiReferensi,
    ) {}
}
