<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ubah kontrak aktif di tempat (koreksi nama, tanggal, THP info, komponen & nominal) tanpa membuat versi baru.
 * Berbeda dengan PerbaruiKontrak (kontrak lama dinonaktifkan, kontrak baru dibuat). Ditolak bila kontrak
 * sudah dipakai payroll final (Contract::terkunci); gunakan Perbarui. Perubahan tercatat di audit log.
 */
final class UbahKontrak
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{contract_name: string, start_date: string, end_date?: ?string, info_thp?: ?string}  $data
     * @param  array<int, string|null>  $nilai  component_id => nominal; hanya komponen ini yang ada di kontrak setelahnya
     */
    public function handle(Contract $kontrak, array $data, array $nilai): Contract
    {
        return DB::transaction(function () use ($kontrak, $data, $nilai): Contract {
            $kontrak = Contract::lockForUpdate()->findOrFail($kontrak->id);
            if (! $kontrak->is_active) {
                throw ValidationException::withMessages(['contract' => 'Hanya kontrak aktif yang bisa diubah.']);
            }
            if ($kontrak->terkunci()) {
                throw ValidationException::withMessages(['contract' => 'Kontrak ini sudah dipakai payroll yang diajukan/disetujui. Gunakan Perbarui untuk membuat versi baru.']);
            }

            $lamaKomponen = $kontrak->components()->pluck('amount', 'component_id')->map(fn ($a) => (string) $a)->all();
            $katalog = Component::where('is_contractable', true)->whereIn('id', array_keys($nilai))->get()->keyBy('id');
            foreach (array_keys($nilai) as $id) {
                // komponen baru harus aktif; komponen yang sudah ada di kontrak boleh tetap walau katalognya dinonaktifkan
                if (! isset($katalog[$id]) || (! $katalog[$id]->is_active && ! isset($lamaKomponen[$id]))) {
                    throw ValidationException::withMessages(['komponen' => 'Ada komponen yang tidak aktif atau tidak boleh dimasukkan ke kontrak.']);
                }
            }

            $lama = $kontrak->only(['contract_name', 'start_date', 'end_date', 'info_thp']);
            $kontrak->fill($data)->save();

            $kontrak->components()->whereNotIn('component_id', array_keys($nilai))->delete();
            foreach ($nilai as $componentId => $amount) {
                $kontrak->components()->updateOrCreate(
                    ['component_id' => $componentId],
                    ['amount' => $amount === null || $amount === '' ? ($lamaKomponen[$componentId] ?? $katalog[$componentId]->default_amount) : (string) $amount],
                );
            }

            $kode = fn () => $kontrak->components()->with('component:id,code')->get()->mapWithKeys(fn ($c) => [$c->component->code => (string) $c->amount])->all();
            $this->audit->log('updated', $kontrak,
                old: $lama + ['komponen' => Component::whereIn('id', array_keys($lamaKomponen))->pluck('code', 'id')->mapWithKeys(fn ($code, $id) => [$code => $lamaKomponen[$id]])->all()],
                new: $kontrak->only(['contract_name', 'start_date', 'end_date', 'info_thp']) + ['komponen' => $kode()]);

            return $kontrak;
        });
    }
}
