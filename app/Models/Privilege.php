<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Privilege extends Model
{
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
        return static::where('role_id', $roleId)
            ->whereHas('menu', fn ($q) => $q->where('url', $menuUrl))
            ->where('can_' . $aksi, true)
            ->exists();
    }
}
