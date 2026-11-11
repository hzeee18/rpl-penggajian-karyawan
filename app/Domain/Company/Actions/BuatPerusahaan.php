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
}
