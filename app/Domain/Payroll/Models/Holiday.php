<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Casts\Tanggal;
use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Kalender libur: departemen_id NULL = semua departemen, terisi = libur khusus departemen itu (AUDIT.md §55). */
final class Holiday extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_holidays';

    /** Jenis hari libur (AUDIT.md §53); semuanya sama-sama mengurangi hari kerja. */
    public const JENIS = ['nasional' => 'Libur nasional', 'cuti_bersama' => 'Cuti bersama', 'perusahaan' => 'Libur perusahaan'];

    protected $fillable = ['holiday_date', 'departemen_id', 'type', 'name'];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Departemen, $this> */
    public function departemen(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Departemen::class);
    }

    protected function casts(): array
    {
        return ['holiday_date' => Tanggal::class];
    }
}
