<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Domain\Company\Models\Company;
use App\Domain\Payroll\Actions\BuatKontrak;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Position;
use App\Models\Karyawan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Keputusan pemilik (AUDIT.md §29): modul payroll baru diterima. Perusahaan yang beralih memakai form Data Karyawan
 * gabungan, menu Kontrak/Proses Payroll/Riwayat/Slip/Komponen lama dialihkan, Profil/Dashboard/cek lembur dari kontrak baru.
 */
final class ModulPayrollTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    private function beralih(): void
    {
        $this->artisan('payroll:aktifkan', ['kode' => Company::DEFAULT_CODE])->assertSuccessful();
    }

    private function kontrakBaru(Karyawan $k): void
    {
        $gp = Component::create(['code' => 'gaji_pokok', 'name' => 'Gaji Pokok', 'type' => 'earning', 'is_taxable' => true, 'is_contractable' => true, 'default_amount' => '0']);
        app(BuatKontrak::class)->handle(['employee_id' => $k->id, 'contract_name' => 'PKWT Uji 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$gp->id => '7250000']);
    }

    public function test_dashboard_dan_absensi_staf_dari_payroll_baru_tanpa_slip(): void
    {
        $this->beralih();
        $rizky = $this->user('rizky');
        $k = Karyawan::findOrFail($rizky->karyawan_id);
        $sakit = \App\Domain\Payroll\Models\LeaveType::create(['code' => 'SKT', 'name' => 'Sakit Uji']);
        $tgl = \App\Domain\Payroll\KehadiranKaryawan::rentang(now()->format('Y-m'))[0];
        \App\Domain\Payroll\Models\Attendance::create(['employee_id' => $k->id, 'attendance_date' => $tgl->toDateString(), 'check_in' => '08:30', 'check_out' => '17:00', 'late_hours' => '0.5']);
        \App\Domain\Payroll\Models\Leave::create(['employee_id' => $k->id, 'leave_type_id' => $sakit->id, 'leave_date' => $tgl->addDay()->toDateString(), 'note' => 'Demam uji']);
        \App\Domain\Payroll\Models\Overtime::create(['employee_id' => $k->id, 'overtime_date' => $tgl->toDateString(), 'duration_hours' => '2.5', 'note' => 'Lembur uji']);

        $this->actingAs($rizky)->get('/dashboard')->assertOk()
            ->assertSee('1 Sakit Uji')->assertSee('2,5 jam')->assertSee('1 kali terlambat')
            ->assertDontSee('Slip gaji belum tersedia')->assertDontSee('Gaji bersih')->assertDontSee('Unduh Slip');

        $this->actingAs($rizky)->get('/absensi')->assertOk()
            ->assertSee('Absensi, Leave &amp; Lembur', false)->assertSee('Demam uji')->assertSee('08:30 – 17:00')->assertSee('Sakit Uji');
        $this->actingAs($rizky)->get('/absensi?filter=leave')->assertOk()->assertSee('Demam uji')->assertDontSee('08:30 – 17:00');

        // menu Cuti & Lembur lama sudah dihapus (AUDIT.md §68)
        $this->actingAs($rizky)->get('/cuti')->assertNotFound();
        $this->actingAs($rizky)->get('/lembur')->assertNotFound();
    }

    public function test_menu_dan_dashboard_hr_sesuai_payroll_baru(): void
    {
        $this->beralih();
        $hr = $this->user('hr');
        $html = $this->actingAs($hr)->get('/dashboard')->assertOk()
            ->assertSee('Kontrak Payroll Aktif')->assertSee('Input Leave & Lembur Terbaru', false)->assertSee('tanpa kontrak payroll')
            ->assertSee(route('pay.import.index'))
            ->getContent();

        preg_match_all('#<a href="https?://[^/"]+/([^"]*)" class="nav-item-link#', $html, $m);
        $this->assertEqualsCanonicalizing(
            ['dashboard', 'karyawan', 'users', 'pay/komponen', 'pay/libur', 'pay/kontrak', 'pay/absensi', 'pay/leave', 'pay/lembur', 'pay/import', 'pay/master', 'pay/run', 'pay/slip'],
            array_values(array_unique($m[1])),
        );
        // halaman modul lama sudah dihapus (AUDIT.md §68)
        foreach (['/import', '/master-payroll', '/pajak', '/kontrak', '/payroll', '/laporan', '/komponen', '/slip', '/approval', '/pay/karyawan'] as $url) {
            $this->actingAs($hr)->get($url)->assertNotFound();
        }
        $this->actingAs($hr)->get('/absensi')->assertForbidden();
    }

    /** AUDIT.md §77: penyetuju diatur sendiri (user dan/atau role, semua bisa dipilih), wajib bila approval dipakai. */
    public function test_penyetuju_payroll_diatur_lewat_role_atau_user(): void
    {
        $this->beralih();
        $admin = $this->user('admin');
        $this->actingAs($admin)->get('/users/create')->assertOk()->assertDontSee('Approver payroll');
        $this->assertSame([], \App\Domain\Payroll\Run\PenyetujuPayroll::daftar()->all(), 'tanpa pilihan, tidak ada penyetuju otomatis');
        $this->actingAs($admin)->get('/pay/approver')->assertOk()->assertSee('Siapa yang menyetujui?')->assertSee('rizky');

        $manager = \App\Models\Role::where('nama', 'Manager')->value('id');
        // "Ya" tanpa penyetuju ditolak
        $this->actingAs($admin)->put('/pay/approver/penyetuju', ['payroll_butuh_approval' => 1])->assertSessionHasErrors('penyetuju');
        // pilih role Manager: semua Manager (termasuk manager.it di luar HR) jadi penyetuju
        $this->actingAs($admin)->put('/pay/approver/penyetuju', ['payroll_butuh_approval' => 1, 'role_id' => [$manager]])->assertSessionHasNoErrors();
        $this->assertContains('manager.it', \App\Domain\Payroll\Run\PenyetujuPayroll::daftar()->pluck('username')->all());
        // pilih satu user saja, bahkan role Karyawan: dia bisa membuka Approval walau role-nya tanpa hak menu
        $rizky = $this->user('rizky');
        $this->actingAs($admin)->put('/pay/approver/penyetuju', ['payroll_butuh_approval' => 1, 'user_id' => [$rizky->id]])->assertSessionHasNoErrors();
        $this->assertSame(['rizky'], \App\Domain\Payroll\Run\PenyetujuPayroll::daftar()->pluck('username')->all());
        $this->assertFalse(\App\Domain\Payroll\Run\PenyetujuPayroll::boleh($this->user('manager.it')));
        $this->actingAs($rizky)->get('/pay/approval')->assertOk();
        $this->actingAs($this->user('manager.it'))->get('/pay/approval')->assertOk(); // punya hak menu: tetap bisa melihat, tanpa tombol putuskan
        // id yang tidak ada ditolak
        $this->actingAs($admin)->put('/pay/approver/penyetuju', ['payroll_butuh_approval' => 1, 'user_id' => [999999]])->assertSessionHasErrors('user_id.0');
        // "Tidak" boleh tanpa penyetuju
        $this->actingAs($admin)->put('/pay/approver/penyetuju', ['payroll_butuh_approval' => 0])->assertSessionHasNoErrors();
        $this->assertFalse(\App\Domain\Payroll\Actions\AlurPayroll::butuhApproval((int) $admin->company_id));
        // HR tanpa hak ubah Approver tidak bisa mengganti
        $this->actingAs($this->user('hr'))->put('/pay/approver/penyetuju', ['payroll_butuh_approval' => 1, 'role_id' => [$manager]])->assertForbidden();
    }

    public function test_form_data_karyawan_memuat_data_payroll(): void
    {
        $this->beralih();
        $hr = $this->user('hr');
        $jabatan = Position::create(['code' => 'PRG', 'name' => 'Programmer']);
        $k = Karyawan::where('nama', 'Rizky Maulana')->firstOrFail();

        $this->actingAs($hr)->get("/karyawan/{$k->id}/edit")->assertOk()->assertSee('Data payroll')->assertSee('<option value="'.$jabatan->id.'"', false)->assertSee('Programmer')
            ->assertDontSee('Jabatan payroll')->assertDontSee('name="jabatan"', false);

        $isi = collect($k->only(['nik', 'nama', 'jenis_kelamin', 'departemen_id', 'jabatan', 'pajak_id', 'status_kepegawaian', 'email', 'telepon', 'alamat', 'nama_bank', 'no_rekening', 'sisa_cuti']))
            ->put('tgl_masuk', $k->tgl_masuk->toDateString())->put('is_active', 1)->all();
        $this->actingAs($hr)->put("/karyawan/{$k->id}", $isi + ['data_payroll' => 1, 'position_id' => $jabatan->id, 'resign_date' => '2026-12-31', 'has_npwp' => 1, 'npwp' => '12.345.678.9-012.345'])
            ->assertSessionHasNoErrors();

        $k->refresh();
        $this->assertSame([$jabatan->id, '2026-12-31', true, '12.345.678.9-012.345'], [$k->position_id, $k->resign_date->toDateString(), $k->has_npwp, $k->npwp]);
        $this->assertSame('Programmer', $k->jabatan, 'teks jabatan mengikuti master Jabatan');
        $this->assertStringNotContainsString('12.345.678', (string) DB::table('karyawan')->where('id', $k->id)->value('npwp'), 'NPWP terenkripsi');
        $log = DB::table('audit_logs')->where('entity_type', Karyawan::class)->where('entity_id', $k->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('12.345.678', (string) $log->new_values);

        // NPWP kosong = tidak diubah; panel cepat "Ubah Karyawan" (tanpa data_payroll) tidak menghapus data payroll
        $this->actingAs($hr)->put("/karyawan/{$k->id}", $isi + ['data_payroll' => 1, 'position_id' => $jabatan->id, 'has_npwp' => 1, 'npwp' => '']);
        $this->actingAs($hr)->put("/karyawan/{$k->id}", array_merge($isi, ['nama' => 'Rizky M', 'kembali' => 'index']))->assertRedirect();
        $k->refresh();
        $this->assertSame(['Rizky M', $jabatan->id, true, '12.345.678.9-012.345'], [$k->nama, $k->position_id, $k->has_npwp, $k->npwp]);

        // jabatan payroll perusahaan lain ditolak
        $this->actingAs($hr)->from("/karyawan/{$k->id}/edit")->put("/karyawan/{$k->id}", $isi + ['data_payroll' => 1, 'position_id' => 999999])->assertSessionHasErrors('position_id');
    }

    public function test_profil_detail_dan_cek_lembur_memakai_kontrak_baru(): void
    {
        $this->beralih();
        $rizky = $this->user('rizky');
        $k = Karyawan::findOrFail($rizky->karyawan_id);

        $this->assertFalse($k->berhakLembur(), 'tanpa kontrak payroll belum berhak lembur');
        $this->kontrakBaru($k);
        $this->assertTrue($k->berhakLembur());

        $this->actingAs($rizky)->get('/profil')->assertOk()->assertSee('PKWT Uji 2026')->assertSee('7.250.000')->assertDontSee('Unduh Kontrak');
        $this->actingAs($this->user('hr'))->get("/karyawan/{$k->id}")->assertOk()->assertSee('PKWT Uji 2026')->assertSee('Kelola di Kontrak Payroll');
    }

    public function test_dashboard_semua_role_memakai_data_baru(): void
    {
        $this->beralih();
        $this->kontrakBaru(Karyawan::findOrFail($this->user('rizky')->karyawan_id));

        $this->actingAs($this->user('hr'))->get('/dashboard')->assertOk()->assertSee('Kontrak Payroll Aktif')->assertDontSee('Kontrak Menunggu Approval')
            ->assertSee(route('pay.run.index'));
        $this->actingAs($this->user('manager.it'))->get('/dashboard')->assertOk()->assertSee('Lembur Tim Periode Ini')->assertSee('diinput HR, dihitung saat payroll');
        $this->actingAs($this->user('rizky'))->get('/dashboard')->assertOk();
        $this->actingAs($this->user('admin'))->get('/dashboard')->assertOk();
    }
}
