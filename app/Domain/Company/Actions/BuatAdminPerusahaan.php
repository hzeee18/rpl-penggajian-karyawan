<?php

declare(strict_types=1);

namespace App\Domain\Company\Actions;

use App\Domain\Company\Tenancy\CurrentCompany;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Akun Admin perusahaan yang dibuat Super Admin (AUDIT.md §36): saat perusahaan dibuat (akun pertama)
 * atau dari panel Ubah Perusahaan. Admin perusahaan lalu mengelola user, role, dan data perusahaannya.
 * Username tetap unik global (login tanpa memilih perusahaan).
 */
final class BuatAdminPerusahaan
{
    public function __construct(private readonly CurrentCompany $konteks) {}

    /** @param array{username: string, email?: ?string, password: string} $data */
    public function handle(int $companyId, array $data): User
    {
        return $this->konteks->runAs($companyId, function () use ($data): User {
            $role = Role::where('nama', Role::ADMIN)->value('id')
                ?? throw new RuntimeException('Role Admin tidak ditemukan di perusahaan ini.');

            return User::create([
                'username' => $data['username'], 'email' => $data['email'] ?? null, 'password' => $data['password'],
                'role_id' => $role, 'karyawan_id' => null, 'is_active' => true,
            ]);
        });
    }

    /** @return Collection<int, User> akun Admin perusahaan tersebut */
    public function daftar(int $companyId): Collection
    {
        return $this->konteks->runAs($companyId, fn () => User::whereHas('role', fn ($q) => $q->where('nama', Role::ADMIN))->orderBy('username')->get());
    }
}
