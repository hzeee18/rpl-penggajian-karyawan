<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Actions\BuatKontrak;
use App\Domain\Payroll\Actions\AlihkanKePayrollBaru;
use App\Domain\Payroll\Models\Attendance;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Holiday;
use App\Domain\Payroll\Models\Leave;
use App\Domain\Payroll\Models\LeaveType;
use App\Domain\Payroll\Models\Overtime;
use App\Domain\Payroll\Models\PeriodConfig;
use App\Domain\Payroll\Models\Position;
use App\Models\Departemen;
use App\Models\Karyawan;
use App\Models\Pajak;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Data contoh lengkap untuk SATU perusahaan yang sudah ada, TANPA master payroll/rumus (diisi pemilik):
 * modul payroll baru aktif (menu lama yang digantikan dialihkan), cut off, libur nasional bertanggal tetap, tipe leave, jabatan, 2 departemen,
 * karyawan, user Admin/HR/Manager/Staf (+ approver HR = Manager), katalog komponen, kontrak aktif dengan
 * nilai berbeda, serta absensi/leave/lembur 2 periode (alpha, terlambat, cuti, sakit bersurat, lembur akhir pekan).
 *
 * Dipanggil lewat `php artisan payroll:isi-data {kode}`; juga dipakai PayrollDemoSeeder. Semua data fiktif.
 * Menolak berjalan bila perusahaan sudah punya karyawan/departemen atau username contoh sudah dipakai.
 */
final class DataPerusahaanSeeder extends Seeder
{
    /** Libur nasional 2026 bertanggal tetap. Libur keagamaan/lunar mengikuti SKB 3 Menteri dan ditambahkan HR. */
    public const LIBUR_TETAP_2026 = [
        '2026-01-01' => 'Tahun Baru Masehi', '2026-05-01' => 'Hari Buruh Internasional', '2026-06-01' => 'Hari Lahir Pancasila',
        '2026-08-17' => 'Hari Kemerdekaan RI', '2026-12-25' => 'Hari Raya Natal',
    ];

    public const PASSWORD = 'password';

    private const NAMA = ['Adi Pratama', 'Bunga Lestari', 'Candra Wijaya', 'Dina Kartika', 'Eko Saputra', 'Fajar Nugroho', 'Gita Permata',
        'Hadi Santoso', 'Indah Puspita', 'Joni Setiawan', 'Kirana Dewi', 'Lukman Hakim', 'Maya Sari', 'Nanda Putra'];

    /** Komponen per varian: [kode, nama, tipe, kena pajak, nilai dari kontrak] */
    private const KOMPONEN = [
        'gaji_pokok' => ['Gaji Pokok', 'earning', true, true], 'uang_makan' => ['Uang Makan', 'earning', true, true],
        'tunjangan_transport' => ['Tunjangan Transport', 'earning', true, true], 'lembur' => ['Lembur', 'earning', true, false],
        'potongan_alpha' => ['Potongan Alpha', 'deduction', false, false], 'potongan_terlambat' => ['Potongan Terlambat', 'deduction', false, false],
        'bpjs_tk' => ['BPJS Ketenagakerjaan', 'deduction', false, true], 'bpjs_kes' => ['BPJS Kesehatan', 'deduction', false, true],
        'pph21' => ['PPh21 TER', 'deduction', false, false],
    ];

    private const VARIAN = [
        'a' => ['gaji_pokok', 'uang_makan', 'lembur', 'potongan_alpha', 'bpjs_tk', 'bpjs_kes', 'pph21'],
        'b' => ['gaji_pokok', 'tunjangan_transport', 'lembur', 'potongan_terlambat', 'bpjs_tk', 'pph21'],
        'lengkap' => ['gaji_pokok', 'uang_makan', 'tunjangan_transport', 'lembur', 'potongan_alpha', 'potongan_terlambat', 'bpjs_tk', 'bpjs_kes', 'pph21'],
    ];

    /** Dipakai `db:seed --class=DataPerusahaanSeeder` lewat perintah payroll:isi-data. */
    public static ?string $kode = null;

    public static int $jumlah = 12;

    public function run(): void
    {
        $company = Company::where('code', self::$kode)->first() ?? throw new RuntimeException('Perusahaan '.self::$kode.' tidak ditemukan.');
        $this->isi($company, ['jumlah' => self::$jumlah]);
        $this->command?->info("Data contoh {$company->code} dibuat. Login: ".implode(', ', self::username($company->code)).' (password: '.self::PASSWORD.').');
    }

    /** @return array{admin: string, hr: string, manager: string, staf: string} */
    public static function username(string $kode): array
    {
        $k = strtolower($kode);

        return ['admin' => "admin.{$k}", 'hr' => "hr.{$k}", 'manager' => "manager.{$k}", 'staf' => "staf.{$k}"];
    }

