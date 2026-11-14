<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payroll;

use App\Domain\Payroll\Actions\AlurPayroll;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Run\PenyetujuPayroll;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Approval payroll: penyetuju yang diatur di menu Approver Payroll bisa setujui/tolak; yang pertama memutuskan dicatat (AUDIT.md §77). */
final class ApprovalPayrollController extends Controller
{
    public function __construct(private readonly AlurPayroll $alur) {}

    public function index(Request $request)
    {
        $run = PayrollRun::with(['creator.karyawan', 'approver'])
            ->whereIn('status', [PayrollRun::PENDING, PayrollRun::APPROVED, PayrollRun::REJECTED])
            ->orderByRaw("case when status = 'pending_approval' then 0 else 1 end")->orderByDesc('id')->paginate(15);

        return view('payroll.approval.index', ['run' => $run, 'bolehPutus' => PenyetujuPayroll::boleh($request->user())]);
    }

    public function show(Request $request, int $id)
    {
        $run = PayrollRun::whereIn('status', [PayrollRun::PENDING, PayrollRun::APPROVED, PayrollRun::REJECTED])->findOrFail($id);

        return view('payroll.approval.show', [
            'run' => $run,
            'penyesuaian' => \App\Domain\Payroll\Models\RunAdjustment::with(['component', 'employee', 'creator'])->where('run_id', $run->id)->orderBy('id')->get(),
            'bolehPutus' => PenyetujuPayroll::boleh($request->user()), // hanya penyetuju terpilih (Approver Payroll) yang bisa setujui/tolak
            'karyawan' => $run->employees()->with('items')->orderBy('nik')->paginate(50),
            'total' => $run->employees()->selectRaw('count(*) n, coalesce(sum(take_home_pay),0) thp')->first(),
        ]);
    }

    public function setujui(Request $request, int $id)
    {
        $this->alur->setujui(PayrollRun::findOrFail($id), $request->user());

        return redirect()->route('pay.approval.index')->with('sukses', 'Payroll disetujui. Slip gaji sedang diterbitkan.');
    }

    public function tolak(Request $request, int $id)
    {
        $data = $request->validate(['alasan' => 'required|max:500'], ['alasan.required' => 'Alasan penolakan wajib diisi.']);
        $this->alur->tolak(PayrollRun::findOrFail($id), $request->user(), $data['alasan']);

        return redirect()->route('pay.approval.index')->with('sukses', 'Payroll ditolak. HR perlu membuat payroll baru untuk periode ini.');
    }
}
