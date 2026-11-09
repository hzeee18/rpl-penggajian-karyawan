<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Departemen;
use App\Models\Karyawan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartemenController extends Controller
{
    public function index(Request $request)
    {
        $departemen = Departemen::with('managers.user.role')->withCount('karyawan')
            ->when($request->q, fn ($q, $s) => $q->where(fn ($q) => $q->where('nama', 'like', "%$s%")->orWhere('kode', 'like', "%$s%")))
            ->orderBy('kode')->get();
        $ubah = $request->has('baru') ? new Departemen() : ($departemen->firstWhere('id', $request->integer('ubah')) ?? $departemen->first() ?? new Departemen());

        return view('master.departemen.index', ['departemen' => $departemen, 'ubah' => $ubah, 'karyawan' => Karyawan::with('user.role', 'departemen')->where('is_active', true)->orderBy('nama')->get()]);
    }

    public function create()
    {
        return view('master.departemen.form', ['departemen' => new Departemen(), 'karyawan' => Karyawan::where('is_active', true)->orderBy('nama')->get()]);
    }

    public function store(Request $request)
    {
        $data = $this->validasi($request);
        $d = Departemen::create(['kode' => $data['kode'], 'nama' => $data['nama']]);
        $d->aturManager($data['manager_ids'] ?? []);

        return redirect()->route('departemen.index', ['ubah' => $d->id])->with('sukses', 'Departemen ditambahkan.');
    }

    public function edit(Departemen $departemen)
    {
        return view('master.departemen.form', ['departemen' => $departemen, 'karyawan' => Karyawan::where('is_active', true)->orderBy('nama')->get()]);
    }

    public function update(Request $request, Departemen $departemen)
    {
        $data = $this->validasi($request, $departemen);
        $departemen->update(['kode' => $data['kode'], 'nama' => $data['nama']]);
        $departemen->aturManager($data['manager_ids'] ?? []);

        return redirect()->route('departemen.index', ['ubah' => $departemen->id])->with('sukses', 'Departemen disimpan.');
    }

    public function destroy(Departemen $departemen)
    {
        if ($departemen->karyawan()->exists()) {
            return back()->with('gagal', 'Departemen masih punya karyawan, tidak bisa dihapus.');
        }
        $departemen->delete();

        return back()->with('sukses', 'Departemen dihapus.');
    }

    private function validasi(Request $request, ?Departemen $d = null): array
    {
        return $request->validate([
            'kode' => ['required', 'max:10', Rule::unique('departemen')->ignore($d)],
            'nama' => 'required|max:100',
            'manager_ids' => 'nullable|array',
            'manager_ids.*' => 'exists:karyawan,id',
        ]);
    }
}
