<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Payroll\Models\PayrollRun;

/**
 * Hari libur di periode yang payroll-nya sudah dikirim/final tidak boleh ditambah, diubah, atau dihapus (AUDIT.md §63):
 * angka payroll itu dihitung dengan kalender saat itu.
 */
final class KunciLibur
{
    /** Alasan tanggal terkunci (masuk rentang payroll yang menunggu persetujuan atau sudah final), atau null. */
    public static function alasan(string $tanggal): ?string
    {
        $run = PayrollRun::whereIn('status', [PayrollRun::PENDING, PayrollRun::APPROVED])
            ->where('period_start', '<=', $tanggal)->where('period_end', '>=', $tanggal)->orderByDesc('status')->first();

        return $run === null ? null : 'Tanggal ini masuk payroll "'.$run->title.'" yang '.($run->status === PayrollRun::APPROVED ? 'sudah final' : 'menunggu persetujuan').', jadi hari liburnya tidak bisa diubah.';
    }
}
