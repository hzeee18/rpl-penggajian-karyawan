<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Payroll\Actions\AlurPayroll;
use App\Domain\Payroll\Models\PayrollRun;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Approval payroll oleh Manager: hanya run yang approver-nya user ini. */
final class ApprovalPayrollController extends Controller
{
    public function __construct(private readonly AlurPayroll $alur) {}

    public function index(Request $request)
    {
        $run = PayrollRun::with('creator.karyawan')->where('approver_user_id', $request->user()->id)
            ->whereIn('status', [PayrollRun::PENDING, PayrollRun::APPROVED, PayrollRun::REJECTED])
            ->orderByRaw("case when status = 'pending_approval' then 0 else 1 end")->orderByDesc('id')->paginate(15);

        return view('payroll-baru.approval.index', compact('run'));
    }

    public function show(Request $request, int $id)
    {
        $run = PayrollRun::where('approver_user_id', $request->user()->id)->findOrFail($id);

        return view('payroll-baru.approval.show', [
            'run' => $run,
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

        return redirect()->route('pay.approval.index')->with('sukses', 'Payroll ditolak. HR perlu membuat payroll baru.');
    }
}
