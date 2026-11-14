<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Company\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Pencatat audit trail payroll baru (PAYROLL_SPEC.md: generate, approve/reject, kontrak,
 * master payroll, variabel, tabel referensi, master data). JANGAN dipakai untuk edit sel preview.
 *
 * Kolom sensitif tidak pernah ditulis ke log.
 */
final class AuditLogger
{
    /** Kolom yang selalu dibuang dari old_values / new_values. */
    private const REDACTED = ['password', 'remember_token', 'npwp', 'bank_account_no', 'no_rekening'];

    public function __construct(private readonly CurrentCompany $company) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function log(string $action, Model|string $entity, ?int $entityId = null, ?array $old = null, ?array $new = null): AuditLog
    {
        if ($entity instanceof Model) {
            $entityId ??= (int) $entity->getKey();
            $companyId = $entity->getAttribute('company_id');
            $entity = $entity::class;
        }

        return AuditLog::create([
            'company_id' => isset($companyId) ? (int) $companyId : $this->company->id(),
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_type' => $entity,
            'entity_id' => $entityId,
            'old_values' => $old === null ? null : Arr::except($old, self::REDACTED),
            'new_values' => $new === null ? null : Arr::except($new, self::REDACTED),
            'ip_address' => Request::ip(),
        ]);
    }

    /**
     * Catat hasil save() terakhir: setelah insert = semua atribut; setelah update = hanya kolom yang berubah.
     * (wasRecentlyCreated tetap true bila model di-update dalam request yang sama, jadi dicek bersama getChanges.)
     */
    public function logChanges(string $action, Model $model): AuditLog
    {
        $changes = Arr::except($model->getChanges(), ['updated_at']);

        if ($model->wasRecentlyCreated && $changes === []) {
            return $this->log($action, $model, old: null, new: $model->getAttributes());
        }

        $changed = array_keys($changes);

        return $this->log(
            $action,
            $model,
            old: Arr::only($model->getPrevious(), $changed),
            new: Arr::only($model->getAttributes(), $changed),
        );
    }
}
