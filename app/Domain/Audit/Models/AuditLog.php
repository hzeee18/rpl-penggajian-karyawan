<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Baris audit trail (hanya ditulis lewat AuditLogger, tidak pernah diubah).
 * Tidak memakai BelongsToCompany: company_id boleh NULL (aksi Super Admin);
 * pembatasan baca per perusahaan dilakukan di layar audit (fase berikutnya).
 */
final class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $fillable = [
        'company_id', 'user_id', 'action', 'entity_type', 'entity_id', 'old_values', 'new_values', 'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
