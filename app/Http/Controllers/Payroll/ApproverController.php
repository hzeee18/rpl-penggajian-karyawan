<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payroll;

use App\Domain\Company\Models\Company;
use App\Domain\Payroll\Actions\AlurPayroll;
use App\Domain\Payroll\Actions\AturApprover;
use App\Domain\Payroll\Models\PayrollApprover;
use App\Domain\Payroll\Run\PenyetujuPayroll;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Approver Payroll: setelan butuh approval, password slip, dan pilihan penyetuju (user dan/atau role, AUDIT.md §77). */
final class ApproverController extends Controller
{
    public function index()
    {
        return view('payroll.approver', [
            'butuhApproval' => AlurPayroll::butuhApproval((int) auth()->user()->company_id),
            'slipPakaiPassword' => (bool) (Company::whereKey((int) auth()->user()->company_id)->value('slip_pakai_password') ?? true),
            'penyetuju' => PenyetujuPayroll::daftar(),
            'roleKandidat' => PenyetujuPayroll::roleKandidat(),
            'userKandidat' => PenyetujuPayroll::userKandidat(),
            'pilihan' => PayrollApprover::get(['user_id', 'role_id']),
        ]);
    }

    /**
     * Setelan approval dalam satu form (AUDIT.md §77): pakai approval atau tidak, dan siapa penyetujunya (role dan/atau user).
     * Bila "Ya", wajib minimal satu role atau user. Default perusahaan: tidak memakai approval.
     */
    public function penyetuju(Request $request, AturApprover $atur)
    {
        $data = $request->validate([
            'payroll_butuh_approval' => 'required|boolean',
            'role_id' => 'array', 'role_id.*' => ['integer', Rule::in(PenyetujuPayroll::roleKandidat()->pluck('id')->all())],
            'user_id' => 'array', 'user_id.*' => ['integer', Rule::in(PenyetujuPayroll::userKandidat()->pluck('id')->all())],
        ], ['role_id.*.in' => 'Role yang dipilih tidak ditemukan.', 'user_id.*.in' => 'User yang dipilih tidak aktif atau tidak ditemukan.']);
        $pesan = $atur->simpanApproval(Company::findOrFail((int) $request->user()->company_id), (bool) $data['payroll_butuh_approval'], $data['role_id'] ?? [], $data['user_id'] ?? []);

        return back()->with('sukses', $pesan);
    }

    /** Setelan perusahaan (AUDIT.md §47, §50): payroll butuh approval (memakai penyetuju yang sudah dipilih), dan PDF slip pakai password. */
    public function pengaturan(Request $request, AturApprover $atur)
    {
        $data = $request->validate([
            'payroll_butuh_approval' => 'required_without:slip_pakai_password|boolean',
            'slip_pakai_password' => 'required_without:payroll_butuh_approval|boolean',
        ]);
        $company = Company::findOrFail((int) $request->user()->company_id);
        if (array_key_exists('slip_pakai_password', $data)) {
            return back()->with('sukses', $atur->simpanSlip($company, (bool) $data['slip_pakai_password']));
        }
        $pilihan = PayrollApprover::get(['user_id', 'role_id']);

        return back()->with('sukses', $atur->simpanApproval($company, (bool) $data['payroll_butuh_approval'],
            $pilihan->pluck('role_id')->filter()->all(), $pilihan->pluck('user_id')->filter()->all()));
    }
}
