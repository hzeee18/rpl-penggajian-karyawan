<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payroll;

use App\Domain\Audit\AuditLogger;
use App\Domain\Company\Tenancy\TenantRule;
use App\Domain\Payroll\Actions\KelolaPeriode;
use App\Domain\Payroll\Models\Holiday;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\PeriodConfig;
use App\Domain\Payroll\Models\WorkPattern;
use App\Models\Departemen;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

/** Kalender Payroll (AUDIT.md §53, §55): tab Periode dan Pola Hari Kerja (di sini), tab Hari Libur (LiburController). */
final class KalenderController extends Controller
{
    public function __construct(private readonly KelolaPeriode $kelola) {}

    public function index(Request $request)
    {
        if ($request->query('tab') === 'libur' || $request->hasAny(['ubah', 'baru'])) {
            return app(LiburController::class)->index($request);
        }
        if ($request->query('tab') === 'pola') {
            return $this->pola($request);
        }

        $periode = PayPeriod::orderByDesc('period_month')->get();
        $pola = WorkPattern::hariUntuk(null, null); // ringkasan memakai pola bawaan perusahaan
        $libur = Holiday::whereNull('departemen_id')->pluck('holiday_date')->map(fn ($d) => $d->toDateString())->flip();
        $ringkas = $periode->mapWithKeys(function (PayPeriod $p) use ($libur, $pola) {
            $kerja = 0;
            $nLibur = 0;
            foreach (CarbonPeriod::create($p->start_date, $p->end_date) as $d) {
                $isLibur = $libur->has($d->toDateString());
                $nLibur += $isLibur ? 1 : 0;
                $kerja += in_array($d->isoWeekday(), $pola, true) && ! $isLibur ? 1 : 0;
            }

            return [$p->id => ['hari' => (int) $p->start_date->diffInDays($p->end_date) + 1, 'kerja' => $kerja, 'libur' => $nLibur, 'status' => $p->status()]];
        });

        return view('payroll.kalender.periode', [
            'periode' => $periode, 'ringkas' => $ringkas, 'config' => $config = PeriodConfig::first(),
            // rencana periode berikutnya untuk modal konfirmasi "Buat periode berikutnya" (tanggal otomatis)
            'rencana' => $config ? rescue(fn () => [$b = $this->kelola->bulanBerikutnya(), ...$this->kelola->rentangBaru($b)], null, false) : null,
            'ubah' => $periode->firstWhere('id', $request->integer('periode_ubah')),
        ]);
    }

    public function store()
    {
        $p = $this->kelola->buatBerikutnya();

        return redirect()->route('pay.libur.index')->with('sukses', 'Periode '.nama_periode($p->period_month).' dibuat: '.tgl($p->start_date).' – '.tgl($p->end_date).'.');
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate(['end_date' => 'required|date']);
        $p = PayPeriod::findOrFail($id);
        $this->kelola->ubahSelesai($p, $data['end_date']);

        return redirect()->route('pay.libur.index')->with('sukses', 'Tanggal periode '.nama_periode($p->period_month).' disimpan.');
    }

    public function destroy(int $id)
    {
        $p = PayPeriod::findOrFail($id);
        $this->kelola->hapus($p);

        return redirect()->route('pay.libur.index')->with('sukses', 'Periode '.nama_periode($p->period_month).' dihapus.');
    }

    /** Tab Pola Hari Kerja (AUDIT.md §55): daftar pola, pola per departemen, pengecualian per karyawan. */
    private function pola(Request $request)
    {
        $pola = WorkPattern::orderByDesc('is_default')->orderBy('name')->get();

        return view('payroll.kalender.pola', [
            'pola' => $pola, 'ubah' => $request->has('pola_baru') ? new WorkPattern(['work_days' => '1,2,3,4,5']) : $pola->firstWhere('id', $request->integer('pola_ubah')),
            'departemen' => Departemen::orderBy('nama')->get(),
            'pengecualian' => Employee::with('departemen')->whereNotNull('pay_work_pattern_id')->orderBy('nama')->get(),
            'karyawan' => Employee::where('is_active', true)->orderBy('nama')->get(['id', 'nik', 'nama']),
        ]);
    }

