<?php

namespace App\Http\Controllers;

use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\ModulPayroll;
use App\Models\Approval;
use App\Models\Attendance;
use App\Models\Departemen;
use App\Models\Karyawan;
use App\Models\Kontrak;
use App\Models\Leave;
use App\Models\MasterPayroll;
use App\Models\Menu;
use App\Models\Overtime;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Http\Request;

/** Dashboard per role, isinya mengikuti layar Dashboard di Figma. */
class DashboardController extends Controller
{
    public function index(Request $request, PayrollService $svc)
    {
        $user = $request->user();
        // multi-tenant: Super Admin (akun platform) tidak punya dashboard perusahaan
        if (app(\App\Domain\Company\Tenancy\CurrentCompany::class)->isSuperAdmin()) {
            return redirect()->route('perusahaan.index');
        }
        Kontrak::sinkronAktif();
        $master = MasterPayroll::aktif();
        $periode = now()->format('Y-m');
        [$awal, $akhir] = $master->rentang($periode);
        $payrollBaru = ModulPayroll::aktif(); // kontrak & payroll dari modul baru (AUDIT.md §29)
        $d = compact('master', 'periode', 'awal', 'akhir', 'payrollBaru');
        $rentang = [$awal->toDateString(), $akhir->toDateString()];

        return match ($user->role->nama) {
            Role::ADMIN => view('dashboard.admin', $d + $this->admin()),
            Role::HR => view('dashboard.hr', $d + ($payrollBaru ? $this->hrPayrollBaru($this->hr($svc, $rentang)) : $this->hr($svc, $rentang))),
            Role::MANAGER => view('dashboard.manager', $d + $this->manager($user, $svc, $master, $rentang, $payrollBaru)),
            default => view('dashboard.karyawan', $d + $this->karyawan($user, $svc, $master, $awal, $akhir, $payrollBaru)),
        };
    }

    private function admin(): array
    {
        $tanpaAkun = Karyawan::where('is_active', true)->doesntHave('user')->count();
        $terkunci = User::where('is_locked', true)->count();
        $nonaktif = User::where('is_active', false)->count();
        $roleTanpaMenu = Role::whereDoesntHave('privileges', fn ($q) => $q->where('can_view', true))->count();

        return [
            'stat' => [
                ['Pengguna Aktif', User::where('is_active', true)->count(), 'dari ' . User::count() . ' akun terdaftar'],
                ['Role', Role::count(), Role::orderBy('id')->pluck('nama')->join(', ')],
                ['Menu', Menu::whereNotNull('url')->untukPerusahaan()->count(), 'dipakai oleh ' . Role::count() . ' role'],
                ['Akun Nonaktif', $nonaktif, 'Dinonaktifkan · tidak bisa login'],
            ],
            'log' => \App\Models\LogAktivitas::with('user.role', 'user.karyawan')->latest('created_at')->latest('id')->take(5)->get(),
            'perhatian' => [
                ['Karyawan baru tanpa akun', 'Ditambahkan HR, perlu dibuatkan akun', $tanpaAkun ? "{$tanpaAkun} menunggu" : 'Tidak ada', $tanpaAkun ? 'warning' : 'success', route('users.create')],
                ['Akun terkunci', 'Gagal login 5x, perlu buka kunci / reset sandi', $terkunci ? "{$terkunci} akun" : 'Tidak ada', $terkunci ? 'danger' : 'success', route('users.index')],
                ['Privilege role', $roleTanpaMenu ? 'Ada role yang belum punya akses menu' : 'Semua role sudah punya akses menu', $roleTanpaMenu ? "{$roleTanpaMenu} role" : 'Lengkap', $roleTanpaMenu ? 'warning' : 'success', route('roles.index')],
                ['Akun nonaktif', 'Karyawan resign atau kontrak habis', "{$nonaktif} akun", 'neutral', route('users.index')],
            ],
        ];
    }

