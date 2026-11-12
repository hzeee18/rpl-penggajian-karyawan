<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Karyawan;
use App\Models\Leave;
use App\Models\MasterPayroll;
use App\Models\Overtime;
use App\Services\PayrollService;
use App\Support\Ekspor;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Absensi, Cuti & Lembur. Tampilannya beda per role (sesuai Figma):
 * - HR: input harian per tanggal (tabel semua karyawan + panel "Ubah Data Harian")
 * - Manager: "Absensi Tim", rekap per anggota tim + ringkasan
 * - Karyawan: riwayat harian miliknya + rekap
 */
class AttendanceController extends Controller
{
    public function index(Request $request, PayrollService $svc)
    {
        $user = $request->user();
        if ($user->isRole('Admin') || $user->actsAsHr()) {
            return $request->tab === 'harian' ? $this->harianHr($request) : $this->semuaHr($request);
        }
        if ($user->isManagerTim()) {
            return $this->rekapTim($request, $svc);
        }

        return $this->riwayatSaya($request, $svc);
    }

    /** Status satu karyawan di satu tanggal: cuti/izin/sakit menang atas absensi. */
    public static function statusHari(int $karyawanId, string $tanggal): array
    {
        $leave = Leave::where('karyawan_id', $karyawanId)->where('tgl_mulai', '<=', $tanggal)->where('tgl_selesai', '>=', $tanggal)->first();
        $absen = Attendance::where('karyawan_id', $karyawanId)->where('tanggal', $tanggal)->first();
        $lembur = Overtime::where('karyawan_id', $karyawanId)->where('tanggal', $tanggal)->get();

        return [
            'status' => $leave?->jenis ?? $absen?->status,
            'leave' => $leave, 'absen' => $absen, 'lembur' => $lembur,
            'keterangan' => $leave?->keterangan ?? $lembur->first()?->keterangan,
        ];
    }