    public function simpanPola(Request $request, AuditLogger $audit, ?int $id = null)
    {
        $pola = $id === null ? new WorkPattern : WorkPattern::findOrFail($id);
        // kode pola hanya penanda (tidak dipakai di rumus): huruf besar/spasi/strip dinormalkan, mis. "KW Shift" -> "kw_shift"
        $request->merge(['code' => (string) \Illuminate\Support\Str::of((string) $request->input('code'))->trim()->lower()->replaceMatches('/[\s\-]+/', '_')]);
        $data = $request->validate([
            'code' => ['required', 'max:64', 'regex:/^[a-z0-9_]+$/', TenantRule::unique('pay_work_patterns', 'code')->ignore($pola->id)],
            'name' => 'required|max:191',
            'hari' => 'required|array|min:1', 'hari.*' => 'integer|between:1,7',
            'is_default' => 'nullable|boolean',
        ], ['hari.required' => 'Pilih minimal satu hari kerja.', 'code.regex' => 'Kode hanya boleh huruf, angka, spasi, strip, atau garis bawah.']);
        $hari = collect($data['hari'])->map(fn ($h) => (int) $h)->unique()->sort()->values()->all();

        DB::transaction(function () use ($pola, $data, $hari, $audit, $request) {
            if ($request->boolean('is_default')) {
                WorkPattern::where('is_default', true)->whereKeyNot($pola->id ?? 0)->update(['is_default' => false]);
            }
            $baru = ! $pola->exists;
            $pola->fill(['code' => $data['code'], 'name' => $data['name'], 'work_days' => implode(',', $hari), 'is_default' => $request->boolean('is_default')])->save();
            $baru ? $audit->log('created', $pola, new: $pola->getAttributes()) : $audit->logChanges('updated', $pola);
        });

        return redirect()->route('pay.libur.index', ['tab' => 'pola'])->with('sukses', 'Pola hari kerja '.$pola->name.' disimpan.');
    }

    public function hapusPola(int $id, AuditLogger $audit)
    {
        $pola = WorkPattern::findOrFail($id);
        DB::transaction(function () use ($pola, $audit) {
            $audit->log('deleted', $pola, old: $pola->getAttributes());
            $pola->delete(); // departemen/karyawan yang memakainya kembali ke pola bawaan (FK null on delete)
        });

        return redirect()->route('pay.libur.index', ['tab' => 'pola'])->with('sukses', 'Pola dihapus. Departemen dan karyawan yang memakainya kembali ke pola bawaan.');
    }

    /** Pola untuk departemen (departemen_id + pola_id kosong = pakai bawaan) atau pengecualian karyawan (employee_id). */
    public function pasangPola(Request $request, AuditLogger $audit)
    {
        $data = $request->validate([
            'departemen_id' => ['nullable', 'integer', TenantRule::exists('departemen')],
            'employee_id' => ['nullable', 'integer', TenantRule::exists('karyawan')],
            'pola_id' => ['nullable', 'integer', TenantRule::exists('pay_work_patterns')],
        ]);
        $target = isset($data['employee_id']) ? Employee::findOrFail($data['employee_id']) : Departemen::findOrFail($data['departemen_id'] ?? 0);
        $lama = $target->pay_work_pattern_id;
        $target->forceFill(['pay_work_pattern_id' => $data['pola_id'] ?? null])->save();
        $audit->log('updated', $target, old: ['pay_work_pattern_id' => $lama], new: ['pay_work_pattern_id' => $target->pay_work_pattern_id]);

        return redirect()->route('pay.libur.index', ['tab' => 'pola'])->with('sukses', 'Pola hari kerja untuk '.$target->nama.' disimpan.');
    }
}
