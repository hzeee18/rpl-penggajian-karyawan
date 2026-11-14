<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Contract;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buat kontrak baru. Nilai default komponen DISALIN dari katalog (perubahan katalog sesudahnya
 * tidak memengaruhi kontrak ini); nilai yang diisi HR menimpa default.
 */
final class BuatKontrak
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{employee_id: int, contract_name: string, start_date: string, end_date?: ?string, info_thp?: ?string}  $data
     * @param  array<int, string|null>  $nilai  component_id => nominal (string desimal); hanya komponen ini yang masuk kontrak
     */
    public function handle(array $data, array $nilai, ?int $previousContractId = null): Contract
    {
        return DB::transaction(function () use ($data, $nilai, $previousContractId): Contract {
            if (Contract::where('employee_id', $data['employee_id'])->active()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['employee_id' => 'Karyawan ini masih punya kontrak aktif. Perbarui atau nonaktifkan kontrak lama dulu.']);
            }

            $katalog = Component::where('is_active', true)->where('is_contractable', true)->whereIn('id', array_keys($nilai))->get()->keyBy('id');
            if ($katalog->count() !== count($nilai)) {
                throw ValidationException::withMessages(['komponen' => 'Ada komponen yang tidak aktif atau tidak boleh dimasukkan ke kontrak.']);
            }

            $contract = Contract::create($data + [
                'previous_contract_id' => $previousContractId,
                'is_active' => true,
                'created_by' => Auth::id(),
            ]);

            foreach ($nilai as $componentId => $amount) {
                $contract->components()->create([
                    'component_id' => $componentId,
                    'amount' => $amount === null || $amount === '' ? $katalog[$componentId]->default_amount : (string) $amount,
                ]);
            }

            $this->audit->log($previousContractId ? 'renewed' : 'created', $contract, new: $contract->getAttributes() + [
                'komponen' => $contract->components()->with('component:id,code')->get()->mapWithKeys(fn ($c) => [$c->component->code => $c->amount])->all(),
            ]);

            return $contract;
        });
    }
}
