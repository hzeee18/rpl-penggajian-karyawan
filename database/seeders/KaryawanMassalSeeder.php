<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Actions\BuatKontrak;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Holiday;
use App\Domain\Payroll\Models\LeaveType;
use App\Domain\Payroll\Models\Position;
use App\Domain\Payroll\Models\WorkPattern;
use App\Models\Departemen;
use App\Models\Karyawan;
use App\Models\Pajak;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Karyawan dummy dalam jumlah besar (default 110) di perusahaan MTS, untuk uji generate payroll skala besar.
 * Jalankan SETELAH FlowUjiSeeder:
 *
 *   php artisan db:seed --class=KaryawanMassalSeeder
 *
 * Isi: 3 departemen tambahan (IT, Marketing, Gudang pola Senin–Sabtu) + departemen UJI yang ada, gaji bervariasi,
 * kontrak (sebagian hampir habis), karyawan masuk/resign di tengah periode, akun login per karyawan
 * (`m001.mts` dst, password: password), absensi 26 Sep – 25 Des 2026 mengikuti pola hari kerja, dengan alpha, terlambat,
 * cuti, sakit, dan lembur. Semua nama, NIK, dan rekening fiktif. Tidak mengubah 12 karyawan dan akun yang sudah ada.
 * Aman dijalankan ulang: bila karyawan massal sudah ada, tidak ada yang diubah.
 */
final class KaryawanMassalSeeder extends Seeder
{
    public const AWALAN_NIK = 'M';

    public static int $jumlah = 110;

    private const DEPAN = ['Agus', 'Ahmad', 'Andi', 'Anisa', 'Ayu', 'Bagus', 'Bayu', 'Budi', 'Citra', 'Dani', 'Desi', 'Dewi', 'Dimas', 'Eka',
        'Endah', 'Fajar', 'Fitri', 'Galih', 'Hendra', 'Ika', 'Irfan', 'Joko', 'Kurnia', 'Lia', 'Mega', 'Nur', 'Putri', 'Rahmat', 'Rina',
        'Rizky', 'Sari', 'Siti', 'Taufik', 'Tri', 'Wahyu', 'Wulan', 'Yoga', 'Yuni', 'Zaki', 'Novi'];

    private const BELAKANG = ['Saputra', 'Wijaya', 'Hidayat', 'Kusuma', 'Lestari', 'Pratama', 'Rahayu', 'Santoso', 'Setiawan', 'Siregar',
        'Nasution', 'Utami', 'Wibowo', 'Gunawan', 'Permana', 'Maharani', 'Ramadhan', 'Purnomo', 'Susanti', 'Hakim'];

    public function run(): void
    {
        $company = Company::where('code', FlowUjiSeeder::KODE)->first()
            ?? throw new RuntimeException('Perusahaan '.FlowUjiSeeder::KODE.' belum ada. Jalankan dulu: php artisan db:seed --class=FlowUjiSeeder');

        $sudah = app(CurrentCompany::class)->runAs((int) $company->id,
            fn () => Karyawan::where('nik', 'like', self::AWALAN_NIK.'%')->exists());
        if ($sudah) {
            $this->command?->warn('Karyawan massal '.FlowUjiSeeder::KODE.' sudah ada. Tidak ada yang diubah.');

            return;
        }

        app(CurrentCompany::class)->runAs((int) $company->id, fn () => DB::transaction(fn () => $this->isi((int) $company->id)));
        $this->command?->info(self::$jumlah.' karyawan dummy ditambahkan ke perusahaan '.FlowUjiSeeder::KODE.'. Login karyawan: m001.mts … (password: password).');
    }

