<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Company\Tenancy\TenantRule;
use App\Domain\Payroll\Actions\KelolaMasterPayroll;
use App\Domain\Payroll\Engine\HasilHitung;
use App\Domain\Payroll\Engine\Kalkulator;
use App\Domain\Payroll\Engine\RumusException;
use App\Domain\Payroll\Engine\SumberData;
use App\Domain\Payroll\Engine\ValidasiMaster;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\MasterVersion;
use App\Domain\Payroll\Models\PeriodConfig;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Master payroll berversi: variabel, parameter, komponen + rumus; uji rumus untuk satu karyawan. */
final class MasterPayrollBaruController extends Controller
{
    public function __construct(private readonly KelolaMasterPayroll $kelola) {}

    public function index()
    {
        return view('payroll-baru.master.index', ['master' => Master::with(['currentVersion', 'versions'])->orderBy('code')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', TenantRule::unique('pay_masters', 'code')],
            'name' => 'required|max:191',
            'description' => 'nullable|max:255',
        ]);
        $master = $this->kelola->buatMaster($data);

        return redirect()->route('pay.master.versi', $master->versions()->value('id'))->with('sukses', 'Master payroll dibuat (versi 1 draft).');
    }

    public function versi(Request $request, int $id, ValidasiMaster $validasi)
    {
        $versi = MasterVersion::with(['master.versions', 'variables', 'params', 'items.component'])->findOrFail($id);
        $uji = null;
        $ujiError = null;
        if ($request->filled('uji_karyawan')) {
            [$uji, $ujiError] = $this->uji($versi, $request->integer('uji_karyawan'), (string) $request->input('uji_periode', now()->format('Y-m')));
        }

        return view('payroll-baru.master.versi', [
            'versi' => $versi,
            'masalah' => $versi->bisaDiubah() ? $validasi->periksa($versi) : [],
            'komponen' => Component::where('is_active', true)->orderBy('code')->get(),
            'karyawan' => Employee::where('is_active', true)->whereHas('activeContract')->orderBy('nama')->get(['id', 'nik', 'nama']),
            'sumberField' => SumberData::FIELD,
            'bawaan' => Kalkulator::BAWAAN + Kalkulator::BAWAAN_PPH21,
            'form' => $request->input('form'),
            'ubah' => $request->integer('ubah'),
            'uji' => $uji,
            'ujiError' => $ujiError,
        ]);
    }

    public function simpanIsi(Request $request, int $id, string $jenis)
    {
        $versi = MasterVersion::findOrFail($id);
        $ubahId = $request->integer('ubah_id') ?: null;

        match ($jenis) {
            'variabel' => $this->kelola->simpanVariabel($versi, $request->validate([
                'code' => $this->aturanKode('pay_variables', $versi, $ubahId),
                'name' => 'required|max:191',
                'kind' => 'required|in:base,derived',
                'source' => ['nullable', 'required_if:kind,base', Rule::in(array_keys(SumberData::FIELD))],
                'aggregate' => 'nullable|required_if:kind,base|in:count,sum,avg,min,max,count_distinct_date',
                'filter_expression' => 'nullable|max:2000',
                'value_expression' => 'nullable|max:500',
                'formula' => 'nullable|required_if:kind,derived|max:2000',
                'sort_order' => 'required|integer',
            ]), $ubahId ? $versi->variables()->findOrFail($ubahId) : null),
            'param' => $this->kelola->simpanParam($versi, $request->validate([
                'code' => $this->aturanKode('pay_params', $versi, $ubahId),
                'name' => 'required|max:191',
                'value' => 'required|decimal:0,4',
            ]), $ubahId ? $versi->params()->findOrFail($ubahId) : null),
            'item' => $this->kelola->simpanItem($versi, $request->validate([
                'component_id' => ['required', TenantRule::exists('pay_components'),
                    Rule::unique('pay_items', 'component_id')->where('master_version_id', $versi->id)->ignore($ubahId)],
                'code' => $this->aturanKode('pay_items', $versi, $ubahId),
                'calc_type' => 'required|in:contract,formula',
                'formula' => 'nullable|required_if:calc_type,formula|max:2000',
                'item_role' => 'required|in:normal,pph21',
                'sort_order' => 'required|integer',
            ]), $ubahId ? $versi->items()->findOrFail($ubahId) : null),
            default => abort(404),
        };

        return redirect()->route('pay.master.versi', $versi->id)->with('sukses', 'Tersimpan.');
    }

    public function hapusIsi(int $id, string $jenis, int $isi)
    {
        $versi = MasterVersion::findOrFail($id);
        $relasi = ['variabel' => 'variables', 'param' => 'params', 'item' => 'items'][$jenis] ?? abort(404);
        $this->kelola->hapus($versi, $versi->{$relasi}()->findOrFail($isi));

        return back()->with('sukses', 'Dihapus.');
    }

    public function terbitkan(int $id)
    {
        $this->kelola->terbitkan(MasterVersion::findOrFail($id));

        return back()->with('sukses', 'Versi diterbitkan dan menjadi versi aktif master ini.');
    }

    public function salin(int $id)
    {
        $baru = $this->kelola->salin(MasterVersion::findOrFail($id));

        return redirect()->route('pay.master.versi', $baru->id)->with('sukses', "Versi {$baru->version_no} (draft) dibuat.");
    }

    /** @return list<mixed> */
    private function aturanKode(string $tabel, MasterVersion $versi, ?int $ubahId): array
    {
        return ['required', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique($tabel, 'code')->where('master_version_id', $versi->id)->ignore($ubahId)];
    }

    /** @return array{0: ?HasilHitung, 1: ?string} */
    private function uji(MasterVersion $versi, int $employeeId, string $periode): array
    {
        $employee = Employee::find($employeeId);
        $contract = $employee ? Contract::where('employee_id', $employee->id)->active()->first() : null;
        if ($employee === null || $contract === null) {
            return [null, 'Karyawan tidak ditemukan atau tidak punya kontrak aktif.'];
        }
        try {
            $bulan = CarbonImmutable::createFromFormat('!Y-m', $periode);
            [$mulai, $selesai] = PeriodConfig::first()?->rentang($periode) ?? [$bulan->startOfMonth(), $bulan->endOfMonth()];

            return [(new Kalkulator($versi))->hitung($employee, $contract, $mulai, $selesai), null];
        } catch (RumusException|\InvalidArgumentException $e) {
            return [null, $e->getMessage()];
        }
    }
}
