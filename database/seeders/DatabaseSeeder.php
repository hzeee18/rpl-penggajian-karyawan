<?php

namespace Database\Seeders;

use App\Models\ApprovalMapping;
use App\Models\Attendance;
use App\Models\Departemen;
use App\Models\Karyawan;
use App\Models\Kontrak;
use App\Models\KontrakKomponen;
use App\Models\Leave;
use App\Models\MasterPayroll;
use App\Models\Menu;
use App\Models\Overtime;
use App\Models\Pajak;
use App\Models\PayslipComponent;
use App\Models\Privilege;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Multi-tenant: seluruh data contoh milik perusahaan DEFAULT (console tidak punya user login). */
    public function run(): void
    {
        $companyId = (int) \App\Domain\Company\Models\Company::where('code', \App\Domain\Company\Models\Company::DEFAULT_CODE)->value('id');

        app(\App\Domain\Company\Tenancy\CurrentCompany::class)->runAs($companyId, fn () => $this->isi());
    }

    private function isi(): void
    {
        $this->call(TarifTerSeeder::class);

        // ===== Role =====
        $roles = collect([Role::ADMIN, Role::HR, Role::MANAGER, Role::KARYAWAN])
            ->mapWithKeys(fn ($n) => [$n => Role::create(['nama' => $n])]);

        // ===== Menu + hak akses =====
        // [nama, url, icon, akses per role: v=view c=create u=update d=delete a=approve]
        // Urutan & nama menu mengikuti sidebar di Figma (ikon = nama ikon Lucide)
        $struktur = [
            ['Dashboard', 'dashboard', 'layout-grid', ['Admin' => 'v', 'HR' => 'v', 'Manager' => 'v', 'Karyawan' => 'v']],
            ['Manajemen User', 'users', 'users', ['Admin' => 'vcud']],
            ['Role & Privilege', 'roles', 'shield', ['Admin' => 'vcud']],
            ['Manajemen Menu', 'menus', 'layers', ['Admin' => 'vcud']],
            ['Approval', 'approval', 'circle-check', ['Manager' => 'va']],
            ['Profil & Kontrak', 'profil', 'user', ['HR' => 'v', 'Manager' => 'v', 'Karyawan' => 'v']],
            ['Data Karyawan', 'karyawan', 'users', ['HR' => 'vcud', 'Manager' => 'v']],
            ['Kontrak', 'kontrak', 'file-text', ['HR' => 'vcud']],
            ['Absensi', 'absensi', 'calendar', ['HR' => 'vcud', 'Manager' => 'v', 'Karyawan' => 'v']],
            ['Cuti, Izin, Sakit', 'cuti', 'calendar-x', ['HR' => 'vcud', 'Manager' => 'v', 'Karyawan' => 'v']],
            ['Lembur', 'lembur', 'clock', ['HR' => 'vcud', 'Manager' => 'vcud', 'Karyawan' => 'v']],
            ['Import Excel', 'import', 'upload', ['HR' => 'vc']],
            ['Proses Payroll', 'payroll', 'wallet', ['HR' => 'vcud']],
            ['Riwayat Payroll', 'laporan', 'history', ['HR' => 'v']],
            ['Slip Gaji', 'slip', 'file-text', ['HR' => 'v', 'Manager' => 'v', 'Karyawan' => 'v']],
            ['Master Data', null, 'database', [], [
                ['Departemen', 'departemen', 'building-2', ['HR' => 'vcud']],
                ['Pajak & Tarif TER', 'pajak', 'percent', ['HR' => 'vcud']],
                ['Komponen Gaji', 'komponen', 'layers', ['HR' => 'vcud']],
                ['Periode', 'master-payroll', 'sliders-horizontal', ['HR' => 'vcud']],
            ]],
        ];
        $huruf = ['v' => 'can_view', 'c' => 'can_create', 'u' => 'can_update', 'd' => 'can_delete', 'a' => 'can_approve'];
        $buat = function ($item, $parentId, $urutan) use (&$buat, $roles, $huruf) {
            $menu = Menu::create(['nama' => $item[0], 'url' => $item[1], 'icon' => $item[2], 'parent_id' => $parentId, 'urutan' => $urutan]);
            if ($menu->url) {
                foreach ($roles as $nama => $role) {
                    $kode = $item[3][$nama] ?? '';
                    Privilege::create(['role_id' => $role->id, 'menu_id' => $menu->id]
                        + collect($huruf)->mapWithKeys(fn ($kolom, $h) => [$kolom => str_contains($kode, $h)])->all());
                }
            }
            foreach ($item[4] ?? [] as $i => $child) {
                $buat($child, $menu->id, $i + 1);
            }
        };
        foreach ($struktur as $i => $item) {
            $buat($item, null, $i + 1);
        }

        // ===== Komponen gaji (sesuai layar Komponen Gaji di Figma) =====
        $rumus = [
            'UM' => 'GP * 15% / HK * (HADIR + SAKIT + IZIN + CUTI)', 'UT' => 'GP * 15% / HK * HADIR',
            'LB' => 'GP / 173 * (JAM_LEMBUR_1 * 1.5 + JAM_LEMBUR_LANJUT * 2)',
            'THR' => 'IF(MASA_KERJA >= 1, GP * MIN(MASA_KERJA, 12) / 12, 0)', 'ALF' => 'GP / HK * ALFA',
        ];
        $k = fn ($kode, $nama, $jenis, $tipe, $nilai, $dasar, $pajak, $per = null, $status = 'aktif') => PayslipComponent::create([
            'kode' => $kode, 'nama' => $nama, 'jenis' => $jenis, 'tipe_nilai' => $tipe, 'nilai_default' => $nilai,
            'dasar_bayar' => $dasar, 'rumus' => $rumus[$kode] ?? null, 'dihitung_per' => $per, 'status_berlaku' => $status, 'kena_pajak' => $pajak,
        ]);
        $k('GP', 'Gaji Pokok', 'penambah', 'nominal', 0, 'gaji_pokok', true);
        $makan = $k('UM', 'Uang Makan', 'penambah', 'persen', 15, 'harian', true, 'hadir,sakit,izin,cuti');
        $transport = $k('UT', 'Uang Transport', 'penambah', 'persen', 15, 'harian', true, 'hadir');
        $lembur = $k('LB', 'Lembur', 'penambah', 'nominal', 0, 'lembur', true);
        $thr = $k('THR', 'THR', 'penambah', 'nominal', 1, 'thr', true, null, 'nonaktif');
        $k('ALF', 'Potongan Alfa', 'pengurang', 'nominal', 0, 'alfa', false);
        $k('PPH', 'PPh 21', 'pengurang', 'nominal', 0, 'pajak', false);

        MasterPayroll::create(['tgl_cutoff' => 25, 'hari_kerja' => 22, 'pembagi_lembur' => 173, 'pengali_jam1' => 1.5, 'pengali_jam_berikut' => 2]);
        foreach ([4, 3, 2, 1, 0] as $mundur) {
            MasterPayroll::untuk(now()->subMonthsNoOverflow($mundur)->format('Y-m')); // Juli s/d periode berjalan
        }
        ApprovalMapping::create(['jenis_ref' => 'kontrak', 'level' => 1, 'tipe_approver' => 'manager_divisi']);
        ApprovalMapping::create(['jenis_ref' => 'payroll', 'level' => 1, 'tipe_approver' => 'manager_hr']);

        $this->dataContoh($roles, $makan, $transport, $lembur, $thr);
    }

    /**
     * Data contoh: 6 departemen, ±120 karyawan, absensi/cuti/lembur Juli s/d hari ini,
     * payroll Juli–Oktober final (slip terbit), November draft, 1 kontrak menunggu approval.
     */
    private function dataContoh($roles, $makan, $transport, $lembur, $thr): void
    {
        mt_srand(2026);
        $faker = \Faker\Factory::create('id_ID');
        $faker->seed(2026);
        $ptkp = Pajak::pluck('id', 'status_ptkp');
        $statusPtkp = ['TK/0', 'TK/0', 'TK/0', 'TK/1', 'K/0', 'K/1', 'K/1', 'K/2', 'K/3', 'TK/2'];

        $dept = collect([
            ['HRD', 'Human Resource'], ['IT', 'Teknologi Informasi'], ['KEU', 'Keuangan'],
            ['OPS', 'Operasional'], ['GDG', 'Gudang'], ['MKT', 'Marketing'],
        ])->mapWithKeys(fn ($d) => [$d[0] => Departemen::create(['kode' => $d[0], 'nama' => $d[1]])]);

        // [kode dept, jabatan staf (+gaji dasar), jumlah staf]
        $staf = [
            'HRD' => [['Staf HR', 6800000], ['Staf Rekrutmen', 6500000], ['Staf Payroll', 7200000]],
            'IT' => [['Programmer', 8500000], ['UI/UX Designer', 7800000], ['System Analyst', 9500000], ['IT Support', 6200000]],
            'KEU' => [['Akuntan', 7600000], ['Staf Pajak', 7200000], ['Kasir', 5600000]],
            'OPS' => [['Staf Lapangan', 5200000], ['Supervisor Operasional', 8200000], ['Teknisi', 5800000]],
            'GDG' => [['Staf Gudang', 5000000], ['Admin Gudang', 5400000], ['Kepala Shift Gudang', 6800000]],
            'MKT' => [['Sales Executive', 6000000], ['Digital Marketing', 6800000], ['Account Manager', 8800000]],
        ];
        $jumlah = ['HRD' => 10, 'IT' => 20, 'KEU' => 14, 'OPS' => 30, 'GDG' => 26, 'MKT' => 20];
        $managerGaji = ['HRD' => 13500000, 'IT' => 16000000, 'KEU' => 15000000, 'OPS' => 14500000, 'GDG' => 12500000, 'MKT' => 15500000];

        // akun tetap untuk demo (username => [nama, dept, jabatan, ptkp, role])
        $akunDemo = [
            'manager.hr' => ['Siti Rahmawati', 'HRD', 'Manager HR', 'K/1', 'Manager'],
            'manager.hr2' => ['Bagus Wicaksono', 'HRD', 'Manager HR', 'K/2', 'Manager'],
            'hr' => ['Dewi Lestari', 'HRD', 'Staf HR', 'TK/0', 'HR'],
            'manager.it' => ['Andi Pratama', 'IT', 'Manager IT', 'K/2', 'Manager'],
            'rizky' => ['Rizky Maulana', 'IT', 'Programmer', 'TK/0', 'Karyawan'],
            'nadia' => ['Nadia Putri', 'IT', 'UI/UX Designer', 'TK/1', 'Karyawan'],
            'manager.keu' => ['Bambang Hartono', 'KEU', 'Manager Keuangan', 'K/3', 'Manager'],
            'fitri' => ['Fitri Handayani', 'KEU', 'Akuntan', 'K/0', 'Karyawan'],
            'manager.ops' => ['Budi Santoso', 'OPS', 'Manager Operasional', 'K/1', 'Manager'],
            'joko' => ['Joko Susilo', 'OPS', 'Supervisor Operasional', 'K/1', 'Karyawan'],
            'dewi.a' => ['Dewi Anggraini', 'OPS', 'Staf Lapangan', 'TK/0', 'Karyawan'],
            'manager.gdg' => ['Teguh Prasetyo', 'GDG', 'Manager Gudang', 'K/2', 'Manager'],
            'agus' => ['Agus Setiawan', 'GDG', 'Staf Gudang', 'TK/0', 'Karyawan'],
            'manager.mkt' => ['Andi Firmansyah', 'MKT', 'Manager Marketing', 'K/1', 'Manager'],
        ];

        User::create(['role_id' => $roles['Admin']->id, 'username' => 'admin', 'password' => 'password', 'email' => 'admin@perusahaan.test']);
        $nik = 0;
        $buatKaryawan = function ($nama, $kodeDept, $jabatan, $status, $gaji, $isManager, $username = null, $role = null) use (&$nik, $dept, $ptkp, $roles, $makan, $transport, $lembur, $thr, $faker) {
            $nik++;
            $masuk = $isManager ? now()->subYears(mt_rand(4, 8))->subMonths(mt_rand(0, 11))->startOfMonth() : now()->subMonths(mt_rand(3, 72))->startOfMonth();
            $kar = Karyawan::create([
                'nik' => 'K' . str_pad($nik, 4, '0', STR_PAD_LEFT), 'nama' => $nama, 'jenis_kelamin' => mt_rand(0, 1) ? 'L' : 'P',
                'departemen_id' => $dept[$kodeDept]->id, 'jabatan' => $jabatan, 'tgl_masuk' => $masuk, 'pajak_id' => $ptkp[$status],
                'status_kepegawaian' => $isManager ? 'tetap' : (mt_rand(0, 3) ? 'kontrak' : 'tetap'),
                'email' => ($username ?? \Illuminate\Support\Str::slug($nama, '.')) . '@perusahaan.test',
                'telepon' => '08' . mt_rand(1100000000, 1999999999), 'alamat' => $faker->city,
                'nama_bank' => ['BCA', 'Mandiri', 'BNI', 'BRI'][mt_rand(0, 3)], 'no_rekening' => (string) mt_rand(1000000000, 9999999999),
                'sisa_cuti' => mt_rand(6, 12),
            ]);
            if ($isManager) {
                $dept[$kodeDept]->managers()->syncWithoutDetaching([$kar->id]);
                $dept[$kodeDept]->manager_id ?? $dept[$kodeDept]->update(['manager_id' => $kar->id]);
            }
            if ($username) {
                User::create(['role_id' => $roles[$role]->id, 'karyawan_id' => $kar->id, 'username' => $username, 'password' => 'password', 'email' => $kar->email]);
            }
            $pkwtt = $isManager || $kar->status_kepegawaian === 'tetap';
            $akhir = $pkwtt ? null : (mt_rand(0, 9) === 0 ? now()->addDays(mt_rand(5, 28)) : now()->addMonths(mt_rand(3, 14))->endOfMonth());
            $mulai = max($masuk->copy(), now()->subYears(2)->startOfYear());
            // riwayat: karyawan lama punya kontrak PKWT sebelumnya (sudah berakhir) sebelum kontrak yang berlaku sekarang
            $sebelum = null;
            if ($masuk->lt($mulai)) {
                $sebelum = Kontrak::create([
                    'karyawan_id' => $kar->id, 'jenis' => 'PKWT', 'tgl_mulai' => $masuk->copy(), 'tgl_akhir' => $mulai->copy()->subDay(),
                    'gaji_pokok' => round($gaji * 0.9, -5), 'status_approval' => 'disetujui', 'is_active' => false,
                ]);
            }
            $kontrak = Kontrak::create([
                'karyawan_id' => $kar->id, 'kontrak_sebelumnya_id' => $sebelum?->id, 'jenis' => $pkwtt ? 'PKWTT' : 'PKWT',
                'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir,
                'gaji_pokok' => $gaji, 'status_approval' => 'disetujui', 'is_active' => true,
            ]);
            // staf lapangan/gudang/operasional/IT dapat lembur; manager & kantor (HRD, KEU, MKT) tidak
            $pasang = [$makan, $transport, $thr];
            if (! $isManager && in_array($kodeDept, ['IT', 'OPS', 'GDG'])) {
                $pasang[] = $lembur;
            }
            foreach ($pasang as $c) {
                KontrakKomponen::create(['kontrak_id' => $kontrak->id, 'payslip_component_id' => $c->id, 'tipe_nilai' => $c->tipe_nilai, 'nilai' => $c->nilai_default]);
            }

            return $kar;
        };

        // manager & akun demo dulu, lalu staf acak sampai jumlah per departemen terpenuhi
        $isi = array_fill_keys(array_keys($jumlah), 0);
        foreach ($akunDemo as $u => [$nama, $kd, $jab, $st, $role]) {
            $mgr = str_starts_with($u, 'manager.');
            $gaji = $mgr ? $managerGaji[$kd] : (collect($staf[$kd])->firstWhere(0, $jab)[1] ?? 6000000) + mt_rand(0, 8) * 100000;
            $buatKaryawan($nama, $kd, $jab, $st, $gaji, $mgr, $u, $role);
            $isi[$kd]++;
        }
        foreach ($jumlah as $kd => $n) {
            while ($isi[$kd] < $n) {
                [$jab, $dasar] = $staf[$kd][mt_rand(0, count($staf[$kd]) - 1)];
                $buatKaryawan($faker->firstName . ' ' . $faker->lastName, $kd, $jab, $statusPtkp[mt_rand(0, 9)], $dasar + mt_rand(0, 15) * 100000, false);
                $isi[$kd]++;
            }
        }
        MasterPayroll::whereNull('dibuat_oleh')->whereNotNull('periode')->update(['dibuat_oleh' => User::where('username', 'hr')->value('id')]);

        // jejak aktivitas Admin (tampil di Dashboard Admin)
        $admin = User::where('username', 'admin')->first();
        foreach (User::where('id', '!=', $admin->id)->with('karyawan')->get() as $i => $u) {
            \App\Models\LogAktivitas::create(['user_id' => $admin->id, 'aktivitas' => 'Buat akun ' . $u->namaTampil(), 'modul' => 'User', 'created_at' => now()->subDays(40 - $i)]);
        }
        \App\Models\LogAktivitas::create(['user_id' => $admin->id, 'aktivitas' => 'Ubah privilege Manager', 'modul' => 'Privilege', 'created_at' => now()->subDays(5)]);

        // ===== absensi, cuti/izin/sakit, lembur: Juli s/d kemarin =====
        [$awal] = MasterPayroll::untuk(now()->subMonthsNoOverflow(4)->format('Y-m'))->rentang(now()->subMonthsNoOverflow(4)->format('Y-m'));
        $hr = User::where('username', 'hr')->value('id');
        $mgrUser = User::whereHas('role', fn ($q) => $q->where('nama', 'Manager'))->get()->keyBy(fn ($u) => $u->karyawan?->departemen_id);
        $berhak = Karyawan::whereHas('kontrakAktif.komponen.component', fn ($q) => $q->where('dasar_bayar', 'lembur'))->pluck('id')->flip();
        $absen = []; $lemburRows = []; $now = now();
        foreach (Karyawan::all() as $kar) {
            $libur = 0;
            foreach (CarbonPeriod::create($awal, now()->subDay()) as $tgl) {
                if ($tgl->isWeekend() || $tgl->lt($kar->tgl_masuk)) {
                    continue;
                }
                if ($libur > 0) { $libur--; continue; }
                $r = mt_rand(1, 1000);
                if ($r <= 12) {                                   // sakit 1-2 hari
                    $n = mt_rand(1, 2); $sel = $this->hariKerjaKe($tgl, $n);
                    Leave::create(['karyawan_id' => $kar->id, 'jenis' => 'sakit', 'tgl_mulai' => $sel[0], 'tgl_selesai' => $sel[1], 'keterangan' => 'Surat dokter']);
                    $libur = $n - 1; continue;
                }
                if ($r <= 18) {                                   // izin
                    Leave::create(['karyawan_id' => $kar->id, 'jenis' => 'izin', 'tgl_mulai' => $tgl->toDateString(), 'tgl_selesai' => $tgl->toDateString(), 'keterangan' => ['Urusan keluarga', 'Keperluan pribadi', 'Mengurus dokumen'][mt_rand(0, 2)]]);
                    continue;
                }
                if ($r <= 26 && $kar->sisa_cuti > 2) {             // cuti 1-3 hari
                    $n = mt_rand(1, 3); $sel = $this->hariKerjaKe($tgl, $n);
                    $l = Leave::create(['karyawan_id' => $kar->id, 'jenis' => 'cuti', 'tgl_mulai' => $sel[0], 'tgl_selesai' => $sel[1], 'keterangan' => 'Cuti tahunan']);
                    $l->kurangiSisaCuti(); $kar->refresh(); $libur = $n - 1; continue;
                }
                $status = $r <= 34 ? 'alfa' : ($r <= 90 ? 'terlambat' : 'hadir');
                $absen[] = ['karyawan_id' => $kar->id, 'tanggal' => $tgl->toDateString(), 'status' => $status,
                    'jam_masuk' => $status === 'alfa' ? null : ($status === 'terlambat' ? sprintf('08:%02d', mt_rand(16, 55)) : sprintf('07:%02d', mt_rand(40, 59))),
                    'jam_keluar' => $status === 'alfa' ? null : sprintf('17:%02d', mt_rand(0, 20)), 'created_at' => $now, 'updated_at' => $now];
                if ($status !== 'alfa' && isset($berhak[$kar->id]) && mt_rand(1, 100) <= 12) {
                    $mulai = mt_rand(17, 18); $jam = mt_rand(1, 4); $oleh = mt_rand(0, 1) ? $hr : ($mgrUser[$kar->departemen_id]->id ?? $hr);
                    $lemburRows[] = ['karyawan_id' => $kar->id, 'tanggal' => $tgl->toDateString(), 'jam_mulai' => sprintf('%02d:00', $mulai), 'jam_selesai' => sprintf('%02d:00', $mulai + $jam),
                        'durasi' => $jam, 'keterangan' => ['Closing gudang', 'Stock opname', 'Deploy aplikasi', 'Bongkar muat', 'Perbaikan mesin', 'Kejar target pengiriman'][mt_rand(0, 5)],
                        'diinput_oleh' => $oleh, 'created_at' => $now, 'updated_at' => $now];
                }
            }
        }
        // DB::table melewati global scope: company_id diisi eksplisit
        $companyId = app(\App\Domain\Company\Tenancy\CurrentCompany::class)->id();
        $milik = fn (array $rows) => array_map(fn ($r) => $r + ['company_id' => $companyId], $rows);
        foreach (array_chunk($absen, 500) as $c) { \DB::table('attendance')->insert($milik($c)); }
        foreach (array_chunk($lemburRows, 500) as $c) { \DB::table('overtime')->insert($milik($c)); }

        // ===== payroll Juli–Oktober final, November draft =====
        $svc = app(\App\Services\PayrollService::class);
        $approval = app(\App\Services\ApprovalService::class);
        \App\Services\PayrollService::$tanpaPdf = true; // PDF dibuat saat slip diunduh
        foreach ([4, 3, 2, 1] as $mundur) {
            $pr = now()->subMonthsNoOverflow($mundur)->format('Y-m');
            $payroll = $svc->generate($pr);
            $a = $approval->ajukanPayroll($payroll);
            $approval->setujui($a, $a->approver, 'Sudah dicek, sesuai.');
            $tgl = \Carbon\Carbon::parse($pr . '-01')->day(27);
            $payroll->update(['tgl_generate' => $tgl->toDateString()]);
            \App\Models\Approval::whereKey($a->id)->update(['created_at' => $tgl, 'tgl_approval' => $tgl->copy()->addDays(2)->toDateString()]);
            \App\Models\Payslip::where('payroll_id', $payroll->id)->update(['created_at' => $tgl->copy()->addDays(2)]);
        }
        \App\Services\PayrollService::$tanpaPdf = false;
        $svc->generate(now()->format('Y-m'));
        \App\Models\Notifikasi::query()->update(['is_read' => true]); // notifikasi lama dianggap sudah dibaca

        // contoh kontrak perpanjangan yang menunggu approval Manager IT
        $nadia = Karyawan::where('nama', 'Nadia Putri')->first();
        $nadia->update(['status_kepegawaian' => 'kontrak']);
        $lamaNadia = $nadia->kontrakAktif()->first();
        $lamaNadia->update(['jenis' => 'PKWT', 'tgl_akhir' => now()->addMonthNoOverflow()->startOfMonth()->subDay()]);
        $lamaNadia->update(['nomor' => null]);
        $lamaNadia->update(['nomor' => Kontrak::buatNomor($lamaNadia)]);
        $baru = Kontrak::create([
            'karyawan_id' => $nadia->id, 'kontrak_sebelumnya_id' => $lamaNadia->id, 'jenis' => 'PKWT', 'tgl_mulai' => now()->addMonthNoOverflow()->startOfMonth(),
            'tgl_akhir' => now()->addMonthNoOverflow()->startOfMonth()->addYear()->subDay(), 'gaji_pokok' => 8000000, 'status_approval' => 'draft',
        ]);
        foreach ([$makan, $transport, $lembur, $thr] as $c) {
            KontrakKomponen::create(['kontrak_id' => $baru->id, 'payslip_component_id' => $c->id, 'tipe_nilai' => $c->tipe_nilai, 'nilai' => $c->nilai_default]);
        }
        $approval->ajukanKontrak($baru);
    }

    /** [tanggal mulai, tanggal selesai] untuk $n hari kerja mulai dari $tgl. */
    private function hariKerjaKe($tgl, int $n): array
    {
        $tgl = $tgl->copy();
        while ($tgl->isWeekend()) {
            $tgl->addDay();
        }
        $mulai = $tgl->toDateString();
        for ($i = 1; $i < $n; $i++) {
            do {
                $tgl->addDay();
            } while ($tgl->isWeekend());
        }

        return [$mulai, $tgl->toDateString()];
    }
}