    /** Alasan data contoh tidak boleh diisi ke perusahaan ini, atau null bila aman. */
    public static function alasanTolak(Company $company): ?string
    {
        // lintas perusahaan: username unik global, jadi dicek ke semua perusahaan
        $dipakai = app(CurrentCompany::class)->lintasPerusahaan('seeder: cek username contoh unik global',
            fn () => DB::table('users')->whereIn('username', array_values(self::username($company->code)))->pluck('username')->all());
        if ($dipakai !== []) {
            return 'Username sudah dipakai: '.implode(', ', $dipakai).'.';
        }
        if (DB::table('karyawan')->where('company_id', $company->id)->exists() || DB::table('departemen')->where('company_id', $company->id)->exists()) {
            return "Perusahaan {$company->code} sudah punya karyawan/departemen; data contoh hanya untuk perusahaan kosong.";
        }
        if (! DB::table('roles')->where('company_id', $company->id)->exists()) {
            return "Perusahaan {$company->code} belum punya role.";
        }

        return null;
    }

    /**
     * @param  array{jumlah?: int, cutoff?: array{start_day: int, end_day: int, start_in_previous_month: bool}, varian?: string, awalan?: string, akhiran_nama?: string, rentang?: array{0: string, 1: string}}  $opsi
     * @return array<string, Component>
     */
    public function isi(Company $company, array $opsi = []): array
    {
        if (($alasan = self::alasanTolak($company)) !== null) {
            throw new RuntimeException($alasan);
        }
        $jumlah = $opsi['jumlah'] ?? 12;
        if ($jumlah < 4 || $jumlah > 50) {
            throw new RuntimeException('Jumlah karyawan contoh 4 sampai 50.');
        }
        $awalan = $opsi['awalan'] ?? substr((string) preg_replace('/[^A-Z0-9]/', '', strtoupper($company->code)), 0, 6);
        $cutoff = $opsi['cutoff'] ?? ['start_day' => 1, 'end_day' => 31, 'start_in_previous_month' => false];
        $varian = $opsi['varian'] ?? 'lengkap';
        $user = self::username($company->code);
        app(AlihkanKePayrollBaru::class)->handle((int) $company->id);

        return app(CurrentCompany::class)->runAs((int) $company->id, function () use ($company, $opsi, $jumlah, $awalan, $cutoff, $varian, $user) {
            return DB::transaction(function () use ($company, $opsi, $jumlah, $awalan, $cutoff, $varian, $user) {
                $config = PeriodConfig::create($cutoff);
                foreach (self::LIBUR_TETAP_2026 as $tgl => $ket) {
                    Holiday::create(['holiday_date' => $tgl, 'name' => $ket]);
                }
                foreach (['cuti' => 'Cuti', 'izin' => 'Izin', 'sakit' => 'Sakit', 'sakit_bersurat' => 'Sakit dengan surat dokter'] as $c => $n) {
                    LeaveType::create(['code' => $c, 'name' => $n]);
                }
                $jabatan = [Position::create(['code' => 'STF', 'name' => 'Staf']), Position::create(['code' => 'SPV', 'name' => 'Supervisor'])];
                $dept = [Departemen::create(['kode' => 'HRD', 'nama' => 'Human Resource']), Departemen::create(['kode' => 'OPS', 'nama' => 'Operasional'])];
                $pajak = Pajak::orderBy('id')->pluck('id')->all();
                $domain = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $company->code)).'.test';

                $karyawan = [];
                for ($i = 0; $i < $jumlah; $i++) {
                    $putaran = intdiv($i, count(self::NAMA));
                    $karyawan[] = Karyawan::create([
                        'departemen_id' => $dept[$i < 3 ? 0 : 1]->id, 'pajak_id' => $pajak[$i % max(count($pajak), 1)] ?? null,
                        'nik' => sprintf('%s%04d', $awalan, $i + 1),
                        'nama' => self::NAMA[$i % count(self::NAMA)].($putaran > 0 ? ' '.($putaran + 1) : '').($opsi['akhiran_nama'] ?? ''),
                        'jabatan' => $jabatan[$i % 2]->name, 'tgl_masuk' => '2024-01-02', 'email' => strtolower($awalan)."{$i}@{$domain}",
                        'nama_bank' => 'BANK DEMO', 'no_rekening' => sprintf('9%09d', 1000 + $i * 37), 'status_kepegawaian' => 'kontrak',
                        'sisa_cuti' => 12, 'is_active' => true,
                    ]);
                    DB::table('karyawan')->where('id', $karyawan[$i]->id)->update(['position_id' => $jabatan[$i % 2]->id]);
                }
                $dept[0]->aturManager([$karyawan[0]->id]);
                $dept[1]->aturManager([$karyawan[0]->id]);

                $role = fn (string $n) => Role::where('nama', $n)->value('id');
                $buatUser = fn (string $u, string $r, ?Karyawan $k) => User::create(['role_id' => $role($r), 'karyawan_id' => $k?->id, 'username' => $u,
                    'password' => self::PASSWORD, 'email' => "{$u}@{$domain}", 'is_active' => true]);
                $manager = $buatUser($user['manager'], Role::MANAGER, $karyawan[0]);
                $hr = $buatUser($user['hr'], Role::HR, $karyawan[1]);
                $hr->forceFill(['approver_user_id' => $manager->id])->save();
                $buatUser($user['admin'], Role::ADMIN, null);
                $buatUser($user['staf'], Role::KARYAWAN, $karyawan[3]);

                $komponen = [];
                foreach (self::VARIAN[$varian] as $code) {
                    [$name, $type, $tax, $kontrak] = self::KOMPONEN[$code];
                    $komponen[$code] = Component::create(['code' => $code, 'name' => $name, 'type' => $type, 'is_taxable' => $tax, 'is_contractable' => $kontrak, 'default_amount' => '0']);
                }
                $nilaiKontrak = [
                    'gaji_pokok' => fn (int $i) => 5000000 + $i * 250000, 'uang_makan' => fn (int $i) => 550000 + ($i % 3) * 50000,
                    'tunjangan_transport' => fn (int $i) => 400000 + ($i % 4) * 25000, 'bpjs_tk' => fn (int $i) => 100000 + $i * 5000,
                    'bpjs_kes' => fn (int $i) => 50000,
                ];
                foreach ($karyawan as $i => $k) {
                    $nilai = [];
                    foreach ($nilaiKontrak as $code => $f) {
                        if (isset($komponen[$code])) {
                            $nilai[$komponen[$code]->id] = (string) $f($i);
                        }
                    }
                    app(BuatKontrak::class)->handle(['employee_id' => $k->id, 'contract_name' => "PKWT {$company->code} 2026", 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $nilai);
                }

                // 2 periode: periode lalu dan periode berjalan (kecuali ditentukan)
                $rentang = $opsi['rentang'] ?? [
                    $config->rentang(now()->subMonthNoOverflow()->format('Y-m'))[0]->toDateString(),
                    $config->rentang(now()->format('Y-m'))[1]->toDateString(),
                ];
                $this->kehadiran($karyawan, $rentang);

                return $komponen;
            });
        });
    }

    /**
     * Absensi: sebagian besar hadir; tiap karyawan ke-4 punya 1 hari alpha per bulan, ke-3 terlambat,
     * ke-5 cuti 2 hari, ke-6 sakit_bersurat, lembur hari kerja dan akhir pekan.
     *
     * @param  list<Karyawan>  $karyawan
     * @param  array{0: string, 1: string}  $rentang
     */
    private function kehadiran(array $karyawan, array $rentang): void
    {
        $tipe = LeaveType::pluck('id', 'code');
        $libur = Holiday::pluck('holiday_date')->map(fn ($d) => $d->toDateString())->flip();
        foreach ($karyawan as $i => $k) {
            foreach (CarbonPeriod::create(CarbonImmutable::parse($rentang[0]), CarbonImmutable::parse($rentang[1])) as $d) {
                $tgl = $d->toDateString();
                if ($d->isWeekend()) {
                    if ($i % 5 === 1 && $d->isSaturday() && $d->day <= 7) {
                        Overtime::create(['employee_id' => $k->id, 'overtime_date' => $tgl, 'duration_hours' => '4', 'note' => 'Lembur akhir pekan']);
                    }

                    continue;
                }
                if ($libur->has($tgl)) {
                    continue;
                }
                if ($i % 4 === 3 && $d->day === 15) {
                    continue; // alpha: tanpa absensi dan tanpa leave
                }
                if ($i % 5 === 4 && in_array($d->day, [10, 11], true)) {
                    Leave::create(['employee_id' => $k->id, 'leave_type_id' => $tipe['cuti'], 'leave_date' => $tgl, 'note' => 'Cuti tahunan']);

                    continue;
                }
                if ($i % 6 === 5 && $d->day === 20) {
                    Leave::create(['employee_id' => $k->id, 'leave_type_id' => $tipe['sakit_bersurat'], 'leave_date' => $tgl, 'note' => 'Surat dokter']);

                    continue;
                }
                $telat = $i % 3 === 2 && $d->day % 7 === 0 ? '0.75' : '0';
                Attendance::create(['employee_id' => $k->id, 'attendance_date' => $tgl, 'check_in' => $telat === '0' ? '07:55:00' : '08:45:00', 'check_out' => '17:05:00', 'late_hours' => $telat]);
                if ($i % 3 === 0 && $d->day % 9 === 0) {
                    Overtime::create(['employee_id' => $k->id, 'overtime_date' => $tgl, 'duration_hours' => '2.5', 'note' => 'Lembur hari kerja']);
                }
            }
        }
    }
}
