<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Company\Tenancy\TenantRule;
use App\Domain\Payroll\Models\Position;
use Illuminate\Database\Eloquent\Model;

final class JabatanController extends MasterSederhanaController
{
    protected function definisi(): array
    {
        return [
            'model' => Position::class, 'route' => 'pay.jabatan', 'judul' => 'Jabatan', 'urut' => 'code', 'hapus' => 'nonaktif',
            'sub' => 'Daftar jabatan perusahaan. Jabatan tidak dihapus, hanya dinonaktifkan.',
            'kolom' => ['code' => 'Kode', 'name' => 'Nama'],
            'field' => [
                ['name' => 'code', 'label' => 'Kode', 'type' => 'text'],
                ['name' => 'name', 'label' => 'Nama jabatan', 'type' => 'text'],
                ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'checkbox'],
            ],
        ];
    }

    protected function aturan(?Model $data): array
    {
        return [
            'code' => ['required', 'max:32', 'alpha_dash', TenantRule::unique('pay_positions', 'code')->ignore($data)],
            'name' => 'required|max:191',
            'is_active' => 'nullable|boolean',
        ];
    }
}
