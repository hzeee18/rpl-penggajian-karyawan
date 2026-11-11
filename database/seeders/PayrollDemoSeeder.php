<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Company\Actions\BuatPerusahaan;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Actions\BuatKontrak;
use App\Domain\Payroll\Actions\KelolaMasterPayroll;
use App\Domain\Payroll\Actions\PasangMenuPayroll;
use App\Domain\Payroll\Models\Attendance;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Holiday;
use App\Domain\Payroll\Models\Leave;
use App\Domain\Payroll\Models\LeaveType;
use App\Domain\Payroll\Models\Overtime;
use App\Domain\Payroll\Models\PeriodConfig;
use App\Domain\Payroll\Models\Position;
use App\Domain\Payroll\Models\ReferenceTable;
use App\Domain\Payroll\Models\ReferenceTableVersion;
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
 * Data demo modul payroll baru (AGENTS.md "Seed data"). Dipanggil manual:
 *   php artisan db:seed --class=PayrollDemoSeeder
 * Tidak dimasukkan ke DatabaseSeeder, tidak menghapus data, menolak berjalan dua kali.
 *
 * - 2 perusahaan (DEMOA, DEMOB) dengan role Admin/HR/Manager/Karyawan dan menu payroll baru aktif.
 * - Karyawan, 2 departemen, kontrak aktif dengan nilai komponen berbeda, absensi/leave/lembur 2 periode
 *   (termasuk alpha, terlambat, lembur akhir pekan, tipe leave baru).
 * - Cut off: DEMOA lintas bulan (26-25), DEMOB kalender bulanan.
 * - Master "Payroll Usual" berbeda di tiap perusahaan (aturan lembur & keterlambatan berbeda: aturan di database).
 * - TER/PTKP: dibaca dari database/seeders/data/ter_pph21.csv. Bila file belum ada, dilewati (komponen PPh21
 *   tidak dipasang) dan diberi peringatan. Angka TER TIDAK dikarang di sini.
 * Semua data fiktif (nama, nomor karyawan, rekening).
 */
final class PayrollDemoSeeder extends Seeder
{
    public const FILE_TER = 'seeders/data/ter_pph21.csv';

    /** Hanya untuk tes: lokasi file TER lain (berisi angka uji). Null = file resmi FILE_TER. */
    public static ?string $fileTerUji = null;

    /** Libur nasional 2026 bertanggal tetap. Libur keagamaan/lunar mengikuti SKB 3 Menteri dan ditambahkan HR. */
    private const LIBUR_TETAP_2026 = [
        '2026-01-01' => 'Tahun Baru Masehi', '2026-05-01' => 'Hari Buruh Internasional', '2026-06-01' => 'Hari Lahir Pancasila',
        '2026-08-17' => 'Hari Kemerdekaan RI', '2026-12-25' => 'Hari Raya Natal',
    ];

    private const NAMA = ['Adi Pratama', 'Bunga Lestari', 'Candra Wijaya', 'Dina Kartika', 'Eko Saputra', 'Fajar Nugroho', 'Gita Permata',
        'Hadi Santoso', 'Indah Puspita', 'Joni Setiawan', 'Kirana Dewi', 'Lukman Hakim', 'Maya Sari', 'Nanda Putra'];

    public function run(): void
    {
        if (Company::whereIn('code', ['DEMOA', 'DEMOB'])->exists()) {
            $this->command?->warn('PayrollDemoSeeder sudah pernah dijalankan (perusahaan DEMOA/DEMOB ada). Tidak ada yang diubah.');

            return;
        }
        if (! DB::table('roles')->where('company_id', Company::where('code', Company::DEFAULT_CODE)->value('id'))->exists()) {
            throw new RuntimeException('Perusahaan DEFAULT belum punya role (templat). Jalankan DatabaseSeeder dulu.');
        }

        $ter = $this->terNasional();

        $this->perusahaan('DEMOA', 'PT Demo Sejahtera', 'A', 12, ['start_day' => 26, 'end_day' => 25, 'start_in_previous_month' => true], $ter, 'a');
        $this->perusahaan('DEMOB', 'PT Contoh Makmur', 'B', 10, ['start_day' => 1, 'end_day' => 31, 'start_in_previous_month' => false], $ter, 'b');

        $this->command?->info('Data demo payroll dibuat. Login: hr.demoa / hr.demob (password: password).');
        if (! $ter) {
            $this->command?->warn('File '.self::FILE_TER.' belum ada: tabel TER dan komponen PPh21 dilewati. Isi file dari peraturan resmi lalu buat ulang demo di database baru.');
        }
    }

