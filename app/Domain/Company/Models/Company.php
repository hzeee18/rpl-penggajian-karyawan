<?php

declare(strict_types=1);

namespace App\Domain\Company\Models;

use Illuminate\Database\Eloquent\Model;

/** Perusahaan (tenant). Tidak memakai BelongsToCompany karena ia sendiri tenant-nya. */
final class Company extends Model
{
    public const DEFAULT_CODE = 'DEFAULT';

    protected $table = 'companies';

    protected $fillable = [
        'code', 'name', 'address', 'logo_path', 'signer_name', 'signer_title', 'signature_path', 'is_active', 'payroll_baru', 'payroll_butuh_approval', 'slip_pakai_password', 'prorata_dasar',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'payroll_baru' => 'boolean', 'payroll_butuh_approval' => 'boolean', 'slip_pakai_password' => 'boolean'];
    }
}
