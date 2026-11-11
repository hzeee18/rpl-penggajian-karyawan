<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\Kontrak;
use App\Models\KontrakKomponen;
use App\Models\PayslipComponent;
use App\Services\ApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KontrakController extends Controller
{
    public function __construct(private ApprovalService $approval)
    {
    }

    /**
     * Satu baris per karyawan: kontrak yang sedang berlaku (atau yang terbaru kalau belum ada yang aktif).
     * Perpanjangan yang sedang diajukan ditampilkan sebagai keterangan di baris yang sama, riwayat lengkap ada di drawer.
     */
    public function index(Request $request)
    {
        Kontrak::sinkronAktif();
        $q = trim((string) $request->q);
        $semua = Karyawan::with(['departemen', 'kontrak.approvals'])->whereHas('kontrak')
            ->when($q, fn ($w) => $w->where(fn ($w) => $w->where('nama', 'like', "%$q%")->orWhere('nik', 'like', "%$q%")
                ->orWhereHas('kontrak', fn ($k) => $k->where('nomor', 'like', "%$q%"))))
            ->orderBy('nama')->get()
            ->map(function ($k) {
                $daftar = $k->kontrak->sortByDesc(fn ($c) => $c->tgl_mulai->toDateString() . str_pad((string) $c->id, 8, '0', STR_PAD_LEFT))->values();
                $berlaku = $daftar->firstWhere('is_active', true) ?? $daftar->first();
                $proses = $daftar->first(fn ($c) => $c->id !== $berlaku->id && in_array($c->status_approval, ['draft', 'menunggu', 'ditolak'])
                    || ($c->status_approval === 'disetujui' && ! $c->is_active && $c->tgl_mulai->isFuture()));

                return (object) ['karyawan' => $k, 'kontrak' => $berlaku, 'proses' => $proses === $berlaku ? null : $proses, 'jumlah' => $daftar->count(), 'status' => $this->statusBaris($berlaku, $proses)];
            });

        $hitung = $semua->countBy('status');
        $status = $request->status;
        $baris = $semua->when($status, fn ($c) => $c->where('status', $status))
            ->sortBy(fn ($b) => [['berakhir' => 0, 'menunggu' => 1, 'ditolak' => 2, 'lanjut' => 3, 'aktif' => 4, 'berakhir_lewat' => 5][$b->status] ?? 5, $b->kontrak->tgl_akhir?->toDateString() ?? '9999'])->values();
        $hal = max(1, $request->integer('page', 1));
        $daftar = new \Illuminate\Pagination\LengthAwarePaginator($baris->forPage($hal, 10)->values(), $baris->count(), 10, $hal, ['path' => $request->url(), 'query' => $request->query()]);

        // drawer: lihat (detail + riwayat), ubah (revisi), perbarui (perpanjangan), baru
        $lihat = $request->lihat ? Kontrak::with(['karyawan.departemen', 'komponen.component', 'approvals.approver.karyawan'])->find($request->lihat) : null;
        $riwayat = $lihat ? Kontrak::with('approvals.approver.karyawan')->where('karyawan_id', $lihat->karyawan_id)->orderByDesc('tgl_mulai')->orderByDesc('id')->get() : collect();

        $ubah = $request->ubah ? Kontrak::with('komponen', 'karyawan.departemen', 'approvals')->find($request->ubah) : null;
        if ($ubah && ! $ubah->bisaDiubah()) {
            $ubah = null;
        }
        $lama = $request->perbarui ? Kontrak::with('komponen', 'karyawan.departemen')->find($request->perbarui) : null;
        if ($lama && ! $lama->bisaDiperbarui()) {
            return redirect()->route('kontrak.index')->with('gagal', 'Kontrak ini belum bisa diperbarui. Tombol Perbarui muncul ' . Kontrak::BULAN_PERBARUI . ' bulan sebelum kontrak berakhir.');
        }

        $form = $ubah ?? ($lama ? $this->dariKontrakLama($lama) : new Kontrak([
            'karyawan_id' => $request->karyawan_id, 'jenis' => 'PKWT', 'tgl_mulai' => now()->addMonthNoOverflow()->startOfMonth(),
            'tgl_akhir' => now()->addMonthNoOverflow()->startOfMonth()->addYear()->subDay(),
        ]));

        return view('kontrak.index', $this->dataForm($form) + compact('daftar', 'hitung', 'lihat', 'riwayat', 'lama'));
    }

    /** Status satu baris di daftar. */
    private function statusBaris(Kontrak $k, ?Kontrak $proses): string
    {
        if ($proses && in_array($proses->status_approval, ['draft', 'menunggu'])) return 'menunggu';
        if ($proses && $proses->status_approval === 'ditolak') return 'ditolak';
        if ($proses && $proses->status_approval === 'disetujui') return 'lanjut';
        if (! $k->is_active) return match ($k->status_approval) { 'disetujui' => $k->tgl_mulai->isFuture() ? 'menunggu' : 'berakhir_lewat', 'ditolak' => 'ditolak', default => 'menunggu' };
        if ($k->bisaDiperbarui()) return 'berakhir';

        return 'aktif';
    }

    /** Draft perpanjangan: mulai sehari setelah kontrak lama berakhir, masa & gaji & komponen sama. */
    private function dariKontrakLama(Kontrak $lama): Kontrak
    {
        $mulai = $lama->tgl_akhir->copy()->addDay();
        $bulan = max(1, (int) round($lama->tgl_mulai->diffInMonths($lama->tgl_akhir->copy()->addDay())));
        $baru = new Kontrak([
            'karyawan_id' => $lama->karyawan_id, 'kontrak_sebelumnya_id' => $lama->id, 'jenis' => 'PKWT',
            'tgl_mulai' => $mulai, 'tgl_akhir' => $mulai->copy()->addMonthsNoOverflow($bulan)->subDay(), 'gaji_pokok' => $lama->gaji_pokok,
        ]);
        $baru->setRelation('karyawan', $lama->karyawan);
        $baru->setRelation('komponen', $lama->komponen);

        return $baru;
    }

    public function show(Kontrak $kontrak)
    {
        $kontrak->load(['karyawan.departemen', 'komponen.component', 'approvals.approver.karyawan']);

        return view('kontrak.show', compact('kontrak'));
    }

    public function create(Request $request)
    {
        return view('kontrak.form', $this->dataForm(new Kontrak([
            'karyawan_id' => $request->karyawan_id, 'jenis' => 'PKWT', 'tgl_mulai' => now()->startOfMonth(),
        ])));
    }

    public function store(Request $request)
    {
        [$data, $komponen] = $this->validasi($request);
        $lama = $request->filled('kontrak_sebelumnya_id') ? Kontrak::find($request->integer('kontrak_sebelumnya_id')) : null;
        if ($lama) {
            if (! $lama->bisaDiperbarui()) {
                return back()->withInput()->with('gagal', 'Kontrak sebelumnya sudah diperbarui atau belum masuk masa perpanjangan.');
            }
            $data['karyawan_id'] = $lama->karyawan_id;
            $data['kontrak_sebelumnya_id'] = $lama->id;
            if ($data['tgl_mulai'] <= $lama->tgl_akhir->toDateString()) {
                return back()->withInput()->withErrors(['tgl_mulai' => 'Tanggal mulai harus setelah kontrak lama berakhir (' . tgl($lama->tgl_akhir) . ').']);
            }
        } elseif (Kontrak::where('karyawan_id', $data['karyawan_id'])->where(fn ($w) => $w->where('is_active', true)->orWhereIn('status_approval', ['draft', 'menunggu']))->exists()) {
            return back()->withInput()->withErrors(['karyawan_id' => 'Karyawan ini sudah punya kontrak aktif atau yang sedang diajukan. Pakai tombol Perbarui kalau kontraknya mau habis.']);
        }

        try {
            $kontrak = DB::transaction(function () use ($data, $komponen) {
                $kontrak = Kontrak::create($data + ['status_approval' => 'draft', 'is_active' => false]);
                $this->simpanKomponen($kontrak, $komponen);
                $this->approval->ajukanKontrak($kontrak);

                return $kontrak;
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->with('gagal', $e->getMessage());
        }

        return redirect()->route('kontrak.index')->with('sukses', ($lama ? 'Perpanjangan kontrak ' : 'Kontrak ') . $kontrak->karyawan->nama . ' (' . $kontrak->nomor . ') disimpan dan diajukan ke Manager divisi untuk disetujui.');
    }

    public function edit(Kontrak $kontrak)
    {
        if (! $kontrak->bisaDiubah()) {
            return redirect()->route('kontrak.show', $kontrak)->with('gagal', 'Kontrak yang menunggu approval atau sudah disetujui tidak bisa diubah.');
        }

        return view('kontrak.form', $this->dataForm($kontrak));
    }

    /** Revisi kontrak yang ditolak, lalu ajukan ulang. */
    public function update(Request $request, Kontrak $kontrak)
    {
        abort_unless($kontrak->bisaDiubah(), 403, 'Kontrak tidak bisa diubah.');
        [$data, $komponen] = $this->validasi($request);

        try {
            DB::transaction(function () use ($kontrak, $data, $komponen) {
                $kontrak->update($data);
                $kontrak->komponen()->delete();
                $this->simpanKomponen($kontrak, $komponen);
                $this->approval->ajukanKontrak($kontrak);
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->with('gagal', $e->getMessage());
        }

        return redirect()->route('kontrak.index')->with('sukses', 'Kontrak direvisi dan diajukan ulang.');
    }

    public function destroy(Kontrak $kontrak)
    {
        if ($kontrak->status_approval === 'disetujui') {
            return back()->with('gagal', 'Kontrak yang sudah disetujui tidak bisa dihapus.');
        }
        \App\Models\Approval::where('jenis_ref', 'kontrak')->where('ref_id', $kontrak->id)->delete();
        $kontrak->delete();

        return redirect()->route('kontrak.index')->with('sukses', 'Kontrak dihapus.');
    }

    private function dataForm(Kontrak $kontrak): array
    {
        return [
            'kontrak' => $kontrak,
            'karyawan' => Karyawan::with('departemen')->where('is_active', true)
                ->when(! $kontrak->exists && ! $kontrak->kontrak_sebelumnya_id, fn ($q) => $q->whereDoesntHave('kontrak', fn ($k) => $k->where('is_active', true)->orWhereIn('status_approval', ['draft', 'menunggu'])))
                ->orderBy('nama')->get(),
            'komponen' => PayslipComponent::untukKontrak()->orderBy('jenis')->get(),
            'terpasang' => ($kontrak->exists || $kontrak->relationLoaded('komponen')) ? $kontrak->komponen->keyBy('payslip_component_id') : collect(),
        ];
    }

    private function validasi(Request $request): array
    {
        $data = $request->validate([
            'karyawan_id' => ['required', \App\Domain\Company\Tenancy\TenantRule::exists('karyawan')],
            'jenis' => 'required|in:PKWT,PKWTT',
            'tgl_mulai' => 'required|date',
            'tgl_akhir' => 'nullable|required_if:jenis,PKWT|date|after:tgl_mulai',
            'gaji_pokok' => 'required|numeric|min:0',
            'komponen' => 'array',
            'komponen.*.pakai' => 'nullable',
            'komponen.*.tipe_nilai' => 'required_with:komponen.*.pakai|in:nominal,persen',
            'komponen.*.nilai' => 'required_with:komponen.*.pakai|nullable|numeric|min:0',
        ]);
        $komponen = collect($data['komponen'] ?? [])->filter(fn ($k) => ! empty($k['pakai']));
        unset($data['komponen']);
        if ($data['jenis'] === 'PKWTT') {
            $data['tgl_akhir'] = null;
        }

        return [$data, $komponen];
    }

    private function simpanKomponen(Kontrak $kontrak, $komponen): void
    {
        foreach ($komponen as $id => $k) {
            KontrakKomponen::create([
                'kontrak_id' => $kontrak->id,
                'payslip_component_id' => $id,
                'tipe_nilai' => $k['tipe_nilai'],
                'nilai' => $k['nilai'] ?? 0,
                'is_active' => true,
            ]);
        }
    }
}
