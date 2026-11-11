<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Payroll\Actions\AlurPayroll;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Run\PemilihKaryawan;
use App\Http\Controllers\Controller;
use App\Models\Departemen;
use Illuminate\Http\Request;

/** Generate Payroll: buat run, preview (queue), ubah sel, generate (queue), hapus draft. */
final class RunController extends Controller
{
    public function __construct(private readonly AlurPayroll $alur) {}

    public function index()
    {
        return view('payroll-baru.run.index', ['run' => PayrollRun::with('creator')->orderByDesc('id')->paginate(15)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['title' => 'required|max:191', 'periode' => 'required|date_format:Y-m']);
        $run = $this->alur->buat($data['title'], $data['periode']);

        return redirect()->route('pay.run.show', $run->id)->with('sukses', 'Payroll dibuat. Pilih master dan karyawan lalu lihat preview.');
    }

    public function show(Request $request, int $id, PemilihKaryawan $pemilih)
    {
        $run = PayrollRun::with(['masterVersion.master', 'creator', 'approver'])->findOrFail($id);
        $karyawan = $run->employees()->with('items')->orderBy('nik')->paginate(50)->withQueryString();
        $kolom = $run->employees()->join('pay_run_items', 'pay_run_items.run_employee_id', '=', 'pay_run_employees.id')
            ->select('pay_run_items.code', 'pay_run_items.name', 'pay_run_items.type', 'pay_run_items.sort_order')->distinct()
            ->orderByRaw("case when pay_run_items.type = 'earning' then 0 else 1 end")->orderBy('pay_run_items.sort_order')->get()->unique('code')->values();

        return view('payroll-baru.run.show', [
            'run' => $run, 'karyawan' => $karyawan, 'kolom' => $kolom,
            'master' => Master::where('is_active', true)->whereNotNull('current_version_id')->orderBy('name')->get(),
            'departemen' => Departemen::orderBy('nama')->get(), 'semuaKaryawan' => Employee::where('is_active', true)->orderBy('nama')->get(['id', 'nik', 'nama']),
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

    public function ubahSel(Request $request, int $id, int $karyawan)
    {
        $data = $request->validate(['code' => 'required|string|max:64', 'nilai' => 'nullable|string|max:20', 'kembalikan' => 'nullable|boolean']);
        $run = PayrollRun::findOrFail($id);
        $re = $run->employees()->findOrFail($karyawan);
        $nilai = $request->boolean('kembalikan') ? null : str_replace(['.', ','], ['', '.'], (string) ($data['nilai'] ?? ''));
        $this->alur->ubahSel($run, $re, $data['code'], $nilai === '' ? null : $nilai);

        return back()->with('sukses', $nilai === null ? 'Sel dikembalikan ke hasil rumus.' : 'Nilai diubah.');
    }

    public function generate(int $id)
    {
        $this->alur->generate(PayrollRun::findOrFail($id));

        return redirect()->route('pay.run.show', $id)->with('sukses', 'Generate sedang diproses. Setelah selesai payroll menunggu persetujuan Manager.');
    }

    public function destroy(int $id)
    {
        $this->alur->hapus(PayrollRun::findOrFail($id));

        return redirect()->route('pay.run.index')->with('sukses', 'Payroll draft dihapus.');
    }
}
