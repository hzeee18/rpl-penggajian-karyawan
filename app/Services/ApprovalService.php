<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\ApprovalMapping;
use App\Models\Kontrak;
use App\Models\Notifikasi;
use App\Models\Payroll;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ApprovalService
{
    public function __construct(private PayrollService $payroll)
    {
    }

    /** Buat approval level tertentu untuk kontrak / payroll + notifikasi ke approver. */
    public function ajukan(string $jenis, int $refId, int $level = 1): Approval
    {
        $mapping = ApprovalMapping::where('jenis_ref', $jenis)->where('level', $level)->first();
        if (! $mapping) {
            throw new RuntimeException("Approval mapping untuk {$jenis} level {$level} belum diatur.");
        }

        $karyawan = $jenis === 'kontrak' ? Kontrak::find($refId)?->karyawan : null;
        $approvers = $mapping->cariApprovers($karyawan);
        if ($approvers->isEmpty()) {
            throw new RuntimeException('Approver tidak ditemukan. Pastikan manager departemen sudah diatur dan punya akun.');
        }

        [$judul, $pesan] = $jenis === 'kontrak'
            ? ['Kontrak menunggu approval', 'Kontrak ' . $karyawan->nama . ' (' . $karyawan->nik . ') perlu persetujuan Anda.']
            : ['Payroll menunggu approval', 'Payroll periode ' . Payroll::find($refId)->namaPeriode() . ' perlu persetujuan Anda.'];
        $dibuat = $approvers->map(function ($approver) use ($mapping, $jenis, $refId, $level, $judul, $pesan, $approvers) {
            $approval = Approval::create([
                'approval_mapping_id' => $mapping->id,
                'approver_id' => $approver->id,
                'diajukan_oleh' => auth()->id() ?? User::idsRole(Role::HR)[0] ?? null,
                'jenis_ref' => $jenis,
                'ref_id' => $refId,
                'level' => $level,
                'status' => 'menunggu',
            ]);
            Notifikasi::kirim($approver->id, $judul, $pesan . ($approvers->count() > 1 ? ' Cukup salah satu manager yang memutuskan.' : ''), 'approval', $approval->id);

            return $approval;
        });

        // kembalikan approval milik user yang sedang login (dipakai auto approve Manager HR), kalau tidak ada yang pertama
        return $dibuat->firstWhere('approver_id', auth()->id()) ?? $dibuat->first();
    }

    public function ajukanKontrak(Kontrak $kontrak): Approval
    {
        return DB::transaction(function () use ($kontrak) {
            $kontrak->update(['status_approval' => 'menunggu']);

            return $this->ajukan('kontrak', $kontrak->id);
        });
    }

    public function ajukanPayroll(Payroll $payroll): Approval
    {
        if ($payroll->status !== 'draft') {
            throw new RuntimeException('Hanya payroll berstatus draft yang bisa diajukan.');
        }

        return DB::transaction(function () use ($payroll) {
            $payroll->update(['status' => 'menunggu']);

            return $this->ajukan('payroll', $payroll->id);
        });
    }

    public function setujui(Approval $approval, User $user, ?string $catatan = null): void
    {
        $this->pastikanBisa($approval, $user);

        DB::transaction(function () use ($approval, $catatan, $user) {
            $approval->update(['status' => 'disetujui', 'catatan' => $catatan, 'tgl_approval' => now()->toDateString()]);
            $this->lewatiYangLain($approval, $user);

            // kalau masih ada level berikutnya, teruskan
            $next = ApprovalMapping::where('jenis_ref', $approval->jenis_ref)->where('level', $approval->level + 1)->exists();
            if ($next) {
                $this->ajukan($approval->jenis_ref, $approval->ref_id, $approval->level + 1);

                return;
            }

            $hr = User::idsRole(Role::HR);
            if ($approval->jenis_ref === 'kontrak') {
                $kontrak = Kontrak::with('karyawan.user')->findOrFail($approval->ref_id);
                $kontrak->update(['status_approval' => 'disetujui']);
                Kontrak::sinkronAktif(); // langsung aktif kalau tanggal mulainya sudah tiba
                $kapan = $kontrak->tgl_mulai->isFuture() ? ' dan berlaku mulai ' . tgl($kontrak->tgl_mulai) : ' dan sudah aktif';

                Notifikasi::kirim($hr, 'Kontrak disetujui', 'Kontrak ' . $kontrak->karyawan->nama . ' sudah disetujui' . $kapan . '.', 'kontrak', $kontrak->id);
                Notifikasi::kirim($kontrak->karyawan->user?->id, 'Kontrak baru disetujui', 'Kontrak kerja Anda sudah disetujui' . $kapan . '. Lihat di menu Profil & Kontrak.', 'kontrak', $kontrak->id);
            } else {
                $payroll = Payroll::findOrFail($approval->ref_id);
                $this->payroll->finalisasi($payroll);
                Notifikasi::kirim($hr, 'Payroll final', 'Payroll periode ' . $payroll->namaPeriode() . ' sudah disetujui. Slip gaji sudah terbit.', 'payroll', $payroll->id);
            }
        });
    }

    public function tolak(Approval $approval, User $user, string $catatan): void
    {
        $this->pastikanBisa($approval, $user);

        DB::transaction(function () use ($approval, $catatan, $user) {
            $approval->update(['status' => 'ditolak', 'catatan' => $catatan, 'tgl_approval' => now()->toDateString()]);
            $this->lewatiYangLain($approval, $user);
            $hr = User::idsRole(Role::HR);

            if ($approval->jenis_ref === 'kontrak') {
                $kontrak = Kontrak::with('karyawan')->findOrFail($approval->ref_id);
                $kontrak->update(['status_approval' => 'ditolak']);
                Notifikasi::kirim($hr, 'Kontrak ditolak', 'Kontrak ' . $kontrak->karyawan->nama . ' ditolak. Catatan: ' . $catatan, 'kontrak', $kontrak->id);
            } else {
                $payroll = Payroll::findOrFail($approval->ref_id);
                $payroll->update(['status' => 'ditolak']);
                Notifikasi::kirim($hr, 'Payroll perlu revisi', 'Payroll ' . $payroll->namaPeriode() . ' ditolak. Catatan: ' . $catatan, 'payroll', $payroll->id);
            }
        });
    }

    /** Setelah satu manager memutuskan, pengajuan yang sama untuk manager lain ditandai "dilewati". */
    private function lewatiYangLain(Approval $approval, User $user): void
    {
        Approval::where('jenis_ref', $approval->jenis_ref)->where('ref_id', $approval->ref_id)->where('level', $approval->level)
            ->where('status', 'menunggu')->whereKeyNot($approval->id)
            ->update(['status' => 'dilewati', 'catatan' => 'Sudah diproses oleh ' . $user->namaTampil(), 'tgl_approval' => now()->toDateString()]);
    }

    private function pastikanBisa(Approval $approval, User $user): void
    {
        if ($approval->status === 'dilewati') {
            throw new RuntimeException('Pengajuan ini sudah diproses manager lain. ' . $approval->catatan . '.');
        }
        if ($approval->status !== 'menunggu') {
            throw new RuntimeException('Approval ini sudah diproses.');
        }
        if ($approval->approver_id !== $user->id) {
            throw new RuntimeException('Anda bukan approver untuk pengajuan ini.');
        }
    }
}
