<?php

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Kolom DATE: dibaca sebagai Carbon, disimpan sebagai 'Y-m-d' (tanpa jam)
 * supaya perbandingan tanggal konsisten di MySQL maupun SQLite.
 */
class Tanggal implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->startOfDay();
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toDateString();
    }
}