    /** HR: satu tabel absensi + cuti/izin/sakit + lembur, dengan filter rentang tanggal. */
    private function semuaHr(Request $request)
    {
        [$periode, $awalDef, $akhirDef] = $this->rentangFilter($request);
        $dari = Carbon::parse($request->input('dari') ?: $awalDef)->toDateString();
        $sampai = Carbon::parse($request->input('sampai') ?: $akhirDef)->toDateString();
        // filter multi pilih: karyawan_id[], departemen_id[], jenis[]
        $ids = fn ($k) => collect((array) $request->input($k))->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $kid = $ids('karyawan_id');
        $did = $ids('departemen_id');
        $jenis = collect((array) $request->input('jenis'))->filter()->intersect(['hadir', 'terlambat', 'alfa', 'cuti', 'izin', 'sakit', 'lembur'])->values()->all();
        $mau = fn (array $j) => ! $jenis || array_intersect($jenis, $j);
        $filterK = fn ($q) => $q->when($kid, fn ($q) => $q->whereIn('karyawan_id', $kid))
            ->when($did, fn ($q) => $q->whereHas('karyawan', fn ($q) => $q->whereIn('departemen_id', $did)));

        $rows = collect();
        if ($mau(['hadir', 'terlambat', 'alfa'])) {
            $filterK(Attendance::with('karyawan.departemen')->whereBetween('tanggal', [$dari, $sampai]))
                ->when($jenis, fn ($q) => $q->whereIn('status', array_intersect($jenis, ['hadir', 'terlambat', 'alfa'])))->get()
                ->each(fn ($a) => $rows->push((object) ['tipe' => 'absensi', 'id' => $a->id, 'tanggal' => $a->tanggal, 'karyawan' => $a->karyawan, 'status' => $a->status,
                    'detail' => $a->jam_masuk ? substr($a->jam_masuk, 0, 5) . '–' . substr((string) $a->jam_keluar, 0, 5) : '-', 'ket' => null, 'oleh' => 'HR', 'model' => $a]));
        }
        if ($mau(['cuti', 'izin', 'sakit'])) {
            $filterK(Leave::with('karyawan.departemen')->where('tgl_mulai', '<=', $sampai)->where('tgl_selesai', '>=', $dari))
                ->when($jenis, fn ($q) => $q->whereIn('jenis', array_intersect($jenis, ['cuti', 'izin', 'sakit'])))->get()
                ->each(fn ($l) => $rows->push((object) ['tipe' => 'cuti', 'id' => $l->id, 'tanggal' => $l->tgl_mulai, 'karyawan' => $l->karyawan, 'status' => $l->jenis,
                    'detail' => tgl($l->tgl_mulai, 'j M') . ($l->tgl_selesai->ne($l->tgl_mulai) ? '–' . tgl($l->tgl_selesai, 'j M') : '') . ' · ' . $l->jumlahHari() . ' hari', 'ket' => $l->keterangan,
                    'oleh' => $l->sumber_input === 'import' ? 'Import Excel' : 'HR', 'model' => $l]));
        }
        if ($mau(['lembur'])) {
            $filterK(Overtime::with(['karyawan.departemen', 'penginput.karyawan', 'penginput.role'])->whereBetween('tanggal', [$dari, $sampai]))->get()
                ->each(fn ($o) => $rows->push((object) ['tipe' => 'lembur', 'id' => $o->id, 'tanggal' => $o->tanggal, 'karyawan' => $o->karyawan, 'status' => 'lembur',
                    'detail' => substr($o->jam_mulai, 0, 5) . '–' . substr($o->jam_selesai, 0, 5) . ' · ' . ($o->durasi + 0) . ' jam', 'ket' => $o->keterangan,
                    'oleh' => $o->penginput?->namaTampil() ?? '-', 'model' => $o]));
        }
        $rows = $rows->sortByDesc(fn ($r) => $r->tanggal->toDateString() . str_pad((string) $r->karyawan->nik, 10, '0', STR_PAD_LEFT))->values();
        $hal = max(1, $request->integer('page', 1));
        $data = new \Illuminate\Pagination\LengthAwarePaginator($rows->forPage($hal, 15)->values(), $rows->count(), 15, $hal, ['path' => $request->url(), 'query' => $request->query()]);

        // drawer ubah: ?ubah=tipe-id
        $ubah = null;
        if ($request->ubah && preg_match('/^(absensi|cuti|lembur)-(\d+)$/', $request->ubah, $m)) {
            $ubah = (object) ['tipe' => $m[1], 'model' => ['absensi' => Attendance::class, 'cuti' => Leave::class, 'lembur' => Overtime::class][$m[1]]::find($m[2])];
        }
        $hitung = $rows->countBy(fn ($r) => in_array($r->status, ['hadir', 'terlambat']) ? 'hadir' : $r->status);

        return view('absensi.semua', [
            'data' => $data, 'dari' => $dari, 'sampai' => $sampai, 'ubah' => $ubah, 'fKaryawan' => $kid, 'fDepartemen' => $did, 'fJenis' => $jenis, 'rentangDefault' => [Carbon::parse($awalDef)->toDateString(), Carbon::parse($akhirDef)->toDateString()], 'hitung' => $hitung, 'total' => $rows->count(),
            'karyawan' => Karyawan::where('is_active', true)->orderBy('nama')->get(),
            'departemen' => \App\Models\Departemen::orderBy('nama')->get(),
            'berhakLembur' => (\App\Domain\Payroll\ModulPayroll::aktif()
                ? Karyawan::whereHas('kontrakPayrollAktif') // payroll baru: cukup kontrak payroll aktif (AUDIT.md §29)
                : Karyawan::whereHas('kontrakAktif.komponen', fn ($q) => $q->where('is_active', true)
                    ->whereHas('component', fn ($q) => $q->where('dasar_bayar', 'lembur'))))->pluck('id')->all(),
        ]);
    }

