<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    protected $fillable = ['parent_id', 'nama', 'url', 'icon', 'urutan'];

    public function parent()
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Menu::class, 'parent_id')->orderBy('urutan');
    }

    public function privileges()
    {
        return $this->hasMany(Privilege::class);
    }

    /** Multi-tenant: tanpa menu khusus Super Admin (platform). */
    public function scopeUntukPerusahaan($q)
    {
        return $q->where(fn ($w) => $w->whereNull('url')->orWhereNotIn('url', \App\Domain\Company\Actions\PasangHakPlatform::MENU_PLATFORM));
    }

    public function isPlatform(): bool
    {
        return in_array($this->url, \App\Domain\Company\Actions\PasangHakPlatform::MENU_PLATFORM, true);
    }
}
