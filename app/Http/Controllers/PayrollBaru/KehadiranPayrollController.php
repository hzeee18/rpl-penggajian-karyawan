<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Payroll\Actions\SimpanKehadiran;
use App\Domain\Payroll\Import\JenisKehadiran;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\LeaveType;
use App\Domain\Payroll\Models\PeriodConfig;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Absensi / Leave / Lembur payroll (input manual). Jenis dari route default 'jenis'. */
final class KehadiranPayrollController extends Controller
{
    public function index(Request $request)
    {
        $j = $this->jenis($request);
        $model = SimpanKehadiran::model($j);
        $kolomTanggal = $this->kolomTanggal($j);
        $periode = $request->input('periode', now()->format('Y-m'));
        [$awal, $akhir] = $this->rentang($periode);

        $data = $model::with(array_filter(['employee', $j === JenisKehadiran::Leave ? 'leaveType' : null]))
            ->whereBetween($kolomTanggal, [$awal->toDateString(), $akhir->toDateString()])
            ->when($request->integer('karyawan'), fn ($q, $id) => $q->where('employee_id', $id))
            ->orderByDesc($kolomTanggal)->orderBy('id')->paginate(20)->withQueryString();

        return view('payroll-baru.kehadiran', [
            'j' => $j, 'data' => $data, 'periode' => $periode, 'awal' => $awal, 'akhir' => $akhir,
            'ubah' => $request->integer('ubah') ? $model::with('employee')->find($request->integer('ubah')) : null,
            'baru' => $request->has('baru'),
            'karyawan' => Employee::where('is_active', true)->orderBy('nama')->get(['id', 'nik', 'nama']),
            'tipeLeave' => LeaveType::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, SimpanKehadiran $simpan)
    {
        $j = $this->jenis($request);
        $hasil = $simpan->simpan($j, $this->payload($request, $j));

        return back()->with('sukses', count($hasil).' data '.strtolower($j->label()).' disimpan.');
    }

    public function update(Request $request, int $id, SimpanKehadiran $simpan)
    {
        $j = $this->jenis($request);
        $simpan->simpan($j, $this->payload($request, $j), SimpanKehadiran::model($j)::findOrFail($id));

        return back()->with('sukses', 'Data diperbarui.');
    }

    public function destroy(Request $request, int $id, SimpanKehadiran $simpan)
    {
        $j = $this->jenis($request);
        $simpan->hapus(SimpanKehadiran::model($j)::findOrFail($id));

        return back()->with('sukses', 'Data dihapus.');
    }

    /** Jenis dari default route (bukan parameter method: default route ditambahkan setelah parameter URL). */
    private function jenis(Request $request): JenisKehadiran
    {
        return JenisKehadiran::dariSlug((string) $request->route('jenis'));
    }

    /** @return array<string, string> payload dengan kolom CSV yang sama */
    private function payload(Request $request, JenisKehadiran $j): array
    {
        return array_map(fn ($v) => (string) $v, $request->only($j->kolom()) + array_fill_keys($j->kolom(), ''));
    }

    private function kolomTanggal(JenisKehadiran $j): string
    {
        return match ($j) {
            JenisKehadiran::Absensi => 'attendance_date',
            JenisKehadiran::Leave => 'leave_date',
            JenisKehadiran::Lembur => 'overtime_date',
        };
    }

    /** Rentang filter: cut off perusahaan bila sudah diatur, selain itu bulan kalender. */
    private function rentang(string $periode): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $periode)) {
            $periode = now()->format('Y-m');
        }
        $config = PeriodConfig::first();
        if ($config !== null) {
            try {
                return $config->rentang($periode);
            } catch (\InvalidArgumentException) {
            }
        }
        $bulan = CarbonImmutable::createFromFormat('!Y-m', $periode);

        return [$bulan->startOfMonth(), $bulan->endOfMonth()];
    }
}
