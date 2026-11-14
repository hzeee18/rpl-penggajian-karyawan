<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Penyetuju payroll yang dipilih perusahaan: satu user ATAU satu role (AUDIT.md §77). */
final class PayrollApprover extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_payroll_approvers';

    protected $fillable = ['user_id', 'role_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
