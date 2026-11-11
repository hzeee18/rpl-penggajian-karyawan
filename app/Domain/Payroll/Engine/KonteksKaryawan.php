<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Engine;

use App\Domain\Payroll\Models\ReferenceTable;
use App\Domain\Payroll\Models\ReferenceTableVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Konteks rumus untuk satu karyawan dalam satu periode. Menyimpan nilai yang sudah dihitung,
 * nilai komponen kontrak, kalender libur, dan versi tabel referensi yang dipakai.
 */
final class KonteksKaryawan implements KonteksRumus
{
    /** @var array<string, Nilai> */
    private array $nilai = [];

    /** @var array<string, Nilai>|null field baris sumber saat mengevaluasi filter/value expression */
    private ?array $baris = null;

    /** @var array<string, ReferenceTableVersion|null> */
    private array $versiTabel = [];

    /**
     * @param  array<string, string>  $kontrak  kode komponen => nominal
     * @param  array<string, true>  $libur  tanggal libur
     */
    public function __construct(
        private readonly array $kontrak,
        private readonly array $libur,
        private readonly string $tanggalAcuan,
    ) {}

    public function set(string $nama, Nilai $nilai): void
    {
        $this->nilai[$nama] = $nilai;
    }

    /** @param array<string, mixed>|null $baris */
    public function denganBaris(?array $baris): void
    {
        $this->baris = $baris === null ? null : array_combine(
            array_map(fn ($k) => 'row.'.$k, array_keys($baris)),
            array_map(fn ($v) => Nilai::dari($v), array_values($baris)),
        );
    }

    public function nilai(string $nama): ?Nilai
    {
        return $this->baris[$nama] ?? $this->nilai[$nama] ?? null;
    }

    public function hariKerja(string $mulai, string $selesai): int
    {
        $n = 0;
        foreach (CarbonPeriod::create(CarbonImmutable::parse($mulai), CarbonImmutable::parse($selesai)) as $d) {
            if (! $d->isWeekend() && ! isset($this->libur[$d->toDateString()])) {
                $n++;
            }
        }

        return $n;
    }

    public function lookup(string $tabel, string $kunci, ?string $nilai, bool $teks): ?string
    {
        $versi = $this->versi($tabel);
        if ($versi === null) {
            throw new RumusException("Tabel referensi '{$tabel}' tidak ada atau belum punya versi terbit");
        }

        $q = $versi->rows()->getQuery()->where(fn ($w) => $kunci === '' ? $w->whereNull('key_value')->orWhere('key_value', '') : $w->where('key_value', $kunci));
        foreach ($q->get() as $row) {
            if ($nilai !== null) {
                if ($row->range_from !== null && Desimal::banding($nilai, (string) $row->range_from) < 0) {
                    continue;
                }
                if ($row->range_to !== null && Desimal::banding($nilai, (string) $row->range_to) > 0) {
                    continue;
                }
            }
            $hasil = $teks ? $row->result_text : $row->result_number;

            return $hasil === null ? null : ($teks ? (string) $hasil : Desimal::normal((string) $hasil));
        }

        return null;
    }

    public function kontrak(string $kodeKomponen): string
    {
        return $this->kontrak[$kodeKomponen] ?? '0';
    }

    /** @return array<int, int> reference_table_id => version_id yang dipakai */
    public function versiDipakai(): array
    {
        $hasil = [];
        foreach ($this->versiTabel as $v) {
            if ($v !== null) {
                $hasil[(int) $v->reference_table_id] = (int) $v->id;
            }
        }

        return $hasil;
    }

    /** Tabel milik perusahaan didahulukan, lalu tabel nasional. */
    private function versi(string $kode): ?ReferenceTableVersion
    {
        if (! array_key_exists($kode, $this->versiTabel)) {
            $tabel = ReferenceTable::where('code', $kode)->where('is_active', true)->orderByRaw('company_id is null')->first();
            $this->versiTabel[$kode] = $tabel?->versiBerlaku($this->tanggalAcuan);
        }

        return $this->versiTabel[$kode];
    }
}