    /** Tabel nasional ter_bulanan & kategori_ptkp dari CSV resmi (diisi pemilik). Kembalikan true bila tersedia. */
    private function terNasional(): bool
    {
        if (ReferenceTable::whereNull('company_id')->whereIn('code', ['ter_bulanan', 'kategori_ptkp'])->count() === 2) {
            return true;
        }
        $file = self::$fileTerUji ?? database_path(self::FILE_TER);
        if (! is_file($file)) {
            return false;
        }

        // format: tabel,kunci,batas_bawah,batas_atas,hasil_angka,hasil_teks
        $baris = array_map('str_getcsv', array_filter(array_map('trim', file($file) ?: [])));
        $header = array_map('strtolower', array_shift($baris) ?? []);
        if ($header !== ['tabel', 'kunci', 'batas_bawah', 'batas_atas', 'hasil_angka', 'hasil_teks']) {
            throw new RuntimeException('Header '.self::FILE_TER.' harus: tabel,kunci,batas_bawah,batas_atas,hasil_angka,hasil_teks');
        }

        app(CurrentCompany::class)->lintasPerusahaan('seeder: tabel referensi nasional TER/PTKP', function () use ($baris) {
            foreach (['ter_bulanan' => 'Tarif Efektif Rata-rata (TER) bulanan', 'kategori_ptkp' => 'Kategori TER per status PTKP'] as $kode => $nama) {
                $isi = array_values(array_filter($baris, fn ($b) => ($b[0] ?? '') === $kode));
                if ($isi === []) {
                    throw new RuntimeException("Tidak ada baris tabel {$kode} di ".self::FILE_TER);
                }
                $tabel = ReferenceTable::create(['company_id' => null, 'code' => $kode, 'name' => $nama, 'description' => 'Sumber: '.self::FILE_TER]);
                $versi = ReferenceTableVersion::create(['company_id' => null, 'reference_table_id' => $tabel->id, 'version_no' => 1, 'status' => 'published', 'published_at' => now()]);
                foreach ($isi as $i => [, $kunci, $dari, $sampai, $angka, $teks]) {
                    $versi->rows()->create(['company_id' => null, 'key_value' => $kunci ?: null, 'range_from' => $dari !== '' ? $dari : null,
                        'range_to' => $sampai !== '' ? $sampai : null, 'result_number' => $angka !== '' ? $angka : null, 'result_text' => $teks !== '' ? $teks : null, 'sort_order' => $i]);
                }
            }
        });

        return true;
    }

