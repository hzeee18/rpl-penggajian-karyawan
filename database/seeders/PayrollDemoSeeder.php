<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Company\Actions\BuatPerusahaan;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Actions\KelolaMasterPayroll;
use App\Domain\Payroll\Models\Component;
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
    public const FILE_TER = TerPph21Seeder::FILE;

    /** Hanya untuk tes: lokasi file TER lain (berisi angka uji). Null = file resmi FILE_TER. */
    public static ?string $fileTerUji = null;

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
        return (new TerPph21Seeder)->pasang(self::$fileTerUji);
    }

    /** @param array{start_day: int, end_day: int, start_in_previous_month: bool} $cutoff */
    private function perusahaan(string $kode, string $nama, string $awalan, int $jumlah, array $cutoff, bool $ter, string $varian): void
    {
        $company = app(BuatPerusahaan::class)->handle(['code' => $kode, 'name' => $nama, 'address' => "Jl. Contoh No. 1, Kota Demo ({$kode})",
            'signer_name' => "Direktur {$kode}", 'signer_title' => 'Direktur']);

        $komponen = (new DataPerusahaanSeeder)->isi($company, [
            'jumlah' => $jumlah, 'cutoff' => $cutoff, 'varian' => $varian, 'awalan' => $awalan, 'akhiran_nama' => $awalan === 'B' ? ' B' : '',
            'rentang' => $cutoff['start_in_previous_month'] ? ['2026-09-26', '2026-11-25'] : ['2026-10-01', '2026-11-30'],
        ]);
        app(CurrentCompany::class)->runAs((int) $company->id, fn () => $this->master($varian, $komponen, $ter));
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
