<?php

declare(strict_types=1);

namespace App\Domain\Company\Tenancy;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Aturan validasi unique/exists untuk tabel bertenant: dibatasi ke perusahaan aktif.
 * Rule bawaan Laravel memakai query builder (tanpa global scope), jadi wajib lewat helper ini.
 * Jangan dipakai untuk users.username (unik global) dan tabel nasional/global (pajak, golongan_ter, menus).
 */
final class TenantRule
{
    public static function unique(string $table, string $column = 'NULL'): Unique
    {
        return Rule::unique($table, $column)->where('company_id', self::companyId());
    }

    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('company_id', self::companyId());
    }

    /** Tanpa konteks perusahaan: 0 (tidak cocok dengan data mana pun). */
    private static function companyId(): int
    {
        return app(CurrentCompany::class)->id() ?? 0;
    }
}
