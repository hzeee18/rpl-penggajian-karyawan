<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Actions\KelolaTabelReferensi;
use App\Domain\Payroll\Models\ReferenceTable;
use App\Domain\Payroll\Models\ReferenceTableVersion;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tabel referensi berversi (TER, kategori PTKP, tarif, ...). Tabel nasional hanya diubah Super Admin. */
final class ReferensiController extends Controller
{
    public function __construct(private readonly KelolaTabelReferensi $kelola, private readonly CurrentCompany $konteks) {}

    public function index()
    {
        return view('payroll-baru.referensi.index', [
            'tabel' => ReferenceTable::with(['versions' => fn ($q) => $q->limit(1)])->orderByRaw('company_id is null')->orderBy('code')->get(),
            'superAdmin' => $this->konteks->isSuperAdmin(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => 'required|max:191',
            'description' => 'nullable|max:255',
            'nasional' => 'nullable|boolean',
        ]);
        $nasional = (bool) ($data['nasional'] ?? false);
        $ada = ReferenceTable::where('code', $data['code'])->when($nasional, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->whereNotNull('company_id'))->exists();
        if ($ada) {
            return back()->withInput()->withErrors(['code' => 'Kode tabel sudah dipakai.']);
        }
        $tabel = $this->kelola->buat(collect($data)->only(['code', 'name', 'description'])->all(), $nasional);

        return redirect()->route('pay.referensi.versi', $tabel->versions()->value('id'))->with('sukses', 'Tabel referensi dibuat.');
    }

    public function versi(Request $request, int $id)
    {
        $versi = ReferenceTableVersion::with(['table', 'rows'])->findOrFail($id);

        return view('payroll-baru.referensi.versi', [
            'versi' => $versi,
            'semuaVersi' => ReferenceTableVersion::where('reference_table_id', $versi->reference_table_id)->orderByDesc('version_no')->get(),
            'bolehKelola' => ! $versi->isNasional() || $this->konteks->isSuperAdmin(),
            'ubah' => $request->integer('ubah') ? $versi->rows->firstWhere('id', $request->integer('ubah')) : null,
        ]);
    }

    public function simpanBaris(Request $request, int $id)
    {
        $versi = ReferenceTableVersion::findOrFail($id);
        $this->kelola->simpanBaris($versi, $this->dataBaris($request));

        return back()->with('sukses', 'Baris ditambahkan.');
    }

    public function ubahBaris(Request $request, int $id, int $baris)
    {
        $versi = ReferenceTableVersion::findOrFail($id);
        $this->kelola->simpanBaris($versi, $this->dataBaris($request), $versi->rows()->findOrFail($baris));

        return redirect()->route('pay.referensi.versi', $versi->id)->with('sukses', 'Baris diperbarui.');
    }

    public function hapusBaris(int $id, int $baris)
    {
        $versi = ReferenceTableVersion::findOrFail($id);
        $this->kelola->hapusBaris($versi, $versi->rows()->findOrFail($baris));

        return back()->with('sukses', 'Baris dihapus.');
    }

    public function terbitkan(Request $request, int $id)
    {
        $data = $request->validate(['effective_from' => 'nullable|date']);
        $this->kelola->terbitkan(ReferenceTableVersion::findOrFail($id), $data['effective_from'] ?? null);

        return back()->with('sukses', 'Versi diterbitkan.');
    }

    public function salin(int $id)
    {
        $baru = $this->kelola->salin(ReferenceTableVersion::with('rows')->findOrFail($id));

        return redirect()->route('pay.referensi.versi', $baru->id)->with('sukses', 'Versi baru (draft) dibuat.');
    }

    /** @return array<string, ?string> */
    private function dataBaris(Request $request): array
    {
        $d = $request->validate([
            'key_value' => 'nullable|max:64',
            'range_from' => 'nullable|decimal:0,2',
            'range_to' => 'nullable|decimal:0,2',
            'result_number' => ['nullable', 'decimal:0,6', Rule::requiredIf(fn () => blank($request->input('result_text')))],
            'result_text' => 'nullable|max:64',
            'sort_order' => 'nullable|integer',
        ]);

        return array_map(fn ($v) => $v === '' ? null : $v, $d) + ['sort_order' => 0];
    }
}
