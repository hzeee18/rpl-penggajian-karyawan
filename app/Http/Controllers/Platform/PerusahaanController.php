<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Domain\Company\Actions\BuatPerusahaan;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Menu "Perusahaan" (Super Admin): kelola perusahaan dan pilih perusahaan aktif. */
final class PerusahaanController extends Controller
{
    public function __construct(private readonly CurrentCompany $konteks) {}

    public function index(Request $request)
    {
        $perusahaan = Company::orderBy('code')->get();

        // lintas perusahaan: halaman platform Super Admin menampilkan jumlah data tiap perusahaan
        $jumlah = fn (string $tabel) => DB::table($tabel)->whereNotNull('company_id')->groupBy('company_id')
            ->selectRaw('company_id, count(*) as n')->pluck('n', 'company_id');
        $ubah = $request->has('baru') ? new Company : ($perusahaan->firstWhere('id', $request->integer('ubah')) ?? new Company);

        return view('platform.perusahaan.index', [
            'perusahaan' => $perusahaan,
            'karyawan' => $jumlah('karyawan'),
            'pengguna' => $jumlah('users'),
            'aktif' => $this->konteks->perusahaanDipilih(),
            'ubah' => $ubah,
        ]);
    }

    public function store(Request $request, BuatPerusahaan $buat)
    {
        $data = $request->validate([
            'code' => ['required', 'max:32', 'alpha_dash', Rule::unique('companies', 'code')],
        ] + $this->aturan());
        $company = $buat->handle($data);

        return redirect()->route('perusahaan.index', ['ubah' => $company->id])
            ->with('sukses', "Perusahaan {$company->name} dibuat dengan role dan hak akses bawaan.");
    }

    public function update(Request $request, Company $perusahaan)
    {
        $data = $request->validate($this->aturan() + ['is_active' => 'boolean', 'tanda_tangan' => 'nullable|image|mimes:png,jpg,jpeg|max:1024']);
        if ($request->hasFile('tanda_tangan')) {
            // disimpan di disk privat; hanya dipakai di PDF slip
            $data['signature_path'] = $request->file('tanda_tangan')->storeAs("signatures/{$perusahaan->id}", 'tanda-tangan.'.$request->file('tanda_tangan')->extension(), 'local');
        }
        unset($data['tanda_tangan']);
        $perusahaan->update($data);

        return redirect()->route('perusahaan.index', ['ubah' => $perusahaan->id])->with('sukses', 'Perusahaan diperbarui.');
    }

    public function pilih(Company $perusahaan)
    {
        abort_unless($perusahaan->is_active, 422, 'Perusahaan nonaktif tidak bisa dipilih.');
        $this->konteks->pilihPerusahaan((int) $perusahaan->id);

        return redirect()->route('perusahaan.index')->with('sukses', "Sekarang bekerja di {$perusahaan->name}.");
    }

    public function keluar()
    {
        $this->konteks->pilihPerusahaan(null);

        return redirect()->route('perusahaan.index')->with('sukses', 'Tidak ada perusahaan aktif.');
    }

    /** @return array<string, mixed> */
    private function aturan(): array
    {
        return [
            'name' => 'required|max:191',
            'address' => 'nullable|max:1000',
            'signer_name' => 'nullable|max:191',
            'signer_title' => 'nullable|max:191',
        ];
    }
}