    private function hr(PayrollService $svc, array $rentang): array
    {
        $hariIni = now()->toDateString();
        $aktif = Karyawan::where('is_active', true)->count();
        $absenHariIni = Attendance::where('tanggal', $hariIni)->get()->countBy('status');
        $hadirHariIni = ($absenHariIni['hadir'] ?? 0) + ($absenHariIni['terlambat'] ?? 0);
        $leaveHariIni = Leave::where('tgl_mulai', '<=', $hariIni)->where('tgl_selesai', '>=', $hariIni)->get()->countBy('jenis');

        // payroll bulan lalu (periode terakhir yang biasanya sedang diproses)
        $payroll = Payroll::orderByDesc('periode')->first();
        $totalPayroll = $payroll ? $svc->ringkasan($payroll)->sum('thp') : 0;
        $statusPayroll = [
            'draft' => 'Draft, belum diajukan', 'menunggu' => 'Menunggu approval Manager HR',
            'ditolak' => 'Ditolak, perlu revisi', 'final' => 'Final, slip sudah terbit',
        ];

        // input cuti & lembur terbaru
        $terbaru = Leave::with('karyawan.departemen')->latest('id')->take(5)->get()->map(fn ($l) => (object) [
            'karyawan' => $l->karyawan, 'jenis' => ucfirst($l->jenis), 'waktu' => $l->created_at,
            'tanggal' => tgl($l->tgl_mulai, 'j M') . ($l->tgl_selesai->ne($l->tgl_mulai) ? '–' . tgl($l->tgl_selesai, 'j M') : ''),
            'oleh' => $l->sumber_input === 'import' ? 'Import Excel' : 'HR', 'peran' => 'HR',
        ])->concat(Overtime::with(['karyawan.departemen', 'penginput.karyawan', 'penginput.role'])->latest('id')->take(5)->get()->map(fn ($o) => (object) [
            'karyawan' => $o->karyawan, 'jenis' => 'Lembur', 'waktu' => $o->created_at, 'tanggal' => tgl($o->tanggal, 'j M'),
            'oleh' => $o->penginput?->namaTampil() ?? '-', 'peran' => $o->penginput?->karyawan?->jabatan ?? $o->penginput?->role?->nama,
        ]))->sortByDesc('waktu')->take(5);

        // kehadiran per departemen di periode berjalan
        $perDept = Departemen::orderBy('nama')->get()->map(function ($d) use ($rentang) {
            $absen = Attendance::whereBetween('tanggal', $rentang)->whereHas('karyawan', fn ($q) => $q->where('departemen_id', $d->id))->get();
            $hadir = $absen->whereIn('status', ['hadir', 'terlambat'])->count();

            return (object) ['nama' => $d->nama, 'persen' => $absen->count() ? round($hadir / $absen->count() * 100) : 0];
        });

        $alfa = Attendance::whereBetween('tanggal', $rentang)->where('status', 'alfa')->distinct('karyawan_id')->count('karyawan_id');
        $kontrakMenunggu = Kontrak::where('status_approval', 'menunggu')->count();
        $kontrakHabis = Kontrak::where('is_active', true)->whereNotNull('tgl_akhir')
            ->whereBetween('tgl_akhir', [$hariIni, now()->addDays(30)->toDateString()])->count();

        return [
            'stat' => [
                ['Total Karyawan', $aktif, Departemen::count() . ' departemen · ' . Karyawan::where('created_at', '>=', now()->startOfMonth())->count() . ' karyawan baru'],
                ['Hadir Hari Ini', $hadirHariIni, ($aktif ? round($hadirHariIni / $aktif * 100) : 0) . '% · ' . ($leaveHariIni['sakit'] ?? 0) . ' sakit, ' . ($leaveHariIni['cuti'] ?? 0) . ' cuti, ' . ($absenHariIni['alfa'] ?? 0) . ' alfa'],
                ['Kontrak Menunggu Approval', $kontrakMenunggu, 'Menunggu Manager divisi'],
                ['Payroll ' . ($payroll ? nama_periode($payroll->periode) : '-'), $payroll ? 'Rp ' . jt($totalPayroll) : '-', $payroll ? $statusPayroll[$payroll->status] : 'Belum ada payroll'],
            ],
            'payroll' => $payroll,
            'terbaru' => $terbaru,
            'perDept' => $perDept,
            'pengingat' => array_values(array_filter([
                $alfa ? ["{$alfa} karyawan alfa periode ini", 'Potongan gaji pokok dihitung GP ÷ hari kerja'] : null,
                $kontrakMenunggu ? ["{$kontrakMenunggu} kontrak menunggu approval Manager", 'Kontrak baru aktif setelah disetujui'] : null,
                $kontrakHabis ? ["{$kontrakHabis} kontrak habis dalam 30 hari", 'Siapkan perpanjangan kontrak'] : null,
                $payroll && $payroll->status === 'ditolak' ? ['Payroll ' . $payroll->namaPeriode() . ' ditolak', 'Perbaiki data lalu generate ulang'] : null,
            ])),
        ];
    }