    private function harianHr(Request $request)
    {
        $tanggal = Carbon::parse($request->input('tanggal') ?? Attendance::where('tanggal', '<=', now()->toDateString())->max('tanggal') ?? now()->toDateString());
        $karyawan = Karyawan::with('departemen')->where('is_active', true)
            ->when($request->q, fn ($q, $s) => $q->where(fn ($q) => $q->where('nama', 'like', "%$s%")->orWhere('nik', 'like', "%$s%")))
            ->orderBy('nik')->paginate(10)->withQueryString();

        $baris = $karyawan->getCollection()->map(fn ($k) => (object) (['karyawan' => $k] + self::statusHari($k->id, $tanggal->toDateString())));
        $pilih = $baris->first(fn ($b) => $b->karyawan->id === $request->integer('ubah')) ?? $baris->first();

        return view('absensi.harian', compact('tanggal', 'karyawan', 'baris', 'pilih'));
    }

    /** Simpan panel "Ubah Data Harian": status kehadiran, jam lembur, keterangan. */
    public function simpanHarian(Request $request)
    {
        $data = $request->validate([
            'karyawan_id' => ['required', \App\Domain\Company\Tenancy\TenantRule::exists('karyawan')],
            'tanggal' => 'required|date',
            'status' => 'nullable|in:hadir,terlambat,alfa,sakit,izin,cuti',
            'jam_masuk' => 'nullable|date_format:H:i',
            'lembur_mulai' => 'nullable|required_with:lembur_selesai|date_format:H:i',
            'lembur_selesai' => 'nullable|required_with:lembur_mulai|date_format:H:i|different:lembur_mulai',
            'keterangan' => 'nullable|max:255',
        ]);
        $this->pastikanTidakTerkunci($data['tanggal']);
        $k = Karyawan::findOrFail($data['karyawan_id']);
        $tgl = Carbon::parse($data['tanggal'])->toDateString();
        $now = self::statusHari($k->id, $tgl);

        DB::transaction(function () use ($data, $k, $tgl, $now) {
            $status = $data['status'] ?? null;
            $leave = $now['leave'];

            // cuti beberapa hari: status diubah lewat menu Cuti
            if ($leave && $leave->tgl_mulai->ne($leave->tgl_selesai) && $status !== $leave->jenis) {
                throw ValidationException::withMessages(['status' => ucfirst($leave->jenis) . ' ini berlaku ' . tgl($leave->tgl_mulai, 'j M') . '–' . tgl($leave->tgl_selesai, 'j M') . '. Ubah lewat daftar Cuti, Izin & Sakit.']);
            }

            if (in_array($status, ['sakit', 'izin', 'cuti'])) {
                Attendance::where('karyawan_id', $k->id)->where('tanggal', $tgl)->delete();
                if ($leave && $leave->jenis !== $status) {
                    $leave->kembalikanSisaCuti();
                    $leave->delete();
                    $leave = null;
                }
                if ($status === 'cuti' && ! $leave && $k->fresh()->sisa_cuti < 1) {
                    throw ValidationException::withMessages(['status' => "Sisa cuti {$k->nama} tidak cukup."]);
                }
                $leave ??= tap(Leave::create(['karyawan_id' => $k->id, 'jenis' => $status, 'tgl_mulai' => $tgl, 'tgl_selesai' => $tgl, 'sumber_input' => 'manual']), fn ($l) => $l->kurangiSisaCuti());
                $leave->update(['keterangan' => $data['keterangan'] ?? null]);
            } else {
                if ($leave) {
                    $leave->kembalikanSisaCuti();
                    $leave->delete();
                }
                if ($status) {
                    $absen = $now['absen'] ?? new Attendance(['karyawan_id' => $k->id, 'tanggal' => $tgl]);
                    $masuk = $data['jam_masuk'] ?? ($absen->jam_masuk ? substr($absen->jam_masuk, 0, 5) : null) ?? ($status === 'terlambat' ? '08:30' : '08:00');
                    $absen->fill([
                        'status' => $status,
                        'jam_masuk' => $status === 'alfa' ? null : $masuk,
                        'jam_keluar' => $status === 'alfa' ? null : ($absen->jam_keluar ?? '17:00'),
                    ])->save();
                } else {
                    Attendance::where('karyawan_id', $k->id)->where('tanggal', $tgl)->delete();
                }
            }

            // lembur hari itu
            $lembur = $now['lembur']->first();
            if (! empty($data['lembur_mulai'])) {
                if (! $k->berhakLembur()) {
                    throw ValidationException::withMessages(['lembur_mulai' => "{$k->nama} tidak berhak lembur (tidak ada komponen lembur di kontraknya)."]);
                }
                $isi = ['jam_mulai' => $data['lembur_mulai'], 'jam_selesai' => $data['lembur_selesai'],
                    'durasi' => Overtime::hitungDurasi($data['lembur_mulai'], $data['lembur_selesai']),
                    'keterangan' => in_array($status, ['sakit', 'izin', 'cuti']) ? null : ($data['keterangan'] ?? null)];
                $lembur ? $lembur->update($isi) : Overtime::create($isi + ['karyawan_id' => $k->id, 'tanggal' => $tgl, 'diinput_oleh' => auth()->id()]);
            } elseif ($lembur) {
                Overtime::where('karyawan_id', $k->id)->where('tanggal', $tgl)->delete();
            }
        });

        return redirect()->route('absensi.index', ['tab' => 'harian', 'tanggal' => $tgl])->with('sukses', 'Data harian ' . $k->nama . ' disimpan.');
    }

