<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenant;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Models\Departemen;
use App\Models\Karyawan;
use App\Models\Privilege;
use App\Models\Role;
use App\Models\User;

/**
 * Membuat perusahaan kedua untuk tes kebocoran tenant, di samping data seed lama (DEFAULT).
 * Role-nya meniru role DEFAULT dengan hak akses yang sama.
 */
trait BuatPerusahaanLain
{
    protected function defaultId(): int
    {
        return (int) Company::where('code', Company::DEFAULT_CODE)->value('id');
    }

    /**
     * @param  list<string>  $peran  nama role lama yang ditiru (Role::ADMIN, Role::HR, ...)
     * @return array{company: Company, users: array<string, User>, departemen: Departemen, karyawan: Karyawan}
     */
    protected function perusahaanLain(string $kode = 'B', array $peran = [Role::HR]): array
    {
        $company = Company::create(['code' => $kode, 'name' => "Perusahaan {$kode}"]);

        return app(CurrentCompany::class)->runAs($company->id, function () use ($company, $kode, $peran) {
            $users = [];
            foreach ($peran as $nama) {
                $asal = Role::withoutGlobalScopes()->where('company_id', $this->defaultId())->where('nama', $nama)->firstOrFail();
                $role = Role::create(['nama' => $nama]);
                foreach (Privilege::withoutGlobalScopes()->where('role_id', $asal->id)->get() as $p) {
                    Privilege::create(['role_id' => $role->id] + $p->only(['menu_id', 'can_view', 'can_create', 'can_update', 'can_delete', 'can_approve']));
                }
                $username = strtolower($nama).'.'.strtolower($kode);
                $users[$nama] = User::create(['role_id' => $role->id, 'username' => $username, 'password' => 'password', 'is_active' => true]);
            }

            $departemen = Departemen::create(['kode' => 'BX', 'nama' => "Departemen Rahasia {$kode}"]);
            $karyawan = Karyawan::create(['nik' => "{$kode}0001", 'nama' => "Karyawan Rahasia {$kode}", 'departemen_id' => $departemen->id, 'is_active' => true]);

            return ['company' => $company, 'users' => $users, 'departemen' => $departemen, 'karyawan' => $karyawan];
        });
    }
}