    /**
     * Dashboard HR untuk perusahaan dengan payroll baru: kartu kontrak & payroll dari modul baru,
     * bagian lain (karyawan, kehadiran, cuti/lembur) tetap seperti dashboard lama.
     */
    private function hrPayrollBaru(array $d): array
    {
        $hariIni = now()->toDateString();
        $kontrakAktif = Contract::where('is_active', true)->count();
        $kontrakHabis = Contract::where('is_active', true)->whereNotNull('end_date')
            ->whereBetween('end_date', [$hariIni, now()->addDays(30)->toDateString()])->count();
        $run = PayrollRun::orderByDesc('period_end')->orderByDesc('id')->first();
        $status = [PayrollRun::DRAFT => 'Draft', PayrollRun::PENDING => 'Menunggu approval', PayrollRun::APPROVED => 'Disetujui, slip terbit', PayrollRun::REJECTED => 'Ditolak'];

        $d['stat'][2] = ['Kontrak Payroll Aktif', $kontrakAktif, $kontrakHabis ? "{$kontrakHabis} habis dalam 30 hari" : 'Tidak ada yang segera habis'];
        $d['stat'][3] = ['Payroll ' . ($run?->title ?? '-'), $run ? $status[$run->status] ?? $run->status : '-', $run ? tgl($run->period_start, 'j M') . ' – ' . tgl($run->period_end, 'j M Y') : 'Belum ada payroll'];
        $d['payroll'] = null; // progres payroll lama tidak dipakai
        // pengingat lama yang masih berlaku hanya alfa (absensi); kontrak & payroll diganti modul baru
        $alfa = array_values(array_filter($d['pengingat'], fn ($p) => str_contains($p[0], 'alfa')));
        $d['pengingat'] = array_values(array_filter([
            $alfa[0] ?? null,
            $kontrakHabis ? ["{$kontrakHabis} kontrak payroll habis dalam 30 hari", 'Perbarui di menu Kontrak Payroll'] : null,
            $run && $run->status === PayrollRun::REJECTED ? ["Payroll {$run->title} ditolak", 'Buat payroll baru dari awal'] : null,
        ]));

        return $d;
    }

    private function manager(User $user, PayrollService $svc, MasterPayroll $master, array $rentang, bool $payrollBaru = false): array
    {
        $timIds = collect($user->karyawanIds())->reject(fn ($id) => $id === $user->karyawan_id)->values();
        $dept = Departemen::dikelola($user->karyawan_id)->first();
        $hariIni = now()->toDateString();

        $approvals = Approval::where('approver_id', $user->id)->where('status', 'menunggu')->latest('id')->get();

        // lembur tim periode berjalan + estimasi upah (1/173 x GP x pengali)
        $lembur = Overtime::with('karyawan.kontrakAktif', 'penginput.role')->whereIn('karyawan_id', $timIds)->whereBetween('tanggal', $rentang)->get()
            ->groupBy('karyawan_id')->map(function ($rows) use ($master) {
                $k = $rows->first()->karyawan;
                $gp = (float) ($k->kontrakAktif?->gaji_pokok ?? 0);

                return (object) [
                    'karyawan' => $k,
                    'jam' => $rows->sum('durasi'),
                    'upah' => $rows->sum(fn ($o) => $o->hitungUpah($gp / max($master->pembagi_lembur, 1), $master)),
                    'sumber' => $rows->contains(fn ($o) => $o->penginput?->isRole('HR')) ? 'Diinput HR' : 'Diinput Manager',
                ];
            })->sortByDesc('jam')->values();

        $absenPeriode = Attendance::whereIn('karyawan_id', $timIds)->whereBetween('tanggal', $rentang)->get();
        $persen = $absenPeriode->count() ? round($absenPeriode->whereIn('status', ['hadir', 'terlambat'])->count() / $absenPeriode->count() * 100) : 0;

        $absenHariIni = Attendance::whereIn('karyawan_id', $timIds)->where('tanggal', $hariIni)->get()->countBy('status');
        $leaveHariIni = Leave::whereIn('karyawan_id', $timIds)->where('tgl_mulai', '<=', $hariIni)->where('tgl_selesai', '>=', $hariIni)->get()->countBy('jenis');
        $hariIniBar = [
            ['Hadir', ($absenHariIni['hadir'] ?? 0) + ($absenHariIni['terlambat'] ?? 0), '#16a34a'],
            ['Sakit', $leaveHariIni['sakit'] ?? 0, '#d97706'],
            ['Izin', $leaveHariIni['izin'] ?? 0, '#0284c7'],
            ['Cuti', $leaveHariIni['cuti'] ?? 0, '#1b6fa3'],
            ['Alfa', $absenHariIni['alfa'] ?? 0, '#dc2626'],
        ];

        return [
            'dept' => $dept,
            'stat' => [
                ['Menunggu Persetujuan', $approvals->count(), $approvals->countBy('jenis_ref')->map(fn ($n, $j) => "{$n} {$j}")->join(' · ') ?: 'Tidak ada pengajuan'],
                ['Anggota Tim', $timIds->count(), ($hariIniBar[0][1]) . ' hadir · ' . ($leaveHariIni['cuti'] ?? 0) . ' cuti hari ini'],
                ['Lembur Periode Ini', rtrim(rtrim(number_format($lembur->sum('jam'), 1, ',', '.'), '0'), ',') . ' jam', $user->bolehLihatGaji() && ! $payrollBaru ? 'Estimasi ' . rupiah($lembur->sum('upah')) : $lembur->count() . ' orang lembur'],
                ['Kehadiran Tim', $persen . '%', 'Rata-rata ' . tgl($rentang[0], 'j M') . ' – ' . tgl($rentang[1], 'j M')],
            ],
            'approvals' => $approvals->take(5),
            'lembur' => $lembur,
            'hariIniBar' => $hariIniBar,
            'jumlahTim' => max($timIds->count(), 1),
        ];
    }