    private function rekapTim(Request $request, PayrollService $svc)
    {
        [$periode, $awal, $akhir] = $this->rentangFilter($request);
        $user = $request->user();
        $tim = Karyawan::whereIn('id', $user->karyawanIds())->where('id', '!=', $user->karyawan_id)
            ->when($request->q, fn ($q, $s) => $q->where('nama', 'like', "%$s%"))->orderBy('nik')->get();
        $baris = $tim->map(fn ($k) => (object) ['karyawan' => $k, 'rekap' => $svc->rekapKaryawan($k->id, $awal, $akhir)]);

        if ($request->export) {
            return Ekspor::excel('absensi-tim-' . $periode, 'Absensi Tim ' . nama_periode($periode), ['NIK', 'Nama', 'Hadir', 'Terlambat', 'Sakit', 'Izin', 'Cuti', 'Alfa', 'Lembur (jam)'],
                $baris->map(fn ($b) => [$b->karyawan->nik, $b->karyawan->nama, $b->rekap['hadir'], $b->rekap['terlambat'], $b->rekap['sakit'], $b->rekap['izin'], $b->rekap['cuti'], $b->rekap['alfa'], $b->rekap['jam_lembur']]));
        }

        // hari kerja yang sudah berjalan di periode ini (untuk persen kehadiran yang adil)
        $hk = Leave::hariKerja($awal, $akhir->copy()->min(now()->startOfDay()));
        $pilih = $baris->first(fn ($b) => $b->karyawan->id === $request->integer('lihat'));
        $harianPilih = $pilih ? $this->timeline($pilih->karyawan->id, $awal, $akhir) : null;

        return view('absensi.tim', compact('periode', 'awal', 'akhir', 'baris', 'hk', 'pilih', 'harianPilih'));
    }

    private function riwayatSaya(Request $request, PayrollService $svc)
    {
        [$periode, $awal, $akhir] = $this->rentangFilter($request);
        $k = $request->user()->karyawan;
        abort_unless($k, 404);
        $semua = $this->timeline($k->id, $awal, $akhir);
        $filter = $request->input('filter');
        $baris = $semua->filter(fn ($r) => match ($filter) {
            'hadir' => in_array($r->status, ['hadir', 'terlambat']),
            'sakit' => in_array($r->status, ['sakit', 'izin']),
            'cuti' => $r->status === 'cuti',
            'lembur' => $r->lembur > 0,
            default => true,
        })->values();

        return view('absensi.saya', [
            'periode' => $periode, 'baris' => $baris, 'jumlah' => $semua->count(),
            'rekap' => $svc->rekapKaryawan($k->id, $awal, $akhir), 'hk' => Leave::hariKerja($awal, $akhir->copy()->min(now()->startOfDay())), 'karyawan' => $k,
        ]);
    }

