<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Company\Tenancy\TenantRule;
use App\Domain\Payroll\Models\LeaveType;
use Illuminate\Database\Eloquent\Model;

final class TipeLeaveController extends MasterSederhanaController
{
    protected function definisi(): array
    {
        return [
            'model' => LeaveType::class, 'route' => 'pay.tipe-leave', 'judul' => 'Tipe leave', 'urut' => 'code', 'hapus' => 'nonaktif',
            'sub' => 'Tipe cuti, izin, sakit, dan tipe lain. Kode dipakai di filter variabel rumus.',
            'kolom' => ['code' => 'Kode', 'name' => 'Nama'],
            'field' => [
                ['name' => 'code', 'label' => 'Kode', 'type' => 'text', 'bantuan' => 'Huruf kecil, angka, garis bawah. Contoh: sakit_bersurat'],
                ['name' => 'name', 'label' => 'Nama', 'type' => 'text'],
                ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'checkbox'],
            ],
        ];
    }

    protected function aturan(?Model $data): array
    {
        return [
            'code' => ['required', 'max:32', self::POLA_KODE, TenantRule::unique('pay_leave_types', 'code')->ignore($data)],
            'name' => 'required|max:100',
            'is_active' => 'nullable|boolean',
        ];
    }
}
