<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Company\Tenancy\TenantRule;
use App\Domain\Payroll\Models\Holiday;
use Illuminate\Database\Eloquent\Model;

final class LiburController extends MasterSederhanaController
{
    protected function definisi(): array
    {
        return [
            'model' => Holiday::class, 'route' => 'pay.libur', 'judul' => 'Hari libur', 'urut' => 'holiday_date', 'hapus' => 'fisik',
            'sub' => 'Hari libur dikurangkan dari hari kerja (fungsi WORKDAYS di rumus payroll).',
            'kolom' => ['holiday_date' => 'Tanggal', 'name' => 'Keterangan'],
            'field' => [
                ['name' => 'holiday_date', 'label' => 'Tanggal', 'type' => 'date'],
                ['name' => 'name', 'label' => 'Keterangan', 'type' => 'text'],
            ],
        ];
    }

    protected function aturan(?Model $data): array
    {
        return [
            'holiday_date' => ['required', 'date', TenantRule::unique('pay_holidays', 'holiday_date')->ignore($data)],
            'name' => 'required|max:191',
        ];
    }
}
