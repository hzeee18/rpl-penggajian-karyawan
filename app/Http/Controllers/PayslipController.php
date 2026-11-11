<?php

namespace App\Http\Controllers;

use App\Models\Notifikasi;
use App\Models\Payslip;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PayslipController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $slip = $user->batasi(Payslip::with(['payroll', 'karyawan.departemen']))
            // Manager & karyawan hanya melihat slip miliknya sendiri
            ->when(! ($user->isRole('Admin') || $user->actsAsHr()), fn ($q) => $q->where('karyawan_id', $user->karyawan_id))
            ->whereHas('payroll', fn ($q) => $q->where('status', 'final'))
            ->when($request->periode, fn ($q, $p) => $q->whereHas('payroll', fn ($q) => $q->where('periode', $p)))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($q) => $q->whereHas('karyawan', fn ($q) => $q->where('nama', 'like', "%$s%")->orWhere('nik', 'like', "%$s%"))
                ->orWhereHas('payroll', fn ($q) => $q->where('periode', 'like', "%$s%"))))
            ->join('payroll', 'payroll.id', '=', 'payslip.payroll_id')
            ->orderByDesc('payroll.periode')->orderBy('payslip.id')->select('payslip.*') // id: urutan periode kembar tetap
            ->paginate(10)->withQueryString();

        return view('slip.index', compact('slip'));
    }

    public function show(Request $request, Payslip $payslip)
    {
        $this->cekAkses($request, $payslip);
        Notifikasi::where('user_id', $request->user()->id)->where('jenis_ref', 'payslip')->where('ref_id', $payslip->id)->update(['is_read' => true]);
        $payslip->load('karyawan.departemen', 'payroll');

        return view('slip.show', ['slip' => $payslip, 'details' => $payslip->details()]);
    }

    public function download(Request $request, Payslip $payslip, PayrollService $svc)
    {
        $this->cekAkses($request, $payslip);
        if (! $payslip->file_pdf || ! Storage::disk('local')->exists($payslip->file_pdf)) {
            $svc->generatePdf($payslip);
        }

        return Storage::disk('local')->download($payslip->file_pdf, 'slip-gaji-' . $payslip->payroll->periode . '-' . $payslip->karyawan->nik . '.pdf');
    }

    private function cekAkses(Request $request, Payslip $payslip): void
    {
        $user = $request->user();
        abort_if($payslip->payroll->status !== 'final', 404);
        abort_unless($user->isRole('Admin') || $user->actsAsHr() || $payslip->karyawan_id === $user->karyawan_id, 403, 'Anda hanya bisa melihat slip gaji milik sendiri.');
    }
}
