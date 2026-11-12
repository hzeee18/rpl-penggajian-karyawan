<?php

namespace App\Http\Controllers;

use App\Models\Kontrak;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ProfilController extends Controller
{
    public const PTKP = [
        'TK/0' => 'tidak kawin, tanpa tanggungan', 'TK/1' => 'tidak kawin, 1 tanggungan', 'TK/2' => 'tidak kawin, 2 tanggungan',
        'TK/3' => 'tidak kawin, 3 tanggungan', 'K/0' => 'kawin, tanpa tanggungan', 'K/1' => 'kawin, 1 tanggungan',
        'K/2' => 'kawin, 2 tanggungan', 'K/3' => 'kawin, 3 tanggungan',
    ];

    public function index(Request $request)
    {
        $karyawan = $request->user()->karyawan?->load(['departemen.manager', 'pajak', 'kontrakAktif.komponen.component', 'kontrak.approvals.approver.karyawan']);
        $payrollBaru = \App\Domain\Payroll\ModulPayroll::aktif();
        if ($payrollBaru) {
            $karyawan?->load(['position', 'kontrakPayroll.components.component']); // kontrak modul baru (AUDIT.md §29)
        }

        return view('profil.index', compact('karyawan', 'payrollBaru'));
    }

    /** Unduh kontrak kerja (PDF). Karyawan hanya kontraknya sendiri; HR semua. */
    public function kontrakPdf(Request $request, Kontrak $kontrak)
    {
        $user = $request->user();
        abort_unless($user->actsAsHr() || $kontrak->karyawan_id === $user->karyawan_id, 403);
        $kontrak->load('karyawan.departemen', 'komponen.component', 'approvals.approver.karyawan');

        return Pdf::loadView('profil.kontrak-pdf', compact('kontrak'))->setPaper('a4')
            ->download('kontrak-' . str_replace('/', '-', $kontrak->kode) . '.pdf');
    }
}
