<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payroll;

use App\Domain\Payroll\Actions\AlurPayroll;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\RunAdjustment;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Run\PemilihKaryawan;
use App\Http\Controllers\Controller;
use App\Models\Departemen;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Generate Payroll: buat run, preview (queue), penyesuaian, generate (queue), hapus draft. */
final class RunController extends Controller
{
    public function __construct(private readonly AlurPayroll $alur) {}

    public function index()
    {
        return view('payroll.run.index', [
            'run' => PayrollRun::with('creator')->orderByDesc('id')->paginate(15),
            'penyetuju' => \App\Domain\Payroll\Run\PenyetujuPayroll::daftar(), // tanpa penyetuju, generate ditolak
            'butuhApproval' => AlurPayroll::butuhApproval((int) auth()->user()->company_id),
            // periode dari Kalender Payroll; yang belum dipakai payroll paling awal = "periode berikutnya" (AUDIT.md §54)
            'periode' => $periode = \App\Domain\Payroll\Models\PayPeriod::orderBy('period_month')->get()->each(fn ($p) => $p->setAttribute('status_periode', $p->status())),
            'berikut' => $periode->firstWhere('status_periode', 'terbuka'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['title' => 'required|max:191', 'periode' => 'required|date_format:Y-m']);
        $run = $this->alur->buat($data['title'], $data['periode']);

        return redirect()->route('pay.run.show', $run->id)->with('sukses', 'Payroll dibuat. Pilih master dan karyawan, lalu klik Lihat preview.');
    }

    public function show(Request $request, int $id, PemilihKaryawan $pemilih)
    {
        $run = PayrollRun::with(['masterVersion.master', 'creator', 'approver'])->findOrFail($id);
        // filter tampilan (AUDIT.md §66): departemen (nama snapshot) dan karyawan; payroll selalu dibuat untuk semua karyawan
        $fDepartemen = array_values(array_filter((array) $request->input('departemen', [])));
        $fKaryawan = array_values(array_filter(array_map('intval', (array) $request->input('karyawan_id', []))));
        $karyawan = $run->employees()->with('items')
            ->when($fDepartemen, fn ($q) => $q->whereIn('department_name', $fDepartemen))
            ->when($fKaryawan, fn ($q) => $q->whereIn('employee_id', $fKaryawan))
            ->orderBy('nik')->paginate(50)->withQueryString();
        $perDepartemen = $run->employees()->selectRaw('department_name, count(*) n, coalesce(sum(total_earnings),0) pendapatan, coalesce(sum(total_deductions),0) potongan, coalesce(sum(take_home_pay),0) thp')->groupBy('department_name')->orderBy('department_name')->get();
        $infoDepartemen = Departemen::with('manager')->whereIn('nama', $perDepartemen->pluck('department_name')->filter())->get()->keyBy('nama');
        $totalKomponen = DB::table('pay_run_items')->join('pay_run_employees', 'pay_run_employees.id', '=', 'pay_run_items.run_employee_id')
            ->where('pay_run_employees.run_id', $run->id)->where('pay_run_employees.company_id', $run->company_id)
            ->selectRaw('pay_run_items.code, max(pay_run_items.name) name, max(pay_run_items.type) type, min(pay_run_items.sort_order) urut, coalesce(sum(pay_run_items.final_amount),0) jumlah')
            ->groupBy('pay_run_items.code')->orderByRaw("max(case when pay_run_items.type = 'earning' then 0 else 1 end)")->orderBy('urut')->get();
        $kolom = $run->employees()->join('pay_run_items', 'pay_run_items.run_employee_id', '=', 'pay_run_employees.id')
            ->select('pay_run_items.code', 'pay_run_items.name', 'pay_run_items.type', 'pay_run_items.sort_order')->distinct()
            ->orderByRaw("case when pay_run_items.type = 'earning' then 0 else 1 end")->orderBy('pay_run_items.sort_order')->get()->unique('code')->values();
        return view('payroll.run.show', [
            'run' => $run, 'karyawan' => $karyawan, 'kolom' => $kolom,
            'perDepartemen' => $perDepartemen, 'infoDepartemen' => $infoDepartemen, 'totalKomponen' => $totalKomponen,
            'fDepartemen' => $fDepartemen, 'fKaryawan' => $fKaryawan, 'kesiapan' => $this->kesiapan($run),
            'master' => Master::where('is_active', true)->whereNotNull('current_version_id')->orderBy('name')->get(),
            'penyesuaian' => RunAdjustment::with(['component', 'employee', 'creator'])->where('run_id', $run->id)->orderBy('id')->get(),
            'karyawanRun' => $run->employees()->orderBy('full_name')->get(['employee_id', 'employee_no', 'full_name']),
            'komponen' => Component::where('is_active', true)->orderBy('type')->orderBy('name')->get(),
            'kedaluwarsa' => $run->isDraft() ? $pemilih->kontrakKedaluwarsa($run) : collect(),
            'total' => $run->employees()->selectRaw('count(*) n, coalesce(sum(total_earnings),0) penambah, coalesce(sum(total_deductions),0) pengurang, coalesce(sum(take_home_pay),0) thp')->first(),
        ]);
    }

    public function pratinjau(Request $request, int $id)
    {
        $data = $request->validate([
            'master_id' => 'required|integer',
            'scope' => 'required|in:all,department,individual',
            'pilihan' => 'nullable|array', 'pilihan.*' => 'integer',
        ]);
        $this->alur->mulaiPratinjau(PayrollRun::findOrFail($id), (int) $data['master_id'], $data['scope'], array_map('intval', $data['pilihan'] ?? []));

        return redirect()->route('pay.run.show', $id)->with('sukses', 'Preview sedang diproses.');
    }

    public function tambahPenyesuaian(Request $request, int $id)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer', 'component_id' => 'required|integer',
            'amount' => 'required|string|max:20', 'note' => 'required|string|max:255',
        ], ['note.required' => 'Keterangan wajib diisi (mis. "Bonus kinerja Q3").']);
        $data['amount'] = str_replace(['.', ','], ['', '.'], $data['amount']);
        $this->alur->tambahPenyesuaian(PayrollRun::findOrFail($id), array_merge($data, ['employee_id' => (int) $data['employee_id'], 'component_id' => (int) $data['component_id']]));

