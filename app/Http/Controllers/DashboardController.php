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
        // multi-tenant: Super Admin (akun platform) memakai dashboard platform lintas perusahaan (AUDIT.md §37)
        if (app(\App\Domain\Company\Tenancy\CurrentCompany::class)->isSuperAdmin()) {
            return view('dashboard.super-admin', $this->superAdmin());
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
            Role::HR => $payrollBaru
                ? view('dashboard.hr-payroll-baru', $this->hrPayrollBaru() + $d)
                : view('dashboard.hr', $d + $this->hr($svc, $rentang)),
            Role::MANAGER => $payrollBaru
                ? view('dashboard.manager-payroll-baru', $this->managerPayrollBaru($user) + $d)
                : view('dashboard.manager', $d + $this->manager($user, $svc, $master, $rentang, $payrollBaru)),
            default => $payrollBaru
                ? view('dashboard.karyawan-payroll-baru', $this->karyawanPayrollBaru($user) + $d)
                : view('dashboard.karyawan', $d + $this->karyawan($user, $svc, $master, $awal, $akhir, $payrollBaru)),
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
     * Dashboard HR untuk perusahaan dengan payroll baru: seluruhnya dari modul baru (pay_*), periode cut off.
     * Tidak ada aturan perusahaan (alpha, potongan) di sini; itu dihitung master payroll.
     */
    private function hrPayrollBaru(): array
    {
        $hariIni = now()->toDateString();
        $periode = now()->format('Y-m');
        [$awal, $akhir] = \App\Domain\Payroll\KehadiranKaryawan::rentang($periode);
        $r = [$awal->toDateString(), $akhir->toDateString()];

        $aktif = Karyawan::where('is_active', true)->count();
        $hadirHariIni = \App\Domain\Payroll\Models\Attendance::where('attendance_date', $hariIni)->distinct()->count('employee_id');
        $leaveHariIni = \App\Domain\Payroll\Models\Leave::where('leave_date', $hariIni)->distinct()->count('employee_id');
        $lemburPeriode = \App\Domain\Payroll\Models\Overtime::whereBetween('overtime_date', $r)->get()
            ->reduce(fn ($n, $o) => bcadd($n, (string) $o->duration_hours, 2), '0');

        $kontrakAktif = Contract::where('is_active', true)->count();
        $kontrakHabis = Contract::where('is_active', true)->whereNotNull('end_date')
            ->whereBetween('end_date', [$hariIni, now()->addDays(30)->toDateString()])->count();
        $tanpaKontrak = Karyawan::where('is_active', true)->whereDoesntHave('kontrakPayrollAktif')->count();
        $run = PayrollRun::orderByDesc('period_end')->orderByDesc('id')->first();
        $status = [PayrollRun::DRAFT => 'Draft', PayrollRun::PENDING => 'Menunggu approval', PayrollRun::APPROVED => 'Disetujui, slip terbit', PayrollRun::REJECTED => 'Ditolak'];

        $terbaru = \App\Domain\Payroll\Models\Leave::with(['employee.departemen', 'leaveType'])->latest('id')->take(5)->get()
            ->map(fn ($l) => (object) ['karyawan' => $l->employee, 'jenis' => $l->leaveType?->name ?? 'Leave', 'waktu' => $l->created_at,
                'tanggal' => tgl($l->leave_date, 'j M'), 'sumber' => $l->import_batch_id ? 'Import CSV' : 'Input manual'])
            ->concat(\App\Domain\Payroll\Models\Overtime::with('employee.departemen')->latest('id')->take(5)->get()
                ->map(fn ($o) => (object) ['karyawan' => $o->employee, 'jenis' => 'Lembur ' . \App\Domain\Payroll\KehadiranKaryawan::angka((string) $o->duration_hours) . ' jam',
                    'waktu' => $o->created_at, 'tanggal' => tgl($o->overtime_date, 'j M'), 'sumber' => $o->import_batch_id ? 'Import CSV' : 'Input manual']))
            ->sortByDesc('waktu')->take(5)->values();

        $hadirPerKaryawan = \App\Domain\Payroll\Models\Attendance::whereBetween('attendance_date', $r)
            ->selectRaw('employee_id, count(*) as n')->groupBy('employee_id')->pluck('n', 'employee_id');
        $perDept = Departemen::orderBy('nama')->get()->map(function ($d) use ($hadirPerKaryawan) {
            $ids = Karyawan::where('is_active', true)->where('departemen_id', $d->id)->pluck('id');

            return (object) ['nama' => $d->nama, 'karyawan' => $ids->count(), 'hadir' => (int) $ids->sum(fn ($id) => $hadirPerKaryawan[$id] ?? 0)];
        });
        $maks = max((int) $perDept->max('hadir'), 1);

        $tahap = match ($run?->status) {
            PayrollRun::DRAFT, PayrollRun::REJECTED => 2,
            PayrollRun::PENDING => 3,
            PayrollRun::APPROVED => 5,
            default => 1,
        };

        return [
            'periode' => $periode, 'awal' => $awal, 'akhir' => $akhir,
            'stat' => [
                ['Total Karyawan', $aktif, Departemen::count() . ' departemen · ' . $tanpaKontrak . ' tanpa kontrak payroll'],
                ['Hadir Hari Ini', $hadirHariIni, ($aktif ? round($hadirHariIni / $aktif * 100) : 0) . '% · ' . $leaveHariIni . ' leave · lembur periode ' . \App\Domain\Payroll\KehadiranKaryawan::angka($lemburPeriode) . ' jam'],
                ['Kontrak Payroll Aktif', $kontrakAktif, $kontrakHabis ? "{$kontrakHabis} habis dalam 30 hari" : 'Tidak ada yang segera habis'],
                ['Payroll ' . ($run?->title ?? '-'), $run ? $status[$run->status] ?? $run->status : '-', $run ? tgl($run->period_start, 'j M') . ' – ' . tgl($run->period_end, 'j M Y') : 'Belum ada payroll'],
            ],
            'run' => $run,
            'tahap' => $tahap,
            'terbaru' => $terbaru,
            'perDept' => $perDept,
            'maks' => $maks,
            'pengingat' => array_values(array_filter([
                $tanpaKontrak ? ["{$tanpaKontrak} karyawan aktif belum punya kontrak payroll", 'Tidak ikut dihitung saat generate payroll'] : null,
                $kontrakHabis ? ["{$kontrakHabis} kontrak payroll habis dalam 30 hari", 'Perbarui di menu Kontrak Payroll'] : null,
                $run && $run->status === PayrollRun::PENDING ? ["Payroll {$run->title} menunggu approval", 'Approver: ' . ($run->approver?->namaTampil() ?? '-')] : null,
                $run && $run->status === PayrollRun::REJECTED ? ["Payroll {$run->title} ditolak", 'Buat payroll baru dari awal'] : null,
            ])),
        ];
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

    /**
     * Dashboard karyawan bila perusahaan memakai payroll baru: kehadiran dari tabel pay_*, periode cut off,
     * tanpa slip gaji (slip dilihat di menu Slip Gaji).
     */
    private function karyawanPayrollBaru(User $user): array
    {
        $k = $user->karyawan?->load('departemen');
        if (! $k) {
            return ['karyawan' => null];
        }
        $periode = now()->format('Y-m');
        [$awal, $akhir] = \App\Domain\Payroll\KehadiranKaryawan::rentang($periode);
        ['rekap' => $rekap] = \App\Domain\Payroll\KehadiranKaryawan::untuk($k->id, $awal, $akhir);
        $masa = $k->hitungMasaKerja();
        $jam = \App\Domain\Payroll\KehadiranKaryawan::angka($rekap['jam_lembur']);

        $riwayat = \App\Domain\Payroll\Models\Leave::with('leaveType')->where('employee_id', $k->id)->latest('leave_date')->take(5)->get()
            ->map(fn ($l) => (object) ['jenis' => $l->leaveType?->name ?? 'Leave', 'urut' => $l->leave_date, 'tanggal' => tgl($l->leave_date, 'j M Y'), 'ket' => $l->note])
            ->concat(\App\Domain\Payroll\Models\Overtime::where('employee_id', $k->id)->latest('overtime_date')->take(5)->get()
                ->map(fn ($o) => (object) ['jenis' => 'Lembur', 'urut' => $o->overtime_date, 'tanggal' => tgl($o->overtime_date, 'j M Y') . ' · ' . \App\Domain\Payroll\KehadiranKaryawan::angka((string) $o->duration_hours) . ' jam', 'ket' => $o->note]))
            ->sortByDesc('urut')->take(5)->values();

        return [
            'karyawan' => $k,
            'periode' => $periode, 'awal' => $awal, 'akhir' => $akhir,
            'rekap' => $rekap,
            'stat' => [
                ['Kehadiran ' . nama_periode($periode), $rekap['hadir'] . ' hari', $rekap['terlambat'] . ' kali terlambat'],
                ['Leave ' . nama_periode($periode), $rekap['leave'] . ' hari', $rekap['leave'] ? collect($rekap['leave_per_tipe'])->map(fn ($n, $t) => "$n $t")->implode(' · ') : 'Belum ada leave'],
                ['Lembur ' . nama_periode($periode), $jam . ' jam', bccomp($rekap['jam_lembur'], '0', 2) > 0 ? 'Dihitung saat payroll' : 'Belum ada lembur'],
                ['Masa Kerja', intdiv($masa, 12) . ' th ' . ($masa % 12) . ' bln', $k->tgl_masuk ? 'Sejak ' . tgl($k->tgl_masuk, 'j M Y') : '-'],
            ],
            'riwayat' => $riwayat,
        ];
    }

    /**
     * Dashboard manager bila perusahaan memakai payroll baru: approval payroll (pay_runs) yang menunggu user ini,
     * kehadiran & lembur tim dari tabel pay_* periode cut off. Tanpa nominal gaji.
     */
    private function managerPayrollBaru(User $user): array
    {
        $ids = $user->karyawanIds(); // null = semua karyawan (Manager HR)
        $tim = Karyawan::where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->when($user->karyawan_id, fn ($q, $id) => $q->where('id', '!=', $id))->pluck('id');
        $dept = Departemen::dikelola($user->karyawan_id)->first();
        $periode = now()->format('Y-m');
        [$awal, $akhir] = \App\Domain\Payroll\KehadiranKaryawan::rentang($periode);
        $r = [$awal->toDateString(), $akhir->toDateString()];
        $hariIni = now()->toDateString();

        $menunggu = \App\Domain\Payroll\Models\PayrollRun::with('creator.karyawan')->where('approver_user_id', $user->id)
            ->where('status', \App\Domain\Payroll\Models\PayrollRun::PENDING)->orderByDesc('id')->get();

        $lembur = \App\Domain\Payroll\Models\Overtime::with('employee')->whereIn('employee_id', $tim)->whereBetween('overtime_date', $r)->get()
            ->groupBy('employee_id')->map(fn ($rows) => (object) [
                'karyawan' => $rows->first()->employee,
                'jam' => $rows->reduce(fn ($n, $o) => bcadd($n, (string) $o->duration_hours, 2), '0'),
                'hari' => $rows->count(),
            ])->sortByDesc(fn ($x) => (float) $x->jam)->values();
        $jamLembur = $lembur->reduce(fn ($n, $x) => bcadd($n, $x->jam, 2), '0');

        $hadirPeriode = \App\Domain\Payroll\Models\Attendance::whereIn('employee_id', $tim)->whereBetween('attendance_date', $r)->count();
        $leavePeriode = \App\Domain\Payroll\Models\Leave::whereIn('employee_id', $tim)->whereBetween('leave_date', $r)->count();
        $hadirHariIni = \App\Domain\Payroll\Models\Attendance::whereIn('employee_id', $tim)->where('attendance_date', $hariIni)->distinct()->count('employee_id');
        $leaveHariIni = \App\Domain\Payroll\Models\Leave::with('leaveType')->whereIn('employee_id', $tim)->where('leave_date', $hariIni)->get()
            ->countBy(fn ($l) => $l->leaveType?->name ?? 'Leave');
        $hariIniBar = collect([['Hadir', $hadirHariIni, '#16a34a']])
            ->concat($leaveHariIni->map(fn ($n, $t) => [$t, $n, '#0284c7'])->values())
            ->push(['Belum ada catatan', max($tim->count() - $hadirHariIni - $leaveHariIni->sum(), 0), '#94a3b8'])->all();

        return [
            'dept' => $dept,
            'awal' => $awal, 'akhir' => $akhir,
            'stat' => [
                ['Menunggu Persetujuan', $menunggu->count(), $menunggu->count() ? 'Payroll menunggu approval kamu' : 'Tidak ada payroll menunggu'],
                ['Anggota Tim', $tim->count(), $hadirHariIni . ' hadir · ' . $leaveHariIni->sum() . ' leave hari ini'],
                ['Lembur Periode Ini', \App\Domain\Payroll\KehadiranKaryawan::angka($jamLembur) . ' jam', $lembur->count() . ' orang lembur'],
                ['Kehadiran Tim', $hadirPeriode . ' hari hadir', $leavePeriode . ' hari leave · ' . tgl($awal, 'j M') . ' – ' . tgl($akhir, 'j M')],
            ],
            'menunggu' => $menunggu->take(5),
            'lembur' => $lembur,
            'hariIniBar' => $hariIniBar,
            'jumlahTim' => max($tim->count(), 1),
        ];
    }

    /**
     * Dashboard Super Admin: ringkasan platform lintas perusahaan. Sengaja memakai DB::table dengan
     * agregat per company_id (bukan model ber-scope): halaman platform, hanya angka ringkasan, tanpa data pribadi.
     */
    private function superAdmin(): array
    {
        $perTabel = fn (string $tabel, ?\Closure $filter = null) => \Illuminate\Support\Facades\DB::table($tabel)->whereNotNull('company_id')
            ->when($filter, $filter)->groupBy('company_id')->selectRaw('company_id, count(*) as n')->pluck('n', 'company_id');

        $karyawan = $perTabel('karyawan', fn ($q) => $q->where('is_active', true));
        $user = $perTabel('users', fn ($q) => $q->where('is_active', true));
        $kontrak = $perTabel('pay_contracts', fn ($q) => $q->where('is_active', true));
        $admin = \Illuminate\Support\Facades\DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereNotNull('users.company_id')->where('roles.nama', Role::ADMIN)->where('users.is_active', true)
            ->groupBy('users.company_id')->selectRaw('users.company_id, count(*) as n')->pluck('n', 'company_id');
        $runTerakhir = PayrollRun::withoutGlobalScope(\App\Domain\Company\Tenancy\CompanyScope::class)
            ->orderByDesc('period_end')->orderByDesc('id')->get(['company_id', 'title', 'status', 'period_end'])->unique('company_id')->keyBy('company_id');
        $menunggu = \Illuminate\Support\Facades\DB::table('pay_runs')->where('status', PayrollRun::PENDING)->count();

        $perusahaan = \App\Domain\Company\Models\Company::orderBy('code')->get()->map(fn ($c) => (object) [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'aktif' => $c->is_active,
            'karyawan' => (int) ($karyawan[$c->id] ?? 0), 'user' => (int) ($user[$c->id] ?? 0),
            'kontrak' => (int) ($kontrak[$c->id] ?? 0), 'admin' => (int) ($admin[$c->id] ?? 0),
            'run' => $runTerakhir[$c->id] ?? null,
        ]);

        // slip gaji terbit per bulan periode (6 bulan terakhir, semua perusahaan)
        $bulan = collect(range(5, 0))->map(fn ($n) => now()->startOfMonth()->subMonthsNoOverflow($n));
        $slip = \Illuminate\Support\Facades\DB::table('pay_payslips')->join('pay_run_employees', 'pay_run_employees.id', '=', 'pay_payslips.run_employee_id')
            ->join('pay_runs', 'pay_runs.id', '=', 'pay_run_employees.run_id')
            ->where('pay_runs.status', PayrollRun::APPROVED)
            ->whereBetween('pay_runs.period_end', [$bulan->first()->toDateString(), now()->endOfMonth()->toDateString()])
            ->get(['pay_runs.period_end'])->countBy(fn ($r) => substr((string) $r->period_end, 0, 7));

        $perRole = \Illuminate\Support\Facades\DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereNotNull('users.company_id')->where('users.is_active', true)
            ->groupBy('roles.nama')->selectRaw('roles.nama, count(*) as n')->orderByDesc('n')->pluck('n', 'nama');

        $tanpaAdmin = $perusahaan->where('aktif', true)->where('admin', 0);

        return [
            'stat' => [
                ['Perusahaan Terdaftar', $perusahaan->count(), $perusahaan->where('aktif', true)->count() . ' aktif · ' . $perusahaan->where('aktif', false)->count() . ' nonaktif'],
                ['User Aktif', $perusahaan->sum('user'), $perRole->count() . ' jenis role di semua perusahaan'],
                ['Karyawan Dikelola', $perusahaan->sum('karyawan'), $perusahaan->sum('kontrak') . ' dengan kontrak payroll aktif'],
                ['Payroll Menunggu Approval', $menunggu, 'Slip terbit bulan ini: ' . ($slip[now()->format('Y-m')] ?? 0)],
            ],
            'perusahaan' => $perusahaan,
            'grafikKaryawan' => ['label' => $perusahaan->pluck('code')->all(), 'data' => $perusahaan->pluck('karyawan')->all()],
            'grafikSlip' => ['label' => $bulan->map(fn ($b) => $b->translatedFormat('M Y'))->all(), 'data' => $bulan->map(fn ($b) => (int) ($slip[$b->format('Y-m')] ?? 0))->all()],
            'perRole' => $perRole,
            'perhatian' => array_values(array_filter([
                $tanpaAdmin->isNotEmpty() ? [$tanpaAdmin->count() . ' perusahaan aktif tanpa akun Admin', $tanpaAdmin->pluck('code')->implode(', '), route('perusahaan.index', ['admin' => $tanpaAdmin->first()->id])] : null,
                ($k = $perusahaan->filter(fn ($p) => $p->karyawan > $p->kontrak)->count()) ? ["{$k} perusahaan punya karyawan tanpa kontrak payroll", 'Karyawan itu tidak ikut dihitung payroll', null] : null,
                $menunggu ? ["{$menunggu} payroll menunggu approval", 'Disetujui approver di masing-masing perusahaan', null] : null,
            ])),
        ];
    }
}
