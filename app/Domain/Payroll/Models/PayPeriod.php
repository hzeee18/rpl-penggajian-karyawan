<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use App\Casts\Tanggal;
use Illuminate\Database\Eloquent\Model;

/**
 * Periode payroll per bulan (AUDIT.md §53). Periode berurutan selalu menyambung: mulai = selesai periode
 * sebelumnya + 1 hari. Periode yang sudah dipakai payroll (draft sampai disetujui) tidak bisa diubah.
 */
final class PayPeriod extends Model
{
    use BelongsToCompany;

    /** Label status yang tampil di semua halaman (AUDIT.md §60): kunci dari status(), nilai [teks, warna badge]. */
    public const STATUS = [
        'terbuka' => ['Belum diproses', 'neutral'], 'draft' => ['Draft payroll', 'info'],
        'menunggu' => ['Menunggu persetujuan', 'warning'], 'final' => ['Final', 'success'],
    ];

    protected $table = 'pay_periods';

    protected $fillable = ['period_month', 'start_date', 'end_date', 'created_by'];

    protected function casts(): array
    {
        return ['start_date' => Tanggal::class, 'end_date' => Tanggal::class];
    }

    /** Payroll yang memakai rentang periode ini (selain yang ditolak). */
    public function payroll()
    {
        return PayrollRun::where('period_start', $this->start_date->toDateString())->where('period_end', $this->end_date->toDateString())
            ->where('status', '!=', PayrollRun::REJECTED);
    }

    public function status(): string
    {
        $status = $this->payroll()->pluck('status');

        return match (true) {
            $status->contains(PayrollRun::APPROVED) => 'final',
            $status->contains(PayrollRun::PENDING) => 'menunggu',
            $status->isNotEmpty() => 'draft',
            default => 'terbuka',
        };
    }
}
