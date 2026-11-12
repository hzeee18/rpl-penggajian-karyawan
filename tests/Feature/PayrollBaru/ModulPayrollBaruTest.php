<?php

declare(strict_types=1);

namespace Tests\Feature\PayrollBaru;

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
final class ModulPayrollBaruTest extends TestCase
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

        // menu Cuti & Lembur lama ditutup untuk role Karyawan; isinya ada di Absensi
        $this->actingAs($rizky)->get('/cuti')->assertForbidden();
        $this->actingAs($rizky)->get('/lembur')->assertForbidden();
    }

    public function test_menu_dan_dashboard_hr_sesuai_payroll_baru(): void
    {
        $this->beralih();
        $hr = $this->user('hr');
        $html = $this->actingAs($hr)->get('/dashboard')->assertOk()
            ->assertSee('Kontrak Payroll Aktif')->assertSee('Input Leave & Lembur Terbaru', false)->assertSee('tanpa kontrak payroll')
            ->assertSee(route('pay.import.index'))->assertDontSee(route('import.index', ['jenis' => 'absensi']))
            ->getContent();

        preg_match_all('#<a href="https?://[^/"]+/([^"]*)" class="nav-item-link#', $html, $m);
        $this->assertEqualsCanonicalizing(
            ['dashboard', 'karyawan', 'users', 'pay/komponen', 'pay/libur', 'pay/kontrak', 'pay/absensi', 'pay/leave', 'pay/lembur', 'pay/import', 'pay/master', 'pay/run', 'pay/slip'],
            array_values(array_unique($m[1])),
        );
        // halaman lama dialihkan ke pengganti
        $this->actingAs($hr)->get('/import')->assertRedirect(route('pay.import.index'));
        $this->actingAs($hr)->get('/master-payroll')->assertRedirect(route('pay.cutoff.index'));
        $this->actingAs($hr)->get('/pajak')->assertRedirect(route('pay.referensi.index'));
        $this->actingAs($hr)->get('/absensi')->assertForbidden();
    }

    public function test_approver_payroll_diisi_dari_form_user_dan_terlihat_di_generate_payroll(): void
    {
        $this->beralih();
        $admin = $this->user('admin');
        $manager = $this->user('manager.it');
        $roleHr = DB::table('roles')->where('company_id', $admin->company_id)->where('nama', 'HR')->value('id');

        $this->actingAs($admin)->get('/users/create')->assertOk()->assertSee('Approver payroll')->assertSee('('.$manager->username.')', false);
        // approver harus Manager aktif
        $this->actingAs($admin)->from('/users/create')->post('/users', ['username' => 'hr_baru', 'role_id' => $roleHr, 'password' => 'rahasia123', 'is_active' => 1,
            'form_approver' => 1, 'approver_user_id' => $this->user('hr')->id])->assertSessionHasErrors('approver_user_id');
        $this->actingAs($admin)->post('/users', ['username' => 'hr_baru', 'role_id' => $roleHr, 'password' => 'rahasia123', 'is_active' => 1,
            'form_approver' => 1, 'approver_user_id' => $manager->id])->assertSessionHasNoErrors();
        $hrBaru = User::where('username', 'hr_baru')->firstOrFail();
        $this->assertSame($manager->id, (int) $hrBaru->approver_user_id);

        $this->actingAs($hrBaru)->get('/pay/run')->assertOk()->assertSee('akan disetujui oleh')->assertSee($manager->username);
        $this->actingAs($admin)->put("/users/{$hrBaru->id}", ['username' => 'hr_baru', 'role_id' => $roleHr, 'is_active' => 1, 'form_approver' => 1, 'approver_user_id' => ''])
            ->assertSessionHasNoErrors();
        $this->actingAs($hrBaru->fresh())->get('/pay/run')->assertOk()->assertSee('belum punya <b>approver payroll</b>', false);
    }

    public function test_tanpa_beralih_fitur_lama_tidak_berubah(): void
    {
        $hr = $this->user('hr');
        $this->actingAs($hr)->get('/kontrak')->assertOk();
        $this->actingAs($hr)->get('/karyawan/create')->assertOk()->assertDontSee('Data payroll');
        $this->assertFalse(Company::where('code', Company::DEFAULT_CODE)->firstOrFail()->payroll_baru);
    }

    public function test_menu_lama_dialihkan_ke_modul_baru(): void
    {
        $this->beralih();
        $hr = $this->user('hr');

        $this->actingAs($hr)->get('/kontrak')->assertRedirect(route('pay.kontrak.index'));
        $this->actingAs($hr)->get('/kontrak/create')->assertRedirect(route('pay.kontrak.index'));
        $this->actingAs($hr)->get('/payroll')->assertRedirect(route('pay.run.index'));
        $this->actingAs($hr)->get('/laporan')->assertRedirect(route('pay.run.index'));
        $this->actingAs($hr)->get('/komponen')->assertRedirect(route('pay.komponen.index'));
        $this->actingAs($hr)->get('/pay/karyawan')->assertRedirect(route('karyawan.index'));
        $this->actingAs($this->user('rizky'))->get('/slip')->assertRedirect(route('pay.slip.index'));

        $sidebar = $this->actingAs($hr)->get('/dashboard')->assertOk()->getContent();
        $tautan = fn (string $path) => (bool) preg_match('#<a href="https?://[^/"]+/'.preg_quote($path, '#').'" class="nav-item-link#', $sidebar);
        $this->assertTrue($tautan('pay/kontrak'));
        $this->assertTrue($tautan('pay/run'));
        $this->assertFalse($tautan('kontrak'), 'menu Kontrak lama disembunyikan');
        $this->assertFalse($tautan('payroll'));
        $this->assertFalse($tautan('pay/karyawan'), 'Karyawan Payroll digabung ke Data Karyawan');
        $this->assertTrue($tautan('karyawan'));
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
        // Import Excel lama dialihkan ke Import CSV payroll (AUDIT.md §34)
        $this->actingAs($this->user('hr'))->post('/import/upload', ['jenis' => 'kontrak', 'file' => UploadedFile::fake()->create('kontrak.xlsx', 4)])
            ->assertRedirect(route('pay.import.index'));
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
