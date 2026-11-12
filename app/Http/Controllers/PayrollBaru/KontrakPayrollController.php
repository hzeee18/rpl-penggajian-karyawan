<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Company\Tenancy\TenantRule;
use App\Domain\Payroll\Actions\BuatKontrak;
use App\Domain\Payroll\Actions\NonaktifkanKontrak;
use App\Domain\Payroll\Actions\PerbaruiKontrak;
use App\Domain\Payroll\Actions\UbahKontrak;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Kontrak payroll baru: buat, ubah di tempat (koreksi, selama belum dipakai payroll final),
 * perbarui (versi baru, kontrak lama dinonaktifkan), nonaktifkan.
 */
final class KontrakPayrollController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->input('status', 'aktif');
        $kontrak = Contract::with(['employee.departemen', 'components.component'])
            ->when($status === 'aktif', fn ($q) => $q->active())
            ->when($status === 'riwayat', fn ($q) => $q->where('is_active', false))
            ->when($request->q, fn ($q, $s) => $q->whereHas('employee', fn ($e) => $e->where('nama', 'like', "%{$s}%")->orWhere('nik', 'like', "%{$s}%")))
            ->orderByDesc('start_date')->orderByDesc('id')->paginate(15)->withQueryString();

        $lihat = $request->integer('lihat') ? Contract::with(['employee', 'components.component', 'previous'])->find($request->integer('lihat')) : null;
        $perbarui = $request->integer('perbarui') ? Contract::with('components')->active()->find($request->integer('perbarui')) : null;
        $ubah = $request->integer('ubah') ? Contract::with(['components', 'employee'])->active()->find($request->integer('ubah')) : null;
        if ($ubah?->terkunci()) {
            return redirect()->route('pay.kontrak.index', ['lihat' => $ubah->id])
                ->with('gagal', 'Kontrak ini sudah dipakai payroll yang diajukan/disetujui. Gunakan Perbarui untuk membuat versi baru.');
        }
        $riwayat = $lihat ? Contract::where('employee_id', $lihat->employee_id)->orderByDesc('start_date')->orderByDesc('id')->get() : collect();

        return view('payroll-baru.kontrak', [
            'kontrak' => $kontrak,
            'status' => $status,
            'lihat' => $lihat,
            'riwayat' => $riwayat,
            'perbarui' => $perbarui,
            'ubah' => $ubah,
            'terkunci' => $lihat?->is_active ? $lihat->terkunci() : false,
            'baru' => $request->has('baru'),
            'karyawanTanpaKontrak' => Employee::where('is_active', true)->whereDoesntHave('activeContract')->orderBy('nama')->get(),
            'katalog' => Component::where('is_active', true)->where('is_contractable', true)->orderBy('type')->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request, BuatKontrak $buat)
    {
        $data = $request->validate($this->aturan() + ['employee_id' => ['required', TenantRule::exists('karyawan')]]);
        $kontrak = $buat->handle($this->dataKontrak($data) + ['employee_id' => (int) $data['employee_id']], $this->nilaiKomponen($data));

        return redirect()->route('pay.kontrak.index', ['lihat' => $kontrak->id])->with('sukses', 'Kontrak dibuat.');
    }

    public function perbarui(Request $request, int $id, PerbaruiKontrak $perbarui)
    {
        $lama = Contract::active()->findOrFail($id);
        $data = $request->validate($this->aturan());
        $baru = $perbarui->handle($lama, $this->dataKontrak($data), $this->nilaiKomponen($data));

        return redirect()->route('pay.kontrak.index', ['lihat' => $baru->id])->with('sukses', 'Kontrak diperbarui. Kontrak lama dinonaktifkan.');
    }

    public function ubah(Request $request, int $id, UbahKontrak $ubah)
    {
        $kontrak = Contract::active()->findOrFail($id);
        $data = $request->validate($this->aturan());
        $ubah->handle($kontrak, $this->dataKontrak($data), $this->nilaiKomponen($data));

        return redirect()->route('pay.kontrak.index', ['lihat' => $kontrak->id])->with('sukses', 'Kontrak diubah.');
    }

    public function nonaktifkan(int $id, NonaktifkanKontrak $nonaktifkan)
    {
        $nonaktifkan->handle(Contract::findOrFail($id));

        return redirect()->route('pay.kontrak.index', ['lihat' => $id])->with('sukses', 'Kontrak dinonaktifkan.');
    }

    /** @return array<string, mixed> */
    private function aturan(): array
    {
        return [
            'contract_name' => 'required|max:191',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'info_thp' => 'nullable|decimal:0,2|min:0',
            'komponen' => 'nullable|array',
            'komponen.*.pakai' => 'nullable|boolean',
            'komponen.*.amount' => 'nullable|decimal:0,2|min:0|max:9999999999999.99',
        ];
    }

    /** @return array<string, string|null> */
    private function dataKontrak(array $data): array
    {
        return [
            'contract_name' => $data['contract_name'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'info_thp' => isset($data['info_thp']) && $data['info_thp'] !== '' ? (string) $data['info_thp'] : null,
        ];
    }

    /** @return array<int, string|null> component_id => nominal, hanya yang dicentang */
    private function nilaiKomponen(array $data): array
    {
        $nilai = [];
        foreach ($data['komponen'] ?? [] as $id => $k) {
            if (! empty($k['pakai'])) {
                $nilai[(int) $id] = isset($k['amount']) && $k['amount'] !== '' ? (string) $k['amount'] : null;
            }
        }

        return $nilai;
    }
}