    private function isi(int $companyId): void
    {
        mt_srand(2026); // data sama setiap kali dibuat

        $gudang = WorkPattern::create(['code' => 'gudang', 'name' => 'Gudang Senin–Sabtu', 'work_days' => '1,2,3,4,5,6']);
        $dept = Departemen::whereIn('kode', ['HRD', 'OPS', 'FIN'])->get()->keyBy('kode');
        $dept['IT'] = Departemen::create(['kode' => 'IT', 'nama' => 'Teknologi Informasi']);
        $dept['MKT'] = Departemen::create(['kode' => 'MKT', 'nama' => 'Marketing']);
        $dept['GDG'] = Departemen::create(['kode' => 'GDG', 'nama' => 'Gudang']);
        $dept['GDG']->forceFill(['pay_work_pattern_id' => $gudang->id])->save();
        // sebaran: Operasional dan Gudang paling banyak
        $sebaran = ['OPS' => 30, 'GDG' => 25, 'MKT' => 18, 'IT' => 15, 'FIN' => 12, 'HRD' => 10];

        $jabatan = Position::pluck('id', 'name');
        $pajak = Pajak::orderBy('id')->pluck('id')->all();
        $komponen = Component::pluck('id', 'code');
        $roleKaryawan = Role::where('nama', Role::KARYAWAN)->value('id');
        $hash = Hash::make(DataPerusahaanSeeder::PASSWORD);

        $daftar = [];
        $kodeDept = array_keys($sebaran);
        for ($i = 0; $i < self::$jumlah; $i++) {
            // isi departemen bergiliran sesuai bobot sebaran
            $bobot = array_sum($sebaran);
            $kode = $kodeDept[0];
            $r = ($i * 37) % $bobot;
            foreach ($sebaran as $kd => $n) {
                if ($r < $n) {
                    $kode = $kd;
                    break;
                }
                $r -= $n;
            }
            $no = $i + 1;
            $nama = self::DEPAN[$i % count(self::DEPAN)].' '.self::BELAKANG[intdiv($i, 2) % count(self::BELAKANG)];
            $spv = $no % 12 === 0;
            $masuk = match (true) {
                $no % 25 === 0 => '2026-11-0'.(3 + $no % 6),           // masuk di tengah periode November (prorata)
                default => sprintf('20%02d-%02d-%02d', 18 + $no % 8, 1 + $no % 12, 1 + $no % 27),
            };
            $resign = $no % 40 === 0 ? '2026-12-1'.($no % 9) : null; // resign di periode Desember
            $k = Karyawan::create([
                'departemen_id' => $dept[$kode]->id, 'pajak_id' => $pajak[$i % max(count($pajak), 1)] ?? null,
                'nik' => sprintf('%s%04d', self::AWALAN_NIK, $no), 'nama' => $nama,
                'jabatan' => $spv ? 'Supervisor' : 'Staf', 'tgl_masuk' => $masuk, 'email' => sprintf('m%03d@mts.test', $no),
                'jenis_kelamin' => in_array(self::DEPAN[$i % count(self::DEPAN)], ['Anisa', 'Ayu', 'Citra', 'Desi', 'Dewi', 'Endah', 'Fitri', 'Ika', 'Lia', 'Mega', 'Putri', 'Rina', 'Sari', 'Siti', 'Wulan', 'Yuni', 'Novi', 'Nur'], true) ? 'P' : 'L',
                'nama_bank' => 'BANK DEMO', 'no_rekening' => sprintf('8%09d', 500000 + $no * 113), 'status_kepegawaian' => 'kontrak',
                'sisa_cuti' => 12, 'is_active' => true,
            ]);
            DB::table('karyawan')->where('id', $k->id)->update(['position_id' => $jabatan[$spv ? 'Supervisor' : 'Staf'] ?? null, 'resign_date' => $resign]);
            User::create(['role_id' => $roleKaryawan, 'karyawan_id' => $k->id, 'username' => sprintf('m%03d.mts', $no),
                'password' => $hash, 'email' => sprintf('m%03d@mts.test', $no), 'is_active' => true]);

            // gaji pokok 4,5 – 15 juta (kelipatan 50 ribu), supervisor lebih tinggi
            $pokok = (4500000 + mt_rand(0, 150) * 50000) + ($spv ? 3000000 : 0);
            $nilai = array_filter([
                $komponen['gaji_pokok'] ?? null => (string) $pokok,
                $komponen['uang_makan'] ?? null => (string) (500000 + mt_rand(0, 4) * 50000),
            ], fn ($v, $id) => $id !== '' && $id !== null, ARRAY_FILTER_USE_BOTH);
            $akhir = match (true) {
                $no % 9 === 0 => '2027-0'.(1 + $no % 2).'-28',  // hampir habis (≤ 3 bulan)
                $no % 3 === 0 => '2027-12-31',
                default => null,                                 // tetap
            };
            $mulai = max($masuk, '2026-01-01');
            app(BuatKontrak::class)->handle(['employee_id' => $k->id, 'contract_name' => $akhir ? 'PKWT' : 'PKWTT',
                'start_date' => $mulai, 'end_date' => $akhir], $nilai);
            $daftar[] = ['id' => $k->id, 'dept' => $kode, 'masuk' => $mulai, 'resign' => $resign, 'no' => $no];
        }
        // manager tiap departemen (kecuali HRD dan FIN yang sudah ada): karyawan massal pertama di departemen itu
        foreach (['OPS', 'IT', 'MKT', 'GDG'] as $kd) {
            $mgr = collect($daftar)->firstWhere('dept', $kd);
            if ($mgr) {
                $dept[$kd]->aturManager([$mgr['id']]);
            }
        }

        $this->kehadiran($companyId, $daftar);
        DataPerusahaanSeeder::namaiKontrak(); // nama kontrak berupa kode (urutan per tahun mencakup kontrak UJI yang sudah ada)
    }

