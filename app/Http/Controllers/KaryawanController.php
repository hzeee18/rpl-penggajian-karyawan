<?php

namespace App\Http\Controllers;

use App\Models\Departemen;
use App\Models\Karyawan;
use App\Models\Notifikasi;
use App\Models\Pajak;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KaryawanController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = $user->batasi(Karyawan::with(['departemen', 'kontrakAktif', 'pajak', 'user', 'kontrak']), 'id')
            ->when($request->q, fn ($q, $s) => $q->where(fn ($q) => $q->where('nama', 'like', "%$s%")->orWhere('nik', 'like', "%$s%")))
            ->when($request->departemen_id, fn ($q, $d) => $q->where('departemen_id', $d))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->status === 'aktif'));

        // Manager: "Tim Saya" (anggota departemennya, tanpa dirinya)
        if ($user->isManagerTim()) {
            $karyawan = $query->where('id', '!=', $user->karyawan_id)->orderBy('nik')->paginate(10)->withQueryString();
            if ($request->export) {
                return \App\Support\Ekspor::excel('tim-saya', 'Tim Saya', ['NIK', 'Nama', 'Jabatan', 'Kontrak', 'Sisa cuti'],
                    $query->get()->map(fn ($k) => [$k->nik, $k->nama, $k->jabatan, $k->kontrakAktif?->jenis, $k->sisa_cuti]));
            }
            $pilih = $karyawan->firstWhere('id', $request->integer('lihat')) ?? $karyawan->first();

            return view('karyawan.tim', ['karyawan' => $karyawan, 'pilih' => $pilih, 'dept' => Departemen::dikelola($user->karyawan_id)->first()]);
        }

        $karyawan = $query->orderBy('nik')->paginate(10)->withQueryString();
        $ubah = $karyawan->firstWhere('id', $request->integer('ubah')) ?? ($request->ubah ? Karyawan::find($request->ubah) : $karyawan->first());

        return view('karyawan.index', [
            'karyawan' => $karyawan, 'ubah' => $ubah,
            'departemen' => Departemen::orderBy('nama')->get(), 'pajak' => Pajak::orderBy('status_ptkp')->get(),
        ]);
    }

    public function show(Request $request, Karyawan $karyawan)
    {
        $this->cekScope($request, $karyawan);
        $karyawan->load(['departemen', 'pajak', 'user.role', 'kontrak.komponen.component']);

        return view('karyawan.show', compact('karyawan'));
    }

    public function create()
    {
        return view('karyawan.form', $this->dataForm(new Karyawan(['is_active' => true, 'sisa_cuti' => 12, 'status_kepegawaian' => 'kontrak'])));
    }

    public function store(Request $request)
    {
        $karyawan = Karyawan::create($this->validasi($request));
        Notifikasi::kirim(User::idsRole(Role::ADMIN), 'Karyawan baru',
            "Karyawan baru {$karyawan->nama} ({$karyawan->nik}) ditambahkan. Silakan buatkan akun login.", 'karyawan', $karyawan->id);

        return redirect()->route('karyawan.show', $karyawan)->with('sukses', 'Karyawan ditambahkan. Lanjut buat kontraknya.');
    }

    public function edit(Karyawan $karyawan)
    {
        return view('karyawan.form', $this->dataForm($karyawan));
    }

    public function update(Request $request, Karyawan $karyawan)
    {
        $karyawan->update($this->validasi($request, $karyawan));

        // simpan dari panel "Ubah Karyawan" di halaman daftar
        if ($request->kembali === 'index') {
            return redirect()->route('karyawan.index', ['ubah' => $karyawan->id])->with('sukses', 'Data ' . $karyawan->nama . ' disimpan.');
        }

        return redirect()->route('karyawan.show', $karyawan)->with('sukses', 'Data karyawan diubah.');
    }

    /** Karyawan yang sudah punya riwayat gaji hanya dinonaktifkan. */
    public function destroy(Karyawan $karyawan)
    {
        if ($karyawan->payslips()->exists() || \App\Models\PayrollDetail::where('karyawan_id', $karyawan->id)->exists()) {
            $karyawan->update(['is_active' => false]);
            $karyawan->user?->update(['is_active' => false]);

            return back()->with('sukses', 'Karyawan punya riwayat gaji, jadi dinonaktifkan (tidak dihapus).');
        }
        $karyawan->user?->delete();
        $karyawan->delete();

        return redirect()->route('karyawan.index')->with('sukses', 'Karyawan dihapus.');
    }

    private function dataForm(Karyawan $karyawan): array
    {
        return [
            'karyawan' => $karyawan,
            'departemen' => Departemen::orderBy('nama')->get(),
            'pajak' => Pajak::orderBy('status_ptkp')->get(),
        ];
    }

    private function validasi(Request $request, ?Karyawan $k = null): array
    {
        $data = $request->validate([
            'nik' => ['required', 'max:20', Rule::unique('karyawan')->ignore($k)],
            'nama' => 'required|max:100',
            'jenis_kelamin' => 'nullable|in:L,P',
            'departemen_id' => 'required|exists:departemen,id',
            'jabatan' => 'required|max:100',
            'tgl_masuk' => 'required|date',
            'pajak_id' => 'required|exists:pajak,id',
            'status_kepegawaian' => 'required|in:tetap,kontrak,harian',
            'email' => 'nullable|email|max:100',
            'telepon' => 'nullable|max:20',
            'alamat' => 'nullable|max:500',
            'nama_bank' => 'nullable|max:50',
            'no_rekening' => 'nullable|max:30',
            'sisa_cuti' => 'required|integer|min:0|max:365',
        ]);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function cekScope(Request $request, Karyawan $karyawan): void
    {
        $ids = $request->user()->karyawanIds();
        abort_if($ids !== null && ! in_array($karyawan->id, $ids), 403, 'Karyawan ini bukan anggota tim Anda.');
    }
}