        return back()->with('sukses', 'Penyesuaian ditambahkan dan gaji karyawan dihitung ulang.');
    }

    public function hapusPenyesuaian(int $id, int $penyesuaian)
    {
        $run = PayrollRun::findOrFail($id);
        $this->alur->hapusPenyesuaian($run, RunAdjustment::where('run_id', $run->id)->findOrFail($penyesuaian));

        return back()->with('sukses', 'Penyesuaian dihapus.');
    }

    public function tarikKembali(Request $request, int $id)
    {
        $data = $request->validate(['alasan' => 'nullable|string|max:500']);
        $this->alur->tarikKembali(PayrollRun::findOrFail($id), $data['alasan'] ?? null);

        return redirect()->route('pay.run.show', $id)->with('sukses', 'Payroll ditarik kembali ke draft. Perbaiki lalu generate ulang.');
    }

    public function generate(int $id)
    {
        $this->alur->generate(PayrollRun::findOrFail($id));

        return redirect()->route('pay.run.show', $id)->with('sukses', 'Generate sedang diproses. Setelah selesai, payroll menunggu persetujuan penyetuju.');
    }

    public function batalkan(int $id)
    {
        $this->alur->batalkanProses(PayrollRun::findOrFail($id));

        return redirect()->route('pay.run.show', $id)->with('sukses', 'Proses dibatalkan. Payroll kembali ke draft; ulangi preview atau generate.');
    }

    public function destroy(int $id)
    {
        $this->alur->hapus(PayrollRun::findOrFail($id));

        return redirect()->route('pay.run.index')->with('sukses', 'Payroll draft dihapus.');
    }

    /**
     * Checklist kesiapan sebelum generate (AUDIT.md §66). wajib = generate ditolak server bila belum terpenuhi;
     * selain itu hanya peringatan. Tiap item: [judul, ok, keterangan, tautan, wajib].
     *
     * @return list<array{0: string, 1: bool, 2: string, 3: ?string, 4: bool}>
     */
    private function kesiapan(PayrollRun $run): array
    {
        if (! $run->isDraft()) {
            return [];
        }
        $u = auth()->user();
        $mulai = $run->period_start->toDateString();
        $selesai = $run->period_end->toDateString();
        $aktif = Employee::where('is_active', true)->where(fn ($q) => $q->whereNull('resign_date')->orWhere('resign_date', '>=', $mulai));
        $ids = $aktif->pluck('id');
        $nAktif = $ids->count();
        $tanpaKontrak = $ids->diff(\App\Domain\Payroll\Models\Contract::whereIn('employee_id', $ids)->where('is_active', true)->where('start_date', '<=', $selesai)
            ->where(fn ($w) => $w->whereNull('end_date')->orWhere('end_date', '>=', $mulai))->pluck('employee_id'))->count();
        $tanpaAbsensi = $ids->diff(\App\Domain\Payroll\Models\Attendance::whereIn('employee_id', $ids)->whereBetween('attendance_date', [$mulai, $selesai])->distinct()->pluck('employee_id'))->count();
        $master = Master::where('is_active', true)->whereNotNull('current_version_id')->exists();
        $butuh = AlurPayroll::butuhApproval((int) $run->company_id);
        $penyetuju = ! $butuh || \App\Domain\Payroll\Run\PenyetujuPayroll::daftar()->isNotEmpty();
        $preview = $run->employees()->exists();
        $minus = $run->employees()->where('take_home_pay', '<', 0)->count();
        $libur = \App\Domain\Payroll\Models\Holiday::whereBetween('holiday_date', [$mulai, $selesai])->count();

        return [
            ['Master Payroll Terbit', $master, $master ? 'Siap dipakai' : 'Terbitkan minimal satu master payroll', $u->bisa('pay-master') ? route('pay.master.index') : null, true],
            // ['Semua Karyawan dalam masa kontrak', $tanpaKontrak === 0, $tanpaKontrak === 0 ? 'Semua karyawan aktif punya kontrak yang berlaku' : $tanpaKontrak.' karyawan belum punya kontrak di periode ini (akan dilewati)', $u->bisa('pay-kontrak') ? route('pay.kontrak.index') : null, false],
            ['Absensi Periode Ini', $tanpaAbsensi === 0, $tanpaAbsensi === 0 ? 'Semua karyawan sudah punya data absensi' : $tanpaAbsensi.' dari '.$nAktif.' karyawan belum punya absensi', $u->bisa('pay-absensi') ? route('pay.absensi.index', ['periode' => $run->period_end->format('Y-m')]) : null, false],
            ['Hari libur', true, $libur.' hari libur di periode ini', $u->bisa('pay-libur') ? route('pay.libur.index', ['tab' => 'libur', 'bulan' => $run->period_end->format('Y-m')]) : null, false],
            ['Approval Payroll', $penyetuju, ! $butuh ? 'Perusahaan tidak memakai persetujuan' : ($penyetuju ? 'Penyetuju sudah diatur' : 'Belum ada penyetuju; atur di menu Approver Payroll'), $u->bisa('pay-approver') ? route('pay.approver.index') : null, true], 
            ['Preview', $preview && $minus === 0, ! $preview ? 'Klik Lihat preview dulu' : ($minus ? $minus.' karyawan gaji bersihnya minus' : 'Preview siap'), null, true],
        ];
    }
}
