<?php

declare(strict_types=1);

namespace App\Domain\Company\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CompanyScope;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Models\ApprovalMapping;
use App\Models\Privilege;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Buat perusahaan baru (Super Admin) lengkap dengan role, hak akses, dan approval mapping
 * yang disalin dari perusahaan DEFAULT sebagai templat. Setelah itu Admin perusahaan
 * mengatur sendiri role dan hak aksesnya (privilege per perusahaan).
 */
final class BuatPerusahaan
{
    /**
     * Role & hak bawaan bila perusahaan templat DEFAULT tidak ada (mis. dihapus saat cleansing, AUDIT.md §39).
     * Hanya menu umum; menu payroll baru, hak HR/Admin lengkap, dan Super Admin dipasang AlihkanKePayrollBaru.
     */
    public const ROLE_BAWAAN = [
        Role::ADMIN => [],
        Role::HR => ['dashboard' => 'v', 'karyawan' => 'vcud', 'profil' => 'v'],
        Role::MANAGER => ['dashboard' => 'v', 'karyawan' => 'v', 'profil' => 'v'],
        Role::KARYAWAN => ['dashboard' => 'v', 'profil' => 'v'],
    ];

    public function __construct(private readonly CurrentCompany $konteks) {}

    /** @param  array{code: string, name: string, address?: ?string, signer_name?: ?string, signer_title?: ?string}  $data */
    public function handle(array $data): Company
    {
        return DB::transaction(function () use ($data): Company {
            $company = Company::create($data + ['is_active' => true]);
            $templat = (int) Company::where('code', Company::DEFAULT_CODE)->value('id');

            // baca templat DEFAULT: query eksplisit ke perusahaan DEFAULT, bukan lintas perusahaan
            $roles = Role::withoutGlobalScope(CompanyScope::class)->where('company_id', $templat)->with([
                'privileges' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class),
            ])->orderBy('id')->get();
            $mapping = ApprovalMapping::withoutGlobalScope(CompanyScope::class)->where('company_id', $templat)->orderBy('id')->get();

            $this->konteks->runAs((int) $company->id, function () use ($roles, $mapping) {
                if ($roles->isEmpty()) {
                    $this->roleBawaan();

                    return;
                }
                foreach ($roles as $asal) {
                    $role = Role::create(['nama' => $asal->nama]);
                    foreach ($asal->privileges as $p) {
                        Privilege::create(['role_id' => $role->id] + $p->only(['menu_id', 'can_view', 'can_create', 'can_update', 'can_delete', 'can_approve']));
                    }
                }
                foreach ($mapping as $m) {
                    ApprovalMapping::create($m->only(['jenis_ref', 'level', 'tipe_approver']));
                }
            });

            return $company;
        });
    }

    /** Tanpa templat: role standar dengan hak menu umum dari ROLE_BAWAAN (konteks perusahaan baru). */
    private function roleBawaan(): void
    {
        $menu = DB::table('menus')->pluck('id', 'url');
        foreach (self::ROLE_BAWAAN as $nama => $hak) {
            $role = Role::create(['nama' => $nama]);
            foreach ($hak as $url => $h) {
                if (isset($menu[$url])) {
                    Privilege::create(['role_id' => $role->id, 'menu_id' => $menu[$url], 'can_view' => str_contains($h, 'v'), 'can_create' => str_contains($h, 'c'),
                        'can_update' => str_contains($h, 'u'), 'can_delete' => str_contains($h, 'd'), 'can_approve' => str_contains($h, 'a')]);
                }
            }
        }
    }
}
