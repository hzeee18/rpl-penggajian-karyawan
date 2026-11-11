<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Company\Tenancy\TenantRule;
use App\Domain\Payroll\Models\Component;
use Illuminate\Database\Eloquent\Model;

final class KomponenController extends MasterSederhanaController
{
    protected function definisi(): array
    {
        return [
            'model' => Component::class, 'route' => 'pay.komponen', 'judul' => 'Komponen', 'urut' => 'code', 'hapus' => 'nonaktif',
            'sub' => 'Katalog komponen gaji. Nilai bawaan disalin ke kontrak saat kontrak dibuat. Komponen hanya dinonaktifkan.',
            'kolom' => ['code' => 'Kode', 'name' => 'Nama', 'type' => 'Jenis', 'default_amount' => 'Nilai bawaan'],
            'field' => [
                ['name' => 'code', 'label' => 'Kode (dipakai di rumus)', 'type' => 'text', 'bantuan' => 'Huruf kecil, angka, garis bawah. Contoh: uang_makan'],
                ['name' => 'name', 'label' => 'Nama', 'type' => 'text'],
                ['name' => 'type', 'label' => 'Jenis', 'type' => 'select', 'opsi' => ['earning' => 'Penambah', 'deduction' => 'Pengurang']],
                ['name' => 'default_amount', 'label' => 'Nilai bawaan (Rp)', 'type' => 'number'],
                ['name' => 'is_taxable', 'label' => 'Kena pajak (masuk bruto PPh21)', 'type' => 'checkbox'],
                ['name' => 'is_contractable', 'label' => 'Bisa dimasukkan ke kontrak', 'type' => 'checkbox'],
                ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'checkbox'],
            ],
        ];
    }

    protected function aturan(?Model $data): array
    {
        return [
            'code' => ['required', 'max:64', self::POLA_KODE, TenantRule::unique('pay_components', 'code')->ignore($data)],
            'name' => 'required|max:191',
            'type' => 'required|in:earning,deduction',
            'default_amount' => 'required|decimal:0,2|min:0|max:9999999999999.99',
            'is_taxable' => 'nullable|boolean',
            'is_contractable' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ];
    }
}
