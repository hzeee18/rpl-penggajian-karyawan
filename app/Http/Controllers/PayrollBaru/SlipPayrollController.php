<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Slip gaji payroll baru. Karyawan hanya melihat slip miliknya (payslips.employee_id = users.karyawan_id);
 * user dengan hak lihat Generate Payroll (HR) melihat slip seluruh karyawan perusahaannya.
 */
final class SlipPayrollController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $slip = Payslip::with('runEmployee.run')
            ->when(! $this->lihatSemua($user), fn ($q) => $q->where('employee_id', $user->karyawan_id ?? 0))
            ->when($request->q, fn ($q, $s) => $q->whereHas('runEmployee', fn ($r) => $r->where('full_name', 'like', "%{$s}%")->orWhere('employee_no', 'like', "%{$s}%")))
            ->whereHas('runEmployee.run', fn ($q) => $q->where('status', PayrollRun::APPROVED))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('payroll-baru.slip.index', ['slip' => $slip, 'semua' => $this->lihatSemua($user)]);
    }

    public function show(Request $request, int $id)
    {
        $slip = $this->slip($request, $id);
        $slip->load('runEmployee.items', 'runEmployee.run');

        return view('payroll-baru.slip.show', ['slip' => $slip, 're' => $slip->runEmployee]);
    }

    public function unduh(Request $request, int $id)
    {
        $slip = $this->slip($request, $id);
        abort_if($slip->file_path === null || ! Storage::disk('local')->exists($slip->file_path), 404, $slip->note ?? 'PDF belum tersedia.');

        return Storage::disk('local')->download($slip->file_path, 'slip-gaji-'.$slip->runEmployee->employee_no.'-'.$slip->runEmployee->period_end->format('Y-m').'.pdf');
    }

    private function slip(Request $request, int $id): Payslip
    {
        $slip = Payslip::with('runEmployee')->findOrFail($id);
        $user = $request->user();
        abort_unless($this->lihatSemua($user) || ((int) $slip->employee_id === (int) $user->karyawan_id && $user->karyawan_id !== null), 403, 'Anda hanya bisa melihat slip gaji milik sendiri.');

        return $slip;
    }

    /** HR (hak lihat Generate Payroll) melihat semua slip; Manager (termasuk Manager HR) hanya slip miliknya. */
    private function lihatSemua(User $user): bool
    {
        return ! $user->isRole(\App\Models\Role::MANAGER) && $user->bisa('pay-run');
    }
}