    private function karyawan(User $user, PayrollService $svc, MasterPayroll $master, $awal, $akhir, bool $payrollBaru = false): array
    {
        $k = $user->karyawan?->load('departemen', 'kontrakAktif');
        if (! $k) {
            return ['karyawan' => null];
        }
        $slip = $payrollBaru ? null : Payslip::with('payroll')->where('karyawan_id', $k->id)
            ->whereHas('payroll', fn ($q) => $q->where('status', 'final'))
            ->join('payroll', 'payroll.id', '=', 'payslip.payroll_id')->orderByDesc('payroll.periode')->select('payslip.*')->first();
        $rekap = $svc->rekapKaryawan($k->id, $awal, $akhir);
        $gp = (float) ($k->kontrakAktif?->gaji_pokok ?? 0);
        $upahLembur = $rekap['lembur']->sum(fn ($o) => $o->hitungUpah($gp / max($master->pembagi_lembur, 1), $master));
        $masa = $k->hitungMasaKerja();

        $riwayat = Leave::where('karyawan_id', $k->id)->latest('tgl_mulai')->take(5)->get()->map(fn ($l) => (object) [
            'jenis' => ucfirst($l->jenis), 'urut' => $l->tgl_mulai,
            'tanggal' => tgl($l->tgl_mulai, 'j M Y') . ($l->tgl_selesai->ne($l->tgl_mulai) ? ' – ' . tgl($l->tgl_selesai, 'j M') : '') . ' (' . $l->jumlahHari() . ' hari)',
            'ket' => $l->keterangan, 'sumber' => $l->sumber_input === 'import' ? 'Import HR' : 'Diinput HR',
        ])->concat(Overtime::with('penginput.role')->where('karyawan_id', $k->id)->latest('tanggal')->take(5)->get()->map(fn ($o) => (object) [
            'jenis' => 'Lembur', 'urut' => $o->tanggal,
            'tanggal' => tgl($o->tanggal, 'j M Y') . ' · ' . substr($o->jam_mulai, 0, 5) . '–' . substr($o->jam_selesai, 0, 5),
            'ket' => $o->keterangan, 'sumber' => 'Diinput ' . ($o->penginput?->isRole('Manager') ? 'Manager' : 'HR'),
        ]))->sortByDesc('urut')->take(5);

        return [
            'karyawan' => $k,
            'slip' => $slip,
            'slipDetail' => $slip ? $slip->details() : collect(),
            'rekap' => $rekap,
            'stat' => [
                ['Kehadiran ' . tgl($akhir, 'F'), $rekap['hadir'] . '/' . MasterPayroll::hariKerja(now()->format('Y-m')) . ' hari', $rekap['sakit'] . ' sakit · ' . $rekap['cuti'] . ' cuti · ' . $rekap['alfa'] . ' alfa'],
                ['Sisa Cuti Tahunan', $k->sisa_cuti . ' hari', 'Jatah 12 hari per tahun'],
                ['Lembur ' . tgl($akhir, 'F'), rtrim(rtrim(number_format($rekap['jam_lembur'], 1, ',', '.'), '0'), ',') . ' jam', $rekap['jam_lembur'] ? ($payrollBaru ? 'Dihitung saat payroll' : rupiah($upahLembur) . ' · estimasi') : 'Belum ada lembur'],
                ['Masa Kerja', intdiv($masa, 12) . ' th ' . ($masa % 12) . ' bln', $masa >= 12 ? 'Berhak THR 1× gaji pokok' : 'THR prorata ' . $masa . '/12'],
            ],
            'riwayat' => $riwayat,
        ];
    }
}
