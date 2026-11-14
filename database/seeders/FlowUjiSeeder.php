<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Company\Actions\BuatPerusahaan;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Actions\AlurPayroll;
use App\Domain\Payroll\Actions\KelolaMasterPayroll;
use App\Domain\Payroll\Models\Attendance;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Holiday;
use App\Domain\Payroll\Models\Item;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\Overtime;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\WorkPattern;
use App\Models\Departemen;
use App\Models\Karyawan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Data uji alur payroll dari awal sampai slip (AUDIT.md §67), di perusahaan MTS (PT Maju Terus Sejahtera).
 * Tidak menyentuh data perusahaan lain. Aman dijalankan ulang: bila perusahaan UJI sudah ada, tidak ada yang diubah.
 *
 *   php artisan db:seed --class=FlowUjiSeeder
 *
 * Login (password: password): superadmin (Super Admin platform), admin.mts, hr.mts, manager.mts (Manager HR = penyetuju), staf.mts (karyawan Operasional).
 * Isi: 3 departemen (HR, Operasional pola Senin–Minggu, Finance), kontrak tetap / hampir habis / masih lama,
 * karyawan baru masuk & resign di tengah periode (prorata hari kerja), Master Payroll terbit, Kalender Payroll
 * (Okt 2026 final, Nov 2026 draft dengan penyesuaian, Des 2026 belum diproses), hari libur nasional/cuti bersama/khusus Finance,
 * absensi, leave, lembur. Semua data fiktif.
 */
final class FlowUjiSeeder extends Seeder
{
    public const KODE = 'MTS';

    public const SUPER_ADMIN = 'superadmin';

    public function run(): void
    {
        $this->superAdmin();
        if (Company::where('code', self::KODE)->exists()) {
            $this->command?->warn('Perusahaan '.self::KODE.' sudah ada. Tidak ada yang diubah. (Hapus manual dulu bila ingin dibuat ulang.)');

            return;
        }
        if (! (new TerPph21Seeder)->pasang()) {
            throw new RuntimeException('File '.TerPph21Seeder::FILE.' belum ada; tabel TER dibutuhkan untuk PPh21.');
        }

        $company = app(BuatPerusahaan::class)->handle(['code' => self::KODE, 'name' => 'PT Maju Terus Sejahtera', 'address' => 'Jl. Merdeka No. 17, Jakarta Pusat',
            'signer_name' => 'Hendra Gunawan', 'signer_title' => 'Direktur Utama']);
        $komponen = (new DataPerusahaanSeeder)->isi($company, [
            'jumlah' => 12, 'varian' => 'a', 'awalan' => 'U',
            'cutoff' => ['start_day' => 26, 'end_day' => 25, 'start_in_previous_month' => true],
            'rentang' => ['2026-09-26', '2026-12-25'],
        ]);

        ini_set('memory_limit', '512M'); // slip PDF Oktober dibuat langsung (dompdf)
        $antrean = config('queue.default');
        config(['queue.default' => 'sync']); // preview/generate/slip langsung diproses di seeder
        try {
            app(CurrentCompany::class)->runAs((int) $company->id, function () use ($komponen) {
                $this->rapikanData($komponen);
                $master = $this->master($komponen);
                $this->payroll($master);
            });
        } finally {
            config(['queue.default' => $antrean]);
            Auth::logout();
        }

        $this->command?->info('Data alur payroll dibuat di perusahaan MTS. Login: admin.mts, hr.mts, manager.mts, staf.mts (password: password).');
    }

