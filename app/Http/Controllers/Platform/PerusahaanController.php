<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Domain\Company\Actions\BuatAdminPerusahaan;
use App\Domain\Company\Actions\BuatPerusahaan;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Actions\AlihkanKePayrollBaru;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

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
        $adminUntuk = $perusahaan->firstWhere('id', $request->integer('admin')); // drawer akun Admin perusahaan

        return view('platform.perusahaan.index', [
            'perusahaan' => $perusahaan,
            'karyawan' => $jumlah('karyawan'),
            'pengguna' => $jumlah('users'),
            'aktif' => $this->konteks->perusahaanDipilih(),
            'ubah' => $ubah,
            'adminUntuk' => $adminUntuk,
            'adminPerusahaan' => $adminUntuk ? app(BuatAdminPerusahaan::class)->daftar((int) $adminUntuk->id) : collect(),
        ]);
    }

    public function store(Request $request, BuatPerusahaan $buat, AlihkanKePayrollBaru $payrollBaru, BuatAdminPerusahaan $admin)
    {
        $data = $request->validate([
            'code' => ['required', 'max:32', 'alpha_dash', Rule::unique('companies', 'code')],
        ] + $this->aturan() + $this->aturanAdmin());
        $akun = ['username' => $data['admin_username'], 'email' => $data['admin_email'] ?? null, 'password' => $data['admin_password']];
        unset($data['admin_username'], $data['admin_email'], $data['admin_password'], $data['admin_password_confirmation']);

        // perusahaan + akun Admin pertama dalam satu transaksi: tidak ada perusahaan tanpa pengelola
        $company = DB::transaction(function () use ($buat, $payrollBaru, $admin, $data, $akun) {
            $company = $buat->handle($data);
            $payrollBaru->handle((int) $company->id); // modul payroll baru sudah diterima (AUDIT.md §29)
            $admin->handle((int) $company->id, $akun);

            return $company;
        });

        return redirect()->route('perusahaan.index', ['ubah' => $company->id])
            ->with('sukses', "Perusahaan {$company->name} dibuat. Admin perusahaan bisa login dengan username {$akun['username']}.");
    }

    /** Tambah akun Admin untuk perusahaan (tanpa perlu memilih perusahaan dulu). */
    public function tambahAdmin(Request $request, Company $perusahaan, BuatAdminPerusahaan $admin)
    {
        $data = $request->validate($this->aturanAdmin());
        $admin->handle((int) $perusahaan->id, ['username' => $data['admin_username'], 'email' => $data['admin_email'] ?? null, 'password' => $data['admin_password']]);

        return redirect()->route('perusahaan.index', ['admin' => $perusahaan->id])
            ->with('sukses', "Akun Admin {$data['admin_username']} dibuat untuk {$perusahaan->name}.");
    }

    /** @return array<string, mixed> */
    private function aturanAdmin(): array
    {
        return [
            // boleh titik seperti akun yang sudah ada (manager.hr, staf.mts)
            'admin_username' => ['required', 'regex:/^[A-Za-z0-9._-]+$/', 'max:50', Rule::unique('users', 'username')],
            'admin_email' => ['nullable', 'email', 'max:100'],
            'admin_password' => ['required', 'confirmed', Password::min(8)],
        ];
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
