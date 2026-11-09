<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Services\ApprovalService;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use RuntimeException;

class ApprovalController extends Controller
{
    public function __construct(private ApprovalService $svc)
    {
    }

    public function index(Request $request)
    {
        $status = $request->input('status', 'menunggu');
        $approval = Approval::with(['approver.karyawan', 'pengaju.karyawan', 'pengaju.role'])
            ->where('approver_id', $request->user()->id)
            ->when($status !== 'semua', fn ($q) => $q->where('status', $status))
            ->when($request->q, fn ($q, $s) => $q->where('jenis_ref', 'like', "%$s%"))
            ->latest('id')->paginate(10)->withQueryString();

        // panel kanan: pengajuan yang dipilih (default yang pertama)
        $pilih = $approval->firstWhere('id', $request->integer('lihat')) ?? $approval->first();
        $ref = $pilih?->ref();
        $ringkasan = null;
        if ($pilih?->jenis_ref === 'payroll' && $ref) {
            $ringkasan = app(PayrollService::class)->ringkasan($ref);
        } elseif ($ref) {
            $ref->load(['karyawan.departemen', 'komponen.component']);
        }

        return view('approval.index', compact('approval', 'status', 'pilih', 'ref', 'ringkasan'));
    }

    public function show(Request $request, Approval $approval)
    {
        abort_unless($approval->approver_id === $request->user()->id, 403, 'Pengajuan ini bukan untuk Anda.');
        $ref = $approval->ref();
        abort_unless($ref, 404);

        $data = compact('approval', 'ref');
        if ($approval->jenis_ref === 'kontrak') {
            $ref->load(['karyawan.departemen', 'komponen.component']);
        } else {
            $data['ringkasan'] = app(PayrollService::class)->ringkasan($ref);
        }

        return view('approval.show', $data);
    }

    public function setujui(Request $request, Approval $approval)
    {
        $request->validate(['catatan' => 'nullable|max:500']);
        try {
            $this->svc->setujui($approval, $request->user(), $request->catatan);
        } catch (RuntimeException $e) {
            return back()->with('gagal', $e->getMessage());
        }

        return redirect()->route('approval.index')->with('sukses', $approval->jenis_ref === 'payroll' ? 'Payroll disetujui. Slip gaji semua karyawan sudah terbit.' : 'Kontrak disetujui dan aktif sesuai tanggal mulainya.');
    }

    public function tolak(Request $request, Approval $approval)
    {
        $request->validate(['catatan' => 'required|max:500'], ['catatan.required' => 'Catatan wajib diisi saat menolak.']);
        try {
            $this->svc->tolak($approval, $request->user(), $request->catatan);
        } catch (RuntimeException $e) {
            return back()->with('gagal', $e->getMessage());
        }

        return redirect()->route('approval.index')->with('sukses', 'Pengajuan ditolak, HR sudah diberi notifikasi.');
    }
}
