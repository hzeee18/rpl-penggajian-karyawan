<?php

namespace App\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Privilege extends Model
{
    use BelongsToCompany;

    public const AKSI = ['view', 'create', 'update', 'delete', 'approve'];

    protected $fillable = ['role_id', 'menu_id', 'can_view', 'can_create', 'can_update', 'can_delete', 'can_approve'];

    protected $casts = [
        'can_view' => 'boolean', 'can_create' => 'boolean', 'can_update' => 'boolean',
        'can_delete' => 'boolean', 'can_approve' => 'boolean',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    /** Cek apakah role boleh melakukan aksi pada menu (url). */
    public static function cekAkses(int $roleId, string $menuUrl, string $aksi = 'view'): bool
    {
        // multi-tenant: dibatasi role_id user (role-nya sendiri); tanpa scope agar hak Super Admin (company_id NULL) terbaca
        return static::withoutGlobalScope(\App\Domain\Company\Tenancy\CompanyScope::class)->where('role_id', $roleId)
            ->whereHas('menu', fn ($q) => $q->where('url', $menuUrl))
            ->where('can_' . $aksi, true)
            ->exists();
    }
}