    /**
     * Absensi 26 Sep – 25 Des 2026 sesuai pola hari kerja departemen (OPS Senin–Minggu, GDG Senin–Sabtu, lainnya Senin–Jumat).
     * Variasi acak tetap (mt_srand): ±2% alpha, ±8% terlambat, cuti/sakit sesekali, lembur hari kerja dan akhir pekan.
     *
     * @param  list<array{id: int, dept: string, masuk: string, resign: ?string, no: int}>  $daftar
     */
    private function kehadiran(int $companyId, array $daftar): void
    {
        $tipe = LeaveType::pluck('id', 'code');
        $libur = Holiday::whereNull('departemen_id')->pluck('holiday_date')->map(fn ($d) => $d->toDateString())->flip();
        $hariKerja = ['OPS' => [1, 2, 3, 4, 5, 6, 7], 'GDG' => [1, 2, 3, 4, 5, 6]];
        $sekarang = now()->toDateTimeString();
        $absen = $lembur = $cuti = [];
        $simpan = function () use (&$absen, &$lembur, &$cuti): void {
            foreach (['pay_attendances' => &$absen, 'pay_overtimes' => &$lembur, 'pay_leaves' => &$cuti] as $tabel => &$baris) {
                foreach (array_chunk($baris, 500) as $potong) {
                    DB::table($tabel)->insert($potong);
                }
                $baris = [];
            }
        };

        foreach ($daftar as $k) {
            $pola = $hariKerja[$k['dept']] ?? [1, 2, 3, 4, 5];
            foreach (CarbonPeriod::create('2026-09-26', '2026-12-25') as $d) {
                $tgl = $d->toDateString();
                if ($tgl < $k['masuk'] || ($k['resign'] !== null && $tgl > $k['resign'])) {
                    continue;
                }
                $dasar = ['company_id' => $companyId, 'employee_id' => $k['id'], 'created_at' => $sekarang, 'updated_at' => $sekarang];
                if (! in_array($d->dayOfWeekIso, $pola, true) || $libur->has($tgl)) {
                    if ($d->isSaturday() && mt_rand(1, 100) <= 6) { // lembur akhir pekan / hari libur
                        $lembur[] = $dasar + ['overtime_date' => $tgl, 'duration_hours' => '4', 'note' => 'Lembur akhir pekan'];
                    }

                    continue;
                }
                $acak = mt_rand(1, 1000);
                if ($acak <= 20) {
                    continue; // alpha
                }
                if ($acak <= 45) {
                    $kode = $acak <= 35 ? 'cuti' : ($acak <= 40 ? 'sakit_bersurat' : 'izin');
                    if (isset($tipe[$kode])) {
                        $cuti[] = $dasar + ['leave_type_id' => $tipe[$kode], 'leave_date' => $tgl, 'note' => ucfirst(str_replace('_', ' ', $kode))];
                    }

                    continue;
                }
                $telat = $acak > 920 ? number_format(mt_rand(1, 8) * 0.25, 2, '.', '') : '0';
                $absen[] = $dasar + ['attendance_date' => $tgl, 'check_in' => $telat === '0' ? '07:5'.mt_rand(0, 9).':00' : sprintf('%02d:%02d:00', 8 + intdiv((int) ($telat * 60), 60), (int) ($telat * 60) % 60),
                    'check_out' => '17:0'.mt_rand(0, 9).':00', 'late_hours' => $telat];
                if (mt_rand(1, 100) <= 7) {
                    $lembur[] = $dasar + ['overtime_date' => $tgl, 'duration_hours' => (string) (mt_rand(2, 8) * 0.5), 'note' => 'Lembur hari kerja'];
                }
            }
            if (count($absen) > 2000) {
                $simpan();
            }
        }
        $simpan();
    }
}
