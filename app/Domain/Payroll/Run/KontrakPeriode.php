<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Run;

use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * Kontrak yang berlaku untuk satu karyawan dalam satu periode (AUDIT.md §51), dipilih dari TANGGAL:
 * kontrak aktif + kontrak lama yang sudah diperbarui (punya penerus), yang rentangnya beririsan dengan periode.
 * Tiap kontrak jadi satu segmen yang dibatasi: awal periode / tanggal masuk, akhir periode / tanggal resign,
 * tanggal akhir kontrak, dan sehari sebelum kontrak berikutnya mulai. Kontrak yang dinonaktifkan manual tidak dipakai.
 */
final class KontrakPeriode
{
    /** @param list<array{contract: Contract, mulai: CarbonImmutable, selesai: CarbonImmutable}> $segmen */
    public function __construct(
        public readonly array $segmen,
        public readonly CarbonImmutable $mulai,
        public readonly CarbonImmutable $selesai,
    ) {}

    public static function untuk(Employee $employee, CarbonImmutable $mulai, CarbonImmutable $selesai): self
    {
        $kontrak = Contract::with('components.component')->where('employee_id', $employee->id)
            ->where(fn ($q) => $q->where('is_active', true)
                ->orWhereIn('id', Contract::select('previous_contract_id')->whereNotNull('previous_contract_id')))
            ->where('start_date', '<=', $selesai->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $mulai->toDateString()))
            ->orderBy('start_date')->orderBy('id')->get()->values();

        $awal = $employee->tgl_masuk !== null ? CarbonImmutable::parse($employee->tgl_masuk)->max($mulai) : $mulai;
        $akhir = $employee->resign_date !== null ? CarbonImmutable::parse($employee->resign_date)->min($selesai) : $selesai;

        $segmen = [];
        foreach ($kontrak as $i => $c) {
            $s = CarbonImmutable::parse($c->start_date)->max($awal);
            $e = $c->end_date !== null ? CarbonImmutable::parse($c->end_date)->min($akhir) : $akhir;
            if (isset($kontrak[$i + 1])) {
                $e = $e->min(CarbonImmutable::parse($kontrak[$i + 1]->start_date)->subDay());
            }
            if ($s->lte($e)) {
                $segmen[] = ['contract' => $c, 'mulai' => $s->startOfDay(), 'selesai' => $e->startOfDay()];
            }
        }

        return new self($segmen, $mulai, $selesai);
    }

    /** Satu kontrak penuh (tanpa segmen, untuk simulasi master / data lama): dianggap berlaku sepanjang periode. */
    public static function penuhDari(Contract $contract, CarbonImmutable $mulai, CarbonImmutable $selesai): self
    {
        return new self([['contract' => $contract, 'mulai' => $mulai->startOfDay(), 'selesai' => $selesai->startOfDay()]], $mulai, $selesai);
    }

    public function kosong(): bool
    {
        return $this->segmen === [];
    }

    /** Kontrak terbaru di periode: dipakai untuk fungsi KONTRAK() di rumus dan snapshot run. */
    public function terakhir(): ?Contract
    {
        return $this->segmen === [] ? null : $this->segmen[array_key_last($this->segmen)]['contract'];
    }

    /** Satu kontrak menutup seluruh periode: nilai kontrak dibayar penuh. */
    public function penuh(): bool
    {
        return count($this->segmen) === 1
            && $this->segmen[0]['mulai']->isSameDay($this->mulai) && $this->segmen[0]['selesai']->isSameDay($this->selesai);
    }

    /** Hari kalender yang tertutup kontrak (dan masa kerja) di periode. */
    public function hariDibayar(): int
    {
        return array_sum(array_map(fn ($s) => (int) $s['mulai']->diffInDays($s['selesai']) + 1, $this->segmen));
    }
}
