<?php

declare(strict_types=1);

namespace App\Domain\Company\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Batasi query ke perusahaan aktif (aturan konteks: CurrentCompany). */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentCompany::class);

        if ($context->isLintasPerusahaan()) {
            return;
        }

        $companyId = $context->id();
        if ($companyId === null) {
            $builder->whereRaw('1 = 0'); // fail closed

            return;
        }

        $builder->where($model->qualifyColumn('company_id'), $companyId);
    }
}