    /** Akun Super Admin platform (company_id NULL) untuk uji lintas perusahaan; dibuat bila belum ada. Password dev: password. */
    private function superAdmin(): void
    {
        if (\Illuminate\Support\Facades\DB::table('users')->where('username', self::SUPER_ADMIN)->exists()) {
            return;
        }
        $roleId = (new \App\Domain\Company\Actions\PasangHakPlatform)->handle();
        \Illuminate\Support\Facades\DB::table('users')->insert(['company_id' => null, 'role_id' => $roleId, 'username' => self::SUPER_ADMIN,
            'email' => 'superadmin@platform.test', 'password' => \Illuminate\Support\Facades\Hash::make(DataPerusahaanSeeder::PASSWORD),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, Component> $komponen */
    private function rapikanData(array $komponen): void
    {
        // departemen: HRD (dari DataPerusahaanSeeder), OPS, + Finance
        $hrd = Departemen::where('kode', 'HRD')->firstOrFail();
        $ops = Departemen::where('kode', 'OPS')->firstOrFail();
        $fin = Departemen::create(['kode' => 'FIN', 'nama' => 'Finance']);
        $k = Karyawan::orderBy('id')->get()->values();
        foreach ([8, 9] as $i) {
            $k[$i]->forceFill(['departemen_id' => $fin->id])->save();
        }
        $fin->aturManager([$k[8]->id]);

        // pola hari kerja: Kantor (Senin–Jumat, bawaan) dan Operasional (Senin–Minggu) untuk departemen OPS
        WorkPattern::create(['code' => 'kantor', 'name' => 'Kantor Senin–Jumat', 'work_days' => '1,2,3,4,5', 'is_default' => true]);
        $p7 = WorkPattern::create(['code' => 'operasional', 'name' => 'Operasional Senin–Minggu', 'work_days' => '1,2,3,4,5,6,7']);
        $ops->forceFill(['pay_work_pattern_id' => $p7->id])->save();
        // absensi akhir pekan untuk karyawan OPS (DataPerusahaanSeeder hanya mengisi Senin–Jumat)
        foreach ($k->where('departemen_id', $ops->id)->take(3) as $karyawan) {
            foreach (CarbonPeriod::create('2026-09-26', '2026-12-25') as $d) {
                if ($d->isWeekend() && ! Attendance::where('employee_id', $karyawan->id)->whereDate('attendance_date', $d)->exists()) {
                    Attendance::create(['employee_id' => $karyawan->id, 'attendance_date' => $d->toDateString(), 'check_in' => '07:55:00', 'check_out' => '17:00:00', 'late_hours' => '0']);
                }
            }
        }

        // hari libur tambahan: cuti bersama dan libur khusus Finance
        Holiday::create(['holiday_date' => '2026-12-24', 'type' => 'cuti_bersama', 'name' => 'Cuti bersama Natal']);
        Holiday::create(['holiday_date' => '2026-12-11', 'type' => 'perusahaan', 'departemen_id' => $fin->id, 'name' => 'Libur tutup buku Finance']);

        // kontrak: sebagian besar tetap; satu hampir habis (H-3 bulan), satu masih lama
        Contract::query()->update(['end_date' => null, 'contract_name' => 'PKWTT']);
        Contract::where('employee_id', $k[4]->id)->update(['end_date' => '2027-01-31', 'contract_name' => 'PKWT']);
        Contract::where('employee_id', $k[5]->id)->update(['end_date' => '2027-12-31', 'contract_name' => 'PKWT']);

        // prorata hari kerja: karyawan baru masuk 10 Nov dan karyawan resign 15 Nov 2026
        $baru = $k[10];
        $baru->forceFill(['tgl_masuk' => '2026-11-10', 'nama' => 'Rani Karyawan Baru'])->save();
        Contract::where('employee_id', $baru->id)->update(['start_date' => '2026-11-10', 'contract_name' => 'PKWT']);
        Attendance::where('employee_id', $baru->id)->where('attendance_date', '<', '2026-11-10')->delete();
        Overtime::where('employee_id', $baru->id)->where('overtime_date', '<', '2026-11-10')->delete();
        Employee::whereKey($k[11]->id)->update(['resign_date' => '2026-11-15']);
        $k[11]->forceFill(['nama' => 'Sapto Karyawan Resign'])->save();
        Attendance::where('employee_id', $k[11]->id)->where('attendance_date', '>', '2026-11-15')->delete();
        DataPerusahaanSeeder::namaiKontrak(); // nama kontrak berupa kode, mis. PKWTT/HRD/0001/I/2026

        // jenis penyesuaian di Katalog Komponen
        Component::create(['code' => 'bonus', 'name' => 'Bonus', 'type' => 'earning', 'is_taxable' => true, 'is_contractable' => false, 'default_amount' => '0']);
        Component::create(['code' => 'reimburse', 'name' => 'Reimburse', 'type' => 'earning', 'is_taxable' => false, 'is_contractable' => false, 'default_amount' => '0']);
    }


    /**
     * BPJS dihitung otomatis dari gaji pokok kontrak (AUDIT.md §73): MIN(gaji pokok, batas upah) × tarif %.
     * Tarif dan batas upah adalah parameter Master Payroll yang diubah HR; batas 0 = tanpa batas (diisi HR sesuai peraturan).
     */
    public static function pasangBpjs(\App\Domain\Payroll\Actions\KelolaMasterPayroll $kelola, \App\Domain\Payroll\Models\MasterVersion $v, \Closure $item, array $kode, int $urut): void
    {
        $nama = ['bpjs_tk' => ['tk', 'BPJS Ketenagakerjaan', '2'], 'bpjs_kes' => ['kes', 'BPJS Kesehatan', '1']];
        foreach ($kode as $k) {
            [$s, $n, $tarif] = $nama[$k];
            $kelola->simpanParam($v, ['code' => "tarif_bpjs_{$s}", 'name' => "Tarif {$n} karyawan (%)", 'value' => $tarif]);
            $kelola->simpanParam($v, ['code' => "batas_upah_bpjs_{$s}", 'name' => "Batas upah {$n} (0 = tanpa batas)", 'value' => '0']);
            $item($k, 'formula', "ROUND(IF(batas_upah_bpjs_{$s} > 0, MIN(CONTRACT('gaji_pokok'), batas_upah_bpjs_{$s}), CONTRACT('gaji_pokok')) * tarif_bpjs_{$s} / 100)", $urut++);
        }
    }

    /** Master "Payroll Default": uang makan per hadir+cuti, lembur per jam, potongan alpha, BPJS dari gaji pokok, PPh21 TER otomatis. */
    private function master(array $c): Master
    {
        $kelola = app(KelolaMasterPayroll::class);
        $master = $kelola->buatMaster(['code' => 'default', 'name' => 'Payroll Default', 'description' => 'Gaji bulanan rutin']);
        $v = $master->versions()->firstOrFail();
        $urut = 0;
        $var = function (array $d) use ($kelola, $v, &$urut): void {
            $kelola->simpanVariabel($v, $d + ['name' => $d['code'], 'sort_order' => ++$urut]);
        };
        $item = fn (string $code, string $calc, ?string $f, int $urut) => $kelola->simpanItem($v, ['component_id' => $c[$code]->id, 'code' => $code, 'calc_type' => $calc, 'formula' => $f, 'item_role' => 'normal', 'sort_order' => $urut]);

        $kelola->simpanParam($v, ['code' => 'pembagi_uang_makan', 'name' => 'Pembagi uang makan', 'value' => '22']);
        $kelola->simpanParam($v, ['code' => 'tarif_lembur', 'name' => 'Tarif lembur per jam', 'value' => '25000']);
        $var(['code' => 'hadir', 'kind' => 'base', 'source' => 'attendances', 'aggregate' => 'count']);
        $var(['code' => 'leave_semua', 'kind' => 'base', 'source' => 'leaves', 'aggregate' => 'count_distinct_date']);
        $var(['code' => 'cuti', 'kind' => 'base', 'source' => 'leaves', 'aggregate' => 'count', 'filter_expression' => "row.type = 'cuti'"]);
        $var(['code' => 'jam_lembur', 'kind' => 'base', 'source' => 'overtimes', 'aggregate' => 'sum', 'value_expression' => 'row.hours']);
        $var(['code' => 'alpha', 'kind' => 'derived', 'formula' => 'MAX(paid_workdays - hadir - leave_semua, 0)']);
        $item('gaji_pokok', 'contract', null, 1);
        $item('uang_makan', 'formula', "CONTRACT('uang_makan') / pembagi_uang_makan * (hadir + cuti)", 2);
        $item('lembur', 'formula', 'tarif_lembur * jam_lembur', 3);
        $item('potongan_alpha', 'formula', 'gaji_pokok / paid_workdays * alpha', 4);
        self::pasangBpjs($kelola, $v, $item, ['bpjs_tk', 'bpjs_kes'], 5);
        $kelola->simpanItem($v, ['component_id' => $c['pph21']->id, 'code' => 'pph21', 'calc_type' => Item::CARA_PPH21_TER, 'formula' => null, 'item_role' => 'normal', 'sort_order' => 99]);
        $kelola->terbitkan($v);

        return $master->fresh();
    }

    /** Oktober 2026 final (disetujui Manager HR), November 2026 draft + penyesuaian, Desember 2026 belum diproses. */
    private function payroll(Master $master): void
    {
        $alur = app(AlurPayroll::class);
        $hr = User::where('username', DataPerusahaanSeeder::username(self::KODE)['hr'])->firstOrFail();
        $manager = User::where('username', DataPerusahaanSeeder::username(self::KODE)['manager'])->firstOrFail();
        Auth::setUser($hr);

        $okt = $alur->buat('Payroll Oktober 2026', '2026-10');
        $alur->mulaiPratinjau($okt, (int) $master->id, 'all', []);
        $alur->generate($okt->fresh()); // default tanpa approval: langsung final
        if ($okt->fresh()->status === PayrollRun::PENDING) {
            $alur->setujui($okt->fresh(), $manager);
        }

        $nov = $alur->buat('Payroll November 2026', '2026-11');
        $alur->mulaiPratinjau($nov, (int) $master->id, 'all', []);
        $candra = Employee::orderBy('id')->skip(2)->firstOrFail();
        $alur->tambahPenyesuaian($nov->fresh(), ['employee_id' => (int) $candra->id, 'component_id' => (int) Component::where('code', 'bonus')->value('id'), 'amount' => '1000000', 'note' => 'Bonus kinerja Q3']);
        $alur->tambahPenyesuaian($nov->fresh(), ['employee_id' => (int) $candra->id, 'component_id' => (int) Component::where('code', 'reimburse')->value('id'), 'amount' => '250000', 'note' => 'Reimburse transport dinas']);

        if (PayrollRun::where('title', 'Payroll Oktober 2026')->value('status') !== PayrollRun::APPROVED) {
            throw new RuntimeException('Payroll Oktober gagal disetujui; periksa master/TER.');
        }
    }
}
