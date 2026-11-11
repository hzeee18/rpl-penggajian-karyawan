<?php

namespace App\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use BelongsToCompany;

    use Notifiable;

    public const MAKS_GAGAL_LOGIN = 5;

    private ?bool $_managerHr = null;

    protected $fillable = ['role_id', 'karyawan_id', 'username', 'password', 'email', 'is_active', 'gagal_login', 'is_locked', 'last_login'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'last_login' => 'datetime',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function notifikasi()
    {
        return $this->hasMany(Notifikasi::class)->latest('id');
    }

    public function isRole(string ...$nama): bool
    {
        return in_array($this->role?->nama, $nama);
    }

    /** Cek hak akses. Manager HR mendapat hak role Manager + role HR sekaligus. */
    public function bisa(string $menu, string $aksi = 'view'): bool
    {
        if (Privilege::cekAkses($this->role_id, $menu, $aksi)) {
            return true;
        }
        if ($this->isManagerHr()) {
            $hr = Role::where('nama', Role::HR)->value('id');

            return $hr && Privilege::cekAkses($hr, $menu, $aksi);
        }

        return false;
    }

    /** Bertindak sebagai HR: role HR, atau Manager yang memimpin departemen HRD. */
    public function actsAsHr(): bool
    {
        return $this->isRole(Role::HR) || $this->isManagerHr();
    }

    /** Boleh melihat nominal gaji orang lain (slip, gaji pokok, upah lembur): Admin, HR, dan Manager HR. Manager divisi tidak. */
    public function bolehLihatGaji(): bool
    {
        return $this->isRole(Role::ADMIN) || $this->actsAsHr();
    }

    /** Manager divisi biasa (bukan Manager HR): melihat data timnya saja. */
    public function isManagerTim(): bool
    {
        return $this->isRole(Role::MANAGER) && ! $this->isManagerHr();
    }

    /** Manager yang memimpin departemen HR (approver payroll). */
    public function isManagerHr(): bool
    {
        return $this->_managerHr ??= $this->isRole(Role::MANAGER) && $this->karyawan_id
            && Departemen::where('kode', Departemen::KODE_HR)->dikelola($this->karyawan_id)->exists();
    }

    /**
     * Daftar id karyawan yang boleh dilihat user ini.
     * null = semua (Admin/HR), Manager = timnya + diri sendiri, Karyawan = dirinya.
     */
    public function karyawanIds(): ?array
    {
        if ($this->isRole(Role::ADMIN) || $this->actsAsHr()) {
            return null;
        }
        if ($this->isRole(Role::MANAGER) && $this->karyawan_id) {
            $deptIds = Departemen::dikelola($this->karyawan_id)->pluck('id');

            return Karyawan::whereIn('departemen_id', $deptIds)->pluck('id')
                ->push($this->karyawan_id)->unique()->values()->all();
        }

        return $this->karyawan_id ? [$this->karyawan_id] : [];
    }

    /** Batasi query (yang punya kolom karyawan_id / id) ke karyawan yang boleh dilihat. */
    public function batasi($query, string $kolom = 'karyawan_id')
    {
        $ids = $this->karyawanIds();

        return $ids === null ? $query : $query->whereIn($kolom, $ids);
    }

    public function namaTampil(): string
    {
        return $this->karyawan?->nama ?? $this->username;
    }

    public static function idsRole(string $role): array
    {
        return static::whereHas('role', fn ($q) => $q->where('nama', $role))->where('is_active', true)->pluck('id')->all();
    }
}
