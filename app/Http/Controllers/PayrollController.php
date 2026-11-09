<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\Payroll;
use App\Services\ApprovalService;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use RuntimeException;

class PayrollController extends Controller
{
    public function __construct(private PayrollService $svc, private ApprovalService $approval)
    {
    }

    /** Menu "Proses Payroll": selalu periode berjalan (lihat periodeTarget). */
    public function index(Request $request)
    {
        return $this->rekap($request);
    }

    /**
     * Periode yang sedang bisa diproses: periode di menu Periode yang belum di-generate (atau draft/ditolak).
     * Return [status, MasterPayroll|null]: 'siap' (sudah masuk masa periode), 'belum_masa' (periode berikutnya
     * belum mulai), atau 'kosong' (semua periode sudah diproses, perlu buat periode baru).
     */
    public static function periodeTarget(): array
    {
        $hariIni = now()->toDateString();
        $terbuka = \App\Models\MasterPayroll::with('payroll')->whereNotNull('tgl_mulai')->orderBy('tgl_mulai')->get()
            ->filter(fn ($m) => ! $m->payroll || $m->payroll->bisaDigenerate())->values();
        $siap = $terbuka->first(fn ($m) => $m->tgl_mulai->toDateString() <= $hariIni);
        if ($siap) {
            return ['siap', $siap];
        }
        $nanti = $terbuka->first();

        return $nanti ? ['belum_masa', $nanti] : ['kosong', null];
    }

    /** Riwayat lama digabung ke menu Riwayat Payroll. */
    public function riwayat()
    {
        return redirect()->route('laporan.index');
    }
    /** Langkah 1: rekap absensi/cuti/lembur periode berjalan untuk dicek, dengan filter seperti di Absensi. */
    public function rekap(Request $request)
    {
        [$keadaan, $master] = self::periodeTarget();
        $menunggu = Payroll::where('status', 'menunggu')->orderByDesc('periode')->get();
        $terakhir = Payroll::where('status', 'final')->orderByDesc('periode')->first();
        if ($keadaan !== 'siap') {
            return view('payroll.rekap', compact('keadaan', 'master', 'menunggu', 'terakhir') + ['periode' => $master?->periode]);
        }

        $periode = $master->periode;
        $rekap = $this->svc->rekap($periode);
        $payroll = Payroll::where('periode', $periode)->first();

        // perkiraan total (semua karyawan, sebelum filter)
        $estimasi = ['gp' => 0, 'lembur' => 0, 'pph' => 0, 'bersih' => 0];
        foreach ($rekap['baris'] as $b) {
            $bruto = 0; $potong = 0;
            foreach ($this->svc->hitungKaryawan($b['karyawan'], $b['rekap'], $rekap['master'], $rekap['akhir']) as $h) {
                $dasar = $h['c']->dasar_bayar;
                if (isset(['gaji_pokok' => 1, 'lembur' => 1, 'pajak' => 1][$dasar])) {
                    $estimasi[['gaji_pokok' => 'gp', 'lembur' => 'lembur', 'pajak' => 'pph'][$dasar]] += $h['nominal'];
                }
                $h['c']->jenis === 'pengurang' ? $potong += $h['nominal'] : $bruto += $h['nominal'];
            }
            $estimasi['bersih'] += $bruto - $potong;
        }

        // filter multi pilih: karyawan_id[], departemen_id[], kondisi[] (alfa, lembur, belum_lengkap)
        $ids = fn ($k) => collect((array) $request->input($k))->filter()->map(fn ($v) => (int) $v)->all();
        $fKaryawan = $ids('karyawan_id');
        $fDepartemen = $ids('departemen_id');
        $fKondisi = collect((array) $request->input('kondisi'))->intersect(['alfa', 'lembur', 'belum_lengkap'])->values()->all();
        $hkBerjalan = max(1, \App\Models\Leave::hariKerja($rekap['awal'], $rekap['akhir']->copy()->min(now()->startOfDay())));
        $semua = $rekap['baris'];
        $rekap['baris'] = $semua->filter(function ($b) use ($fKaryawan, $fDepartemen, $fKondisi, $hkBerjalan) {
            $r = $b['rekap'];
            if ($fKaryawan && ! in_array($b['karyawan']->id, $fKaryawan)) return false;
            if ($fDepartemen && ! in_array($b['karyawan']->departemen_id, $fDepartemen)) return false;
            foreach ($fKondisi as $k) {
                $cocok = match ($k) {
                    'alfa' => $r['alfa'] > 0,
                    'lembur' => $r['jam_lembur'] > 0,
                    'belum_lengkap' => ($r['hadir'] + $r['sakit'] + $r['izin'] + $r['cuti'] + $r['alfa']) < $hkBerjalan,
                };
                if (! $cocok) return false;
            }

            return true;
        })->values();
        $hal = max(1, $request->integer('page', 1));
        $rekap['baris'] = new \Illuminate\Pagination\LengthAwarePaginator($rekap['baris']->forPage($hal, 15)->values(), $rekap['baris']->count(), 15, $hal, ['path' => $request->url(), 'query' => $request->query()]);

        return view('payroll.rekap', $rekap + compact('keadaan', 'periode', 'payroll', 'estimasi', 'menunggu', 'terakhir', 'semua', 'fKaryawan', 'fDepartemen', 'fKondisi', 'hkBerjalan') + [
            'departemen' => \App\Models\Departemen::orderBy('nama')->get(),
        ]);
    }