    /** Riwayat harian satu karyawan (terbaru dulu): status, keterangan, lembur. */
    private function timeline(int $karyawanId, Carbon $awal, Carbon $akhir)
    {
        $sampai = $akhir->copy()->min(now()->startOfDay());
        $absen = Attendance::where('karyawan_id', $karyawanId)->whereBetween('tanggal', [$awal->toDateString(), $sampai->toDateString()])->get()->keyBy(fn ($a) => $a->tanggal->toDateString());
        $leaves = Leave::where('karyawan_id', $karyawanId)->where('tgl_mulai', '<=', $sampai->toDateString())->where('tgl_selesai', '>=', $awal->toDateString())->get();
        $lembur = Overtime::with('penginput.role')->where('karyawan_id', $karyawanId)->whereBetween('tanggal', [$awal->toDateString(), $sampai->toDateString()])->get()->groupBy(fn ($o) => $o->tanggal->toDateString());

        $hasil = collect();
        if ($sampai->lt($awal)) {
            return $hasil;
        }
        foreach (CarbonPeriod::create($awal, $sampai) as $d) {
            $t = $d->toDateString();
            $leave = $leaves->first(fn ($l) => $t >= $l->tgl_mulai->toDateString() && $t <= $l->tgl_selesai->toDateString() && ! $d->isWeekend());
            $a = $absen[$t] ?? null;
            $o = $lembur[$t] ?? collect();
            if (! $leave && ! $a && $o->isEmpty()) {
                continue;
            }
            $status = $leave?->jenis ?? $a?->status;
            $ket = $leave?->keterangan ?? ($o->first()?->keterangan) ?? match ($status) {
                'alfa' => 'Tanpa keterangan', 'terlambat' => 'Terlambat masuk (' . substr($a->jam_masuk, 0, 5) . ')', default => ucfirst((string) $status),
            };
            $oleh = $o->first()?->penginput?->isRole('Manager') ? 'Manager' : 'HR';
            $hasil->push((object) ['tanggal' => $d->copy(), 'status' => $status, 'keterangan' => $ket, 'lembur' => (float) $o->sum('durasi'), 'oleh' => $oleh]);
        }

        return $hasil->reverse()->values();
    }

    // ===== Form lengkap (tetap ada untuk input dari menu lain) =====

    public function create(Request $request)
    {
        return view('absensi.form', ['attendance' => new Attendance(['tanggal' => now(), 'status' => 'hadir', 'jam_masuk' => '08:00', 'jam_keluar' => '17:00']), 'karyawan' => $this->pilihanKaryawan($request)]);
    }

    public function store(Request $request)
    {
        $data = $this->validasi($request);
        Attendance::create($data);

        return $this->kembali('Absensi disimpan.');
    }

    public function edit(Request $request, Attendance $attendance)
    {
        return view('absensi.form', ['attendance' => $attendance, 'karyawan' => $this->pilihanKaryawan($request)]);
    }

    public function update(Request $request, Attendance $attendance)
    {
        $this->pastikanTidakTerkunci($attendance->tanggal);
        $attendance->update($this->validasi($request, $attendance));

        return $this->kembali('Absensi diubah.');
    }

    public function destroy(Attendance $attendance)
    {
        $this->pastikanTidakTerkunci($attendance->tanggal);
        $attendance->delete();

        return back()->with('sukses', 'Absensi dihapus.');
    }

    private function validasi(Request $request, ?Attendance $a = null): array
    {
        $data = $request->validate([
            'karyawan_id' => ['required', \App\Domain\Company\Tenancy\TenantRule::exists('karyawan')],
            'tanggal' => ['required', 'date', \App\Domain\Company\Tenancy\TenantRule::unique('attendance')->where('karyawan_id', $request->karyawan_id)->ignore($a)],
            'status' => 'required|in:' . implode(',', array_keys(Attendance::STATUS)),
            'jam_masuk' => 'nullable|required_unless:status,alfa|date_format:H:i',
            'jam_keluar' => 'nullable|date_format:H:i',
        ], ['tanggal.unique' => 'Absensi karyawan ini di tanggal tersebut sudah ada.']);
        $this->pastikanLingkup($request, $data['karyawan_id']);
        $this->pastikanTidakTerkunci($data['tanggal']);
        if ($data['status'] === 'alfa') {
            $data['jam_masuk'] = $data['jam_keluar'] = null;
        }

        return $data;
    }
}
