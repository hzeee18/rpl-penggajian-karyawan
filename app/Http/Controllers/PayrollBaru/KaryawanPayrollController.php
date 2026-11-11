<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Audit\AuditLogger;
use App\Domain\Company\Tenancy\TenantRule;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Position;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data karyawan yang khusus dipakai payroll baru: jabatan (master), tanggal resign, NPWP.
 * Data inti (nama, NIK, departemen, rekening, PTKP) tetap dikelola menu Data Karyawan lama.
 */
final class KaryawanPayrollController extends Controller
{
    public function index(Request $request)
    {
        $karyawan = Employee::with(['position', 'departemen', 'pajak'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('nama', 'like', "%{$s}%")->orWhere('nik', 'like', "%{$s}%")))
            ->orderBy('nik')->paginate(15)->withQueryString();
        $ubah = $request->integer('ubah') ? Employee::with('position')->find($request->integer('ubah')) : null;

        return view('payroll-baru.karyawan', [
            'karyawan' => $karyawan,
            'ubah' => $ubah,
            'jabatan' => Position::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, int $id, AuditLogger $audit)
    {
        $employee = Employee::findOrFail($id);
        $data = $request->validate([
            'position_id' => ['nullable', TenantRule::exists('pay_positions')],
            'resign_date' => 'nullable|date',
            'has_npwp' => 'nullable|boolean',
            'npwp' => ['nullable', 'regex:/^[0-9.\-]{15,20}$/'],
        ]);
        $data['has_npwp'] = (bool) ($data['has_npwp'] ?? false);
        if (blank($data['npwp'] ?? null)) {
            unset($data['npwp']); // kosong = tidak diubah (NPWP tidak pernah ditampilkan ulang)
        }

        DB::transaction(function () use ($employee, $data, $audit) {
            $employee->fill($data)->save();
            $audit->logChanges('updated', $employee); // npwp otomatis tidak dicatat (AuditLogger)
        });

        return redirect()->route('pay.karyawan.index', ['ubah' => $employee->id] + $request->only('page', 'q'))
            ->with('sukses', 'Data payroll karyawan disimpan.');
    }
}