    /** Langkah 2: generate payroll draft. */
    public function generate(Request $request)
    {
        $periode = $request->validate(['periode' => 'required|date_format:Y-m'])['periode'];
        [$keadaan, $target] = self::periodeTarget();
        if ($keadaan !== 'siap' || $target->periode !== $periode) {
            return redirect()->route('payroll.index')->with('gagal', $keadaan === 'belum_masa'
                ? 'Periode ' . nama_periode($target->periode) . ' belum dimulai. Generate bisa dilakukan mulai ' . tgl($target->tgl_mulai) . '.'
                : 'Periode ini sudah diproses. Buat periode baru di menu Periode dulu.');
        }

        // sesuai Figma: generate langsung dikirim ke Manager HR untuk disetujui
        try {
            $payroll = $this->svc->generate($periode);
            $otomatis = $this->ajukanAtauSetujui($payroll);
        } catch (RuntimeException $e) {
            return back()->with('gagal', $e->getMessage());
        }

        return redirect()->route('payroll.show', $payroll)->with('sukses', 'Payroll ' . $payroll->namaPeriode() . ' berhasil di-generate dan ' . ($otomatis ? 'langsung disetujui. Slip gaji sudah terbit.' : 'dikirim ke Manager HR.'));
    }

    public function show(Request $request, Payroll $payroll)
    {
        return redirect()->route('laporan.show', ['payroll' => $payroll] + $request->only('departemen_id'));
    }

    public function detailKaryawan(Payroll $payroll, Karyawan $karyawan)
    {
        $r = $this->svc->ringkasan($payroll)->firstWhere('karyawan.id', $karyawan->id);
        abort_unless($r, 404);
        [$awal, $akhir] = ($payroll->master ?? \App\Models\MasterPayroll::aktif())->rentang($payroll->periode);
        $rekap = $this->svc->rekapKaryawan($karyawan->id, $awal, $akhir);

        return view('payroll.detail-karyawan', compact('payroll', 'karyawan', 'r', 'rekap', 'awal', 'akhir'));
    }

    /** Langkah 3: ajukan ke Manager HR. */
    public function ajukan(Payroll $payroll)
    {
        try {
            $otomatis = $this->ajukanAtauSetujui($payroll);
        } catch (RuntimeException $e) {
            return back()->with('gagal', $e->getMessage());
        }

        return back()->with('sukses', $otomatis ? 'Payroll langsung disetujui. Slip gaji sudah terbit.' : 'Payroll diajukan ke Manager HR.');
    }

    /** Ajukan ke Manager HR. Kalau yang mengajukan Manager HR sendiri, langsung disetujui (auto approve). */
    private function ajukanAtauSetujui(Payroll $payroll): bool
    {
        $user = auth()->user();
        $approval = $this->approval->ajukanPayroll($payroll);
        if ($approval->approver_id !== $user->id) {
            return false;
        }
        $this->approval->setujui($approval, $user, 'Disetujui otomatis karena dibuat oleh Manager HR.');

        return true;
    }

    public function destroy(Payroll $payroll)
    {
        if (! $payroll->bisaDigenerate()) {
            return back()->with('gagal', 'Payroll yang sudah diajukan atau final tidak bisa dihapus.');
        }
        \App\Models\Approval::where('jenis_ref', 'payroll')->where('ref_id', $payroll->id)->delete();
        $payroll->delete();

        return redirect()->route('payroll.index')->with('sukses', 'Payroll draft dihapus.');
    }
}
