<?php

declare(strict_types=1);

namespace Tests\Feature\PayrollBaru;

use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Models\Attendance;
use App\Domain\Payroll\Models\ImportBatch;
use App\Domain\Payroll\Models\Leave;
use App\Domain\Payroll\Models\LeaveType;
use App\Domain\Payroll\Models\Overtime;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\MultiTenant\BuatPerusahaanLain;
use Tests\TestCase;

/** Fase 4: absensi, leave, lembur + import CSV dengan preview. */
final class KehadiranImportTest extends TestCase
{
    use BuatPerusahaanLain;
    use RefreshDatabase;

    protected bool $seed = true;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('payroll:aktifkan', ['kode' => 'DEFAULT']);
        $this->hr = User::where('username', 'hr')->firstOrFail();
        foreach (['cuti' => 'Cuti', 'izin' => 'Izin', 'sakit_bersurat' => 'Sakit dengan surat'] as $code => $name) {
            LeaveType::create(['code' => $code, 'name' => $name]);
        }
    }

    private function unggah(string $jenis, string $csv): ImportBatch
    {
        $this->actingAs($this->hr)->post('/pay/import', ['jenis' => $jenis, 'file' => UploadedFile::fake()->createWithContent("{$jenis}.csv", $csv)])
            ->assertSessionHasNoErrors();

        return ImportBatch::latest('id')->firstOrFail();
    }

    /** @return array<int, ?string> row_no => error */
    private function hasil(ImportBatch $b): array
    {
        return $b->rows()->get()->mapWithKeys(fn ($r) => [$r->row_no => $r->error_message])->all();
    }

    public function test_import_absensi_preview_perbaiki_hapus_lalu_simpan(): void
    {
        Leave::create(['employee_id' => $this->idKaryawan('K0002'), 'leave_type_id' => LeaveType::where('code', 'cuti')->value('id'), 'leave_date' => '2026-11-03']);
        $csv = "nik,tanggal,jam_masuk,jam_keluar,jam_terlambat\n"
            ."K0001,2026-11-02,08:00,17:00,0\n"
            ."XXXX,2026-11-02,08:00,17:00,0\n"
            ."K0001,02-11-2026,08:00,17:00,0\n"
            ."K0001,2026-11-02,08:10,17:00,0.25\n"
            ."K0002,2026-11-03,08:00,17:00,1.5\n";
        $batch = $this->unggah('absensi', $csv);

        $e = $this->hasil($batch);
        $this->assertNull($e[2]);
        $this->assertStringContainsString('NIK XXXX tidak ditemukan', $e[3]);
        $this->assertStringContainsString('YYYY-MM-DD', $e[4]);
        $this->assertStringContainsString('lebih dari sekali', $e[5]);
        $this->assertStringContainsString('bentrok', $e[6]);
        $this->assertSame([1, 5], [$batch->fresh()->valid_rows, $batch->fresh()->total_rows]);

        $this->actingAs($this->hr)->get("/pay/import/{$batch->id}")->assertOk()->assertSee('table-danger');
        $this->actingAs($this->hr)->from("/pay/import/{$batch->id}")->post("/pay/import/{$batch->id}/simpan")->assertSessionHasErrors('batch');
        $this->assertSame(0, Attendance::count());

        $baris = fn (int $no) => $batch->rows()->where('row_no', $no)->value('id');
        $this->actingAs($this->hr)->put("/pay/import/{$batch->id}/baris/{$baris(3)}", ['nik' => 'K0003', 'tanggal' => '2026-11-02', 'jam_masuk' => '08:00', 'jam_keluar' => '17:00', 'jam_terlambat' => '0']);
        $this->actingAs($this->hr)->put("/pay/import/{$batch->id}/baris/{$baris(4)}", ['nik' => 'K0001', 'tanggal' => '2026-11-04', 'jam_masuk' => '08:00', 'jam_keluar' => '17:00', 'jam_terlambat' => '0']);
        foreach ([5, 6] as $no) {
            $this->actingAs($this->hr)->delete("/pay/import/{$batch->id}/baris/{$baris($no)}");
        }
        $this->assertSame([3, 3], [$batch->fresh()->valid_rows, $batch->fresh()->total_rows]);

        $this->actingAs($this->hr)->post("/pay/import/{$batch->id}/simpan")->assertRedirect(route('pay.import.index'));
        $this->assertSame(3, Attendance::where('import_batch_id', $batch->id)->count());
        $this->assertSame('committed', $batch->fresh()->status);
        $this->actingAs($this->hr)->post("/pay/import/{$batch->id}/simpan")->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => ImportBatch::class, 'action' => 'imported']);
    }

    public function test_leave_rentang_melewati_akhir_pekan_dan_bentrok_dengan_absensi(): void
    {
        Attendance::create(['employee_id' => $this->idKaryawan('K0002'), 'attendance_date' => '2026-11-09', 'late_hours' => '0']);
        $csv = "nik,kode_tipe,tanggal_mulai,tanggal_selesai,keterangan\n"
            ."K0001,cuti,2026-11-06,2026-11-09,Liburan\n"   // Jumat s/d Senin
            ."K0001,izin,2026-11-14,2026-11-15,\n"           // Sabtu-Minggu saja
            ."K0001,tidak_ada,2026-11-10,,\n"
            ."K0002,sakit_bersurat,2026-11-09,2026-11-09,\n"; // bentrok absensi
        $batch = $this->unggah('leave', $csv);

        $e = $this->hasil($batch);
        $this->assertNull($e[2]);
        $this->assertStringContainsString('hanya berisi Sabtu/Minggu', $e[3]);
        $this->assertStringContainsString('tidak_ada tidak ditemukan', $e[4]);
        $this->assertStringContainsString('bentrok', $e[5]);

        foreach ([3, 4, 5] as $no) {
            $this->actingAs($this->hr)->delete("/pay/import/{$batch->id}/baris/".$batch->rows()->where('row_no', $no)->value('id'));
        }
        $this->actingAs($this->hr)->post("/pay/import/{$batch->id}/simpan");

        $this->assertSame(['2026-11-06', '2026-11-09'], Leave::orderBy('leave_date')->get()->map(fn ($l) => $l->leave_date->toDateString())->all());

        // absensi di tanggal leave ditolak
        $this->actingAs($this->hr)->from('/pay/absensi')->post('/pay/absensi', ['nik' => 'K0001', 'tanggal' => '2026-11-09', 'jam_masuk' => '08:00'])
            ->assertSessionHasErrors('data');
    }

    public function test_import_lembur_angka_dan_pemisah_titik_koma(): void
    {
        $csv = "\xEF\xBB\xBFnik;tanggal;durasi_jam;keterangan\n"
            ."K0001;2026-11-07;2,5;Sabtu\n"
            ."K0001;2026-11-07;1;lagi\n"
            ."K0001;2026-11-08;0;nol\n"
            ."K0001;2026-11-09;25;terlalu banyak\n";
        $batch = $this->unggah('lembur', $csv);
        $e = $this->hasil($batch);
        $this->assertNull($e[2]);
        $this->assertNull($e[3], 'lembur boleh lebih dari satu per hari');
        $this->assertStringContainsString('lebih dari 0', $e[4]);
        $this->assertStringContainsString('sampai 24', $e[5]);

        $this->actingAs($this->hr)->from('/pay/import')
            ->post('/pay/import', ['jenis' => 'lembur', 'file' => UploadedFile::fake()->createWithContent('x.csv', "nik,salah\nK0001,1\n")])
            ->assertSessionHasErrors('file');

        foreach ([4, 5] as $no) {
            $this->actingAs($this->hr)->delete("/pay/import/{$batch->id}/baris/".$batch->rows()->where('row_no', $no)->value('id'));
        }
        $this->actingAs($this->hr)->post("/pay/import/{$batch->id}/simpan");
        $this->assertSame(['2.50', '1.00'], Overtime::orderBy('id')->pluck('duration_hours')->all());
    }

    public function test_input_manual_tambah_ubah_hapus(): void
    {
        $this->actingAs($this->hr)->post('/pay/absensi', ['nik' => 'K0001', 'tanggal' => '2026-11-02', 'jam_masuk' => '08:05', 'jam_keluar' => '17:00', 'jam_terlambat' => '0.08'])
            ->assertSessionHasNoErrors();
        $a = Attendance::firstOrFail();
        $this->assertSame(['08:05:00', '0.08'], [$a->check_in, $a->late_hours]);

        $this->actingAs($this->hr)->put("/pay/absensi/{$a->id}", ['nik' => 'K0001', 'tanggal' => '2026-11-03', 'jam_masuk' => '08:00', 'jam_keluar' => '17:00', 'jam_terlambat' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-11-03', $a->fresh()->attendance_date->toDateString());

        $this->actingAs($this->hr)->post('/pay/leave', ['nik' => 'K0002', 'kode_tipe' => 'cuti', 'tanggal_mulai' => '2026-11-12', 'tanggal_selesai' => '2026-11-16'])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, Leave::count(), 'Kamis, Jumat, Senin');

        $this->actingAs($this->hr)->delete("/pay/absensi/{$a->id}")->assertRedirect();
        $this->assertSame(0, Attendance::count());
        $this->actingAs($this->hr)->get('/pay/leave?periode=2026-11')->assertOk()->assertSee('Cuti');
        $this->assertSame(['created', 'updated', 'deleted'], DB::table('audit_logs')->where('entity_type', Attendance::class)->orderBy('id')->pluck('action')->all());
    }

    public function test_data_perusahaan_lain_tidak_bisa_dirujuk(): void
    {
        $b = $this->perusahaanLain();
        $batch = $this->unggah('absensi', "nik,tanggal,jam_masuk,jam_keluar,jam_terlambat\n{$b['karyawan']->nik},2026-11-02,08:00,17:00,0\n");
        $this->assertStringContainsString('tidak ditemukan', (string) $batch->rows()->value('error_message'));

        $batchB = app(CurrentCompany::class)->runAs((int) $b['company']->id,
            fn () => ImportBatch::create(['type' => 'attendances', 'file_name' => 'b.csv', 'status' => 'previewing']));
        $this->actingAs($this->hr)->get("/pay/import/{$batchB->id}")->assertNotFound();
        $this->actingAs($this->hr)->post("/pay/import/{$batchB->id}/simpan")->assertNotFound();
    }

    private function idKaryawan(string $nik): int
    {
        return (int) DB::table('karyawan')->where('nik', $nik)->where('company_id', $this->defaultId())->value('id');
    }
}
