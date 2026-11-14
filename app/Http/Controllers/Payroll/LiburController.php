<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payroll;

use App\Domain\Company\Tenancy\TenantRule;
use App\Domain\Payroll\Actions\KunciLibur;
use App\Domain\Payroll\Models\Holiday;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\WorkPattern;
use App\Models\Departemen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class LiburController extends MasterSederhanaController
{
    protected function definisi(): array
    {
        return [
            'model' => Holiday::class, 'route' => 'pay.libur', 'judul' => 'Hari libur', 'halaman' => 'Kalender Payroll', 'urut' => 'holiday_date', 'hapus' => 'fisik',
            'sub' => 'Tanggal libur tidak dihitung sebagai hari kerja saat menghitung gaji.',
            'param' => array_filter(['tab' => 'libur', 'bulan' => preg_match('/^\d{4}-\d{2}/', (string) request('holiday_date')) ? substr((string) request('holiday_date'), 0, 7) : request('bulan')]), 'atas' => 'payroll.kalender._libur',
            'kolom' => ['holiday_date' => 'Tanggal', 'type' => 'Jenis', 'departemen_id' => 'Berlaku untuk', 'name' => 'Keterangan'],
            'field' => [
                ['name' => 'holiday_date', 'label' => 'Tanggal', 'type' => 'date'],
                ['name' => 'type', 'label' => 'Jenis', 'type' => 'select', 'opsi' => Holiday::JENIS],
                ['name' => 'departemen_id', 'label' => 'Berlaku untuk', 'type' => 'select', 'opsional' => true,
                    'opsi' => ['' => 'Semua departemen'] + Departemen::orderBy('nama')->pluck('nama', 'id')->all(),
                    'bantuan' => 'Libur nasional berlaku untuk semua. Pilih departemen untuk libur khusus, mis. libur operasional.'],
                ['name' => 'name', 'label' => 'Keterangan', 'type' => 'text'],
            ],
        ];
    }

    protected function aturan(?Model $data): array
    {
        return [
            'departemen_id' => ['nullable', 'integer', TenantRule::exists('departemen')],
            'holiday_date' => ['required', 'date',
                TenantRule::unique('pay_holidays', 'holiday_date')->ignore($data)
                    ->where(fn ($q) => request('departemen_id') ? $q->where('departemen_id', request('departemen_id')) : $q->whereNull('departemen_id')),
                function (string $atribut, mixed $nilai, \Closure $gagal) use ($data) {
                    if (request('departemen_id') && Holiday::whereDate('holiday_date', $nilai)->whereNull('departemen_id')->when($data, fn ($q) => $q->whereKeyNot($data->getKey()))->exists()) {
                        $gagal('Tanggal ini sudah libur untuk semua departemen.');
                    }
                }],
            'type' => 'sometimes|required|in:'.implode(',', array_keys(Holiday::JENIS)),
            'name' => 'required|max:191',
        ];
    }

    /**
     * Hari libur di periode yang payroll-nya sudah dikirim/final tidak boleh ditambah, diubah, atau dihapus (AUDIT.md §63):
     * angka payroll itu dihitung dengan kalender saat itu. Dicek tanggal lama dan tanggal baru.
     */
    protected function periksaKunci(?Model $lama, ?array $baru): void
    {
        foreach (array_filter([$lama?->holiday_date?->toDateString(), $baru['holiday_date'] ?? null]) as $tgl) {
            if (($alasan = KunciLibur::alasan((string) $tgl)) !== null) {
                throw \Illuminate\Validation\ValidationException::withMessages(['holiday_date' => $alasan]);
            }
        }
    }

    public function alasanKunci(Model $row): ?string
    {
        return KunciLibur::alasan($row->holiday_date->toDateString());
    }

    /** Bulan yang tampil di kalender (?bulan=Y-m; bila tidak ada, bulan dari ?holiday_date atau bulan ini). */
    private function bulanTampil(Request $request): \Carbon\CarbonImmutable
    {
        $bulan = (string) $request->query('bulan');
        if (! preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            $bulan = preg_match('/^\d{4}-\d{2}/', (string) $request->query('holiday_date')) ? substr((string) $request->query('holiday_date'), 0, 7) : now()->format('Y-m');
        }

        return \Carbon\CarbonImmutable::createFromFormat('!Y-m', $bulan)->startOfMonth();
    }

    /** Daftar di bawah kalender hanya berisi hari libur bulan yang sedang ditampilkan (dan departemen yang dipilih). */
    protected function saringDaftar(\Illuminate\Database\Eloquent\Builder $query, Request $request): void
    {
        $awal = $this->bulanTampil($request);
        $query->whereBetween('holiday_date', [$awal->toDateString(), $awal->endOfMonth()->toDateString()]);
        if ($dept = $request->integer('departemen')) {
            $query->where(fn ($w) => $w->whereNull('departemen_id')->orWhere('departemen_id', $dept));
        }
    }

    protected function dataAtas(Request $request): array
    {
        $awalBulan = $this->bulanTampil($request);
        $akhirBulan = $awalBulan->endOfMonth();
        $dept = Departemen::find($request->integer('departemen'));

        // tanggal terkunci: masuk rentang payroll yang menunggu persetujuan atau sudah final
        $terkunci = [];
        $runs = PayrollRun::whereIn('status', [PayrollRun::PENDING, PayrollRun::APPROVED])
            ->where('period_start', '<=', $akhirBulan->toDateString())->where('period_end', '>=', $awalBulan->toDateString())->get();
        foreach ($runs as $r) {
            foreach (\Carbon\CarbonPeriod::create($r->period_start->max($awalBulan), $r->period_end->min($akhirBulan)) as $d) {
                $terkunci[$d->toDateString()] = $r->status === PayrollRun::APPROVED ? 'final' : 'menunggu persetujuan';
            }
        }

        return [
            'bulan' => $awalBulan, 'departemen' => Departemen::orderBy('nama')->get(), 'dept' => $dept, 'terkunci' => $terkunci,
            // pola hari kerja yang ditampilkan: milik departemen terpilih, atau bawaan perusahaan
            'hariKerja' => WorkPattern::hariUntuk(null, $dept?->pay_work_pattern_id),
            'libur' => Holiday::with('departemen')->whereBetween('holiday_date', [$awalBulan->toDateString(), $akhirBulan->toDateString()])
                ->when($dept, fn ($q) => $q->where(fn ($w) => $w->whereNull('departemen_id')->orWhere('departemen_id', $dept->id)))
                ->orderByRaw('departemen_id is not null')->get()->groupBy(fn ($h) => $h->holiday_date->toDateString()),
        ];
    }
}