    /** @param array{start_day: int, end_day: int, start_in_previous_month: bool} $cutoff */
    private function perusahaan(string $kode, string $nama, string $awalan, int $jumlah, array $cutoff, bool $ter, string $varian): void
    {
        $company = app(BuatPerusahaan::class)->handle(['code' => $kode, 'name' => $nama, 'address' => "Jl. Contoh No. 1, Kota Demo ({$kode})",
            'signer_name' => "Direktur {$kode}", 'signer_title' => 'Direktur']);
        (new PasangMenuPayroll)->berikanHak((int) $company->id);

        app(CurrentCompany::class)->runAs((int) $company->id, function () use ($awalan, $jumlah, $cutoff, $ter, $varian, $kode) {
            PeriodConfig::create($cutoff);
            foreach (self::LIBUR_TETAP_2026 as $tgl => $ket) {
                Holiday::create(['holiday_date' => $tgl, 'name' => $ket]);
            }
            foreach (['cuti' => 'Cuti', 'izin' => 'Izin', 'sakit' => 'Sakit', 'sakit_bersurat' => 'Sakit dengan surat dokter'] as $c => $n) {
                LeaveType::create(['code' => $c, 'name' => $n]);
            }
            $jabatan = [Position::create(['code' => 'STF', 'name' => 'Staf']), Position::create(['code' => 'SPV', 'name' => 'Supervisor'])];
            $dept = [Departemen::create(['kode' => 'HRD', 'nama' => 'Human Resource']), Departemen::create(['kode' => 'OPS', 'nama' => 'Operasional'])];
            $pajak = Pajak::orderBy('id')->pluck('id')->all();

            $karyawan = [];
            for ($i = 0; $i < $jumlah; $i++) {
                $karyawan[] = Karyawan::create([
                    'departemen_id' => $dept[$i < 3 ? 0 : 1]->id, 'pajak_id' => $pajak[$i % max(count($pajak), 1)] ?? null,
                    'nik' => sprintf('%s%04d', $awalan, $i + 1), 'nama' => self::NAMA[$i % count(self::NAMA)].($awalan === 'B' ? ' B' : ''),
                    'jabatan' => $jabatan[$i % 2]->name, 'tgl_masuk' => '2024-01-02', 'email' => strtolower($awalan)."{$i}@demo.test",
                    'nama_bank' => 'BANK DEMO', 'no_rekening' => sprintf('9%09d', 1000 + $i * 37), 'status_kepegawaian' => 'kontrak',
                    'sisa_cuti' => 12, 'is_active' => true,
                ]);
                DB::table('karyawan')->where('id', $karyawan[$i]->id)->update(['position_id' => $jabatan[$i % 2]->id]);
            }
            $dept[0]->aturManager([$karyawan[0]->id]);
            $dept[1]->aturManager([$karyawan[0]->id]);

            $role = fn (string $n) => Role::where('nama', $n)->value('id');
            $user = fn (string $u, string $r, ?Karyawan $k) => User::create(['role_id' => $role($r), 'karyawan_id' => $k?->id, 'username' => $u,
                'password' => 'password', 'email' => "{$u}@demo.test", 'is_active' => true]);
            $manager = $user('manager.'.strtolower($kode), Role::MANAGER, $karyawan[0]);
            $hr = $user('hr.'.strtolower($kode), Role::HR, $karyawan[1]);
            $hr->forceFill(['approver_user_id' => $manager->id])->save();
            $user('admin.'.strtolower($kode), Role::ADMIN, null);
            $user('staf.'.strtolower($kode), Role::KARYAWAN, $karyawan[3]);

            $komponen = $this->komponen($varian);
            foreach ($karyawan as $i => $k) {
                $nilai = [$komponen['gaji_pokok']->id => (string) (5000000 + $i * 250000), $komponen['bpjs_tk']->id => (string) (100000 + $i * 5000)];
                if ($varian === 'a') {
                    $nilai += [$komponen['uang_makan']->id => (string) (550000 + ($i % 3) * 50000), $komponen['bpjs_kes']->id => '50000'];
                } else {
                    $nilai += [$komponen['tunjangan_transport']->id => (string) (400000 + ($i % 4) * 25000)];
                }
                app(BuatKontrak::class)->handle(['employee_id' => $k->id, 'contract_name' => "PKWT {$kode} 2026", 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $nilai);
            }

            $this->kehadiran($karyawan, $cutoff['start_in_previous_month'] ? ['2026-09-26', '2026-11-25'] : ['2026-10-01', '2026-11-30']);
            $this->master($varian, $komponen, $ter);
        });
    }

    /** @return array<string, Component> */
    private function komponen(string $varian): array
    {
        $daftar = $varian === 'a'
            ? [['gaji_pokok', 'Gaji Pokok', 'earning', true, true], ['uang_makan', 'Uang Makan', 'earning', true, true], ['lembur', 'Lembur', 'earning', true, false],
                ['potongan_alpha', 'Potongan Alpha', 'deduction', false, false], ['bpjs_tk', 'BPJS Ketenagakerjaan', 'deduction', false, true],
                ['bpjs_kes', 'BPJS Kesehatan', 'deduction', false, true], ['pph21', 'PPh21 TER', 'deduction', false, false]]
            : [['gaji_pokok', 'Gaji Pokok', 'earning', true, true], ['tunjangan_transport', 'Tunjangan Transport', 'earning', true, true],
                ['lembur', 'Lembur', 'earning', true, false], ['potongan_terlambat', 'Potongan Terlambat', 'deduction', false, false],
                ['bpjs_tk', 'BPJS Ketenagakerjaan', 'deduction', false, true], ['pph21', 'PPh21 TER', 'deduction', false, false]];
        $hasil = [];
        foreach ($daftar as [$code, $name, $type, $tax, $kontrak]) {
            $hasil[$code] = Component::create(['code' => $code, 'name' => $name, 'type' => $type, 'is_taxable' => $tax, 'is_contractable' => $kontrak, 'default_amount' => '0']);
        }

        return $hasil;
    }

    /**
     * Absensi 2 periode: sebagian besar hadir; tiap karyawan ke-4 punya 1 hari alpha per bulan, ke-3 terlambat,
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

    /** @param array<string, Component> $c */
    private function master(string $varian, array $c, bool $ter): void
    {
        $kelola = app(KelolaMasterPayroll::class);
        $master = $kelola->buatMaster(['code' => 'usual', 'name' => 'Payroll Usual', 'description' => $varian === 'a' ? 'Uang makan per hari hadir+cuti, lembur Rp25.000/jam' : 'Lembur 1,5x/2x upah jam, potongan terlambat']);
        $v = $master->versions()->firstOrFail();
        $urut = 0;
        $var = function (array $d) use ($kelola, $v, &$urut): void {
            $kelola->simpanVariabel($v, $d + ['name' => $d['code'], 'sort_order' => ++$urut]);
        };
        $item = function (string $code, string $calc, ?string $f, int $urut, string $role = 'normal') use ($kelola, $v, $c): void {
            $kelola->simpanItem($v, ['component_id' => $c[$code]->id, 'code' => $code, 'calc_type' => $calc, 'formula' => $f, 'item_role' => $role, 'sort_order' => $urut]);
        };

        $var(['code' => 'hadir', 'kind' => 'base', 'source' => 'attendances', 'aggregate' => 'count']);
        $var(['code' => 'leave_semua', 'kind' => 'base', 'source' => 'leaves', 'aggregate' => 'count_distinct_date']);
        $var(['code' => 'jam_telat', 'kind' => 'base', 'source' => 'attendances', 'aggregate' => 'sum', 'value_expression' => 'row.late_hours']);

        if ($varian === 'a') {
            $kelola->simpanParam($v, ['code' => 'pembagi_uang_makan', 'name' => 'Pembagi uang makan', 'value' => '22']);
            $kelola->simpanParam($v, ['code' => 'tarif_lembur', 'name' => 'Tarif lembur per jam', 'value' => '25000']);
            $var(['code' => 'cuti', 'kind' => 'base', 'source' => 'leaves', 'aggregate' => 'count', 'filter_expression' => "row.type = 'cuti'"]);
            $var(['code' => 'jam_lembur', 'kind' => 'base', 'source' => 'overtimes', 'aggregate' => 'sum', 'value_expression' => 'row.hours']);
            $var(['code' => 'alpha', 'kind' => 'derived', 'formula' => 'MAX(workdays - hadir - leave_semua, 0)']);
            $item('gaji_pokok', 'contract', null, 1);
            $item('uang_makan', 'formula', "CONTRACT('uang_makan') / pembagi_uang_makan * (hadir + cuti)", 2);
            $item('lembur', 'formula', 'tarif_lembur * jam_lembur', 3);
            $item('potongan_alpha', 'formula', 'gaji_pokok / workdays * alpha', 4);
            $item('bpjs_tk', 'contract', null, 5);
            $item('bpjs_kes', 'contract', null, 6);
        } else {
            $kelola->simpanParam($v, ['code' => 'pembagi_upah_jam', 'name' => 'Pembagi upah per jam', 'value' => '173']);
            $kelola->simpanParam($v, ['code' => 'tarif_terlambat', 'name' => 'Potongan per jam terlambat', 'value' => '20000']);
            $var(['code' => 'jam_lembur_kerja', 'kind' => 'base', 'source' => 'overtimes', 'aggregate' => 'sum', 'value_expression' => 'row.hours', 'filter_expression' => 'row.weekday <= 5 AND NOT row.is_holiday']);
            $var(['code' => 'jam_lembur_libur', 'kind' => 'base', 'source' => 'overtimes', 'aggregate' => 'sum', 'value_expression' => 'row.hours', 'filter_expression' => 'row.weekday > 5 OR row.is_holiday']);
            $item('gaji_pokok', 'contract', null, 1);
            $item('tunjangan_transport', 'contract', null, 2);
            $item('lembur', 'formula', 'gaji_pokok / pembagi_upah_jam * (jam_lembur_kerja * 1.5 + jam_lembur_libur * 2)', 3);
            $item('potongan_terlambat', 'formula', 'tarif_terlambat * jam_telat', 4);
            $item('bpjs_tk', 'contract', null, 5);
        }
        if ($ter) {
            $item('pph21', 'formula', "ROUND(gross_taxable * LOOKUP('ter_bulanan', LOOKUP_TEXT('kategori_ptkp', ptkp_status), gross_taxable))", 99, 'pph21');
        }
        $kelola->terbitkan($v);
    }
}
