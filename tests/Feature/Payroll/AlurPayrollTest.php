<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Actions\BuatKontrak;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\ReferenceTableVersion;
use App\Domain\Payroll\Models\RunEmployee;
use App\Domain\Payroll\Models\TaxMonthlyHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\MultiTenant\BuatPerusahaanLain;
use Tests\TestCase;

/** Fase 6: alur payroll (preview, edit sel, generate, approval, cegah ganda, kunci periode). */
final class AlurPayrollTest extends TestCase
{
    use BuatPerusahaanLain;
    use RefreshDatabase;
    use SiapkanPayroll;

    protected bool $seed = true;

    private User $hr;

    private User $managerHr;

    private Master $master;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 09:00', 'Asia/Jakarta'));
        $this->artisan('payroll:aktifkan', ['kode' => 'DEFAULT']);
        $this->hr = User::where('username', 'hr')->firstOrFail();
        $this->managerHr = User::where('username', 'manager.hr')->firstOrFail();
        $this->master = $this->siapkanPayroll();
    }

    private function id(string $nama): int
    {
        return (int) Employee::where('nama', $nama)->value('id');
    }

    private function runBaru(array $karyawan): PayrollRun
    {
        $this->actingAs($this->hr)->post('/pay/run', ['title' => 'Payroll November 2026', 'periode' => '2026-11'])->assertSessionHasNoErrors();
        $run = PayrollRun::latest('id')->firstOrFail();
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/pratinjau", ['master_id' => $this->master->id, 'scope' => 'individual', 'pilihan' => $karyawan])
            ->assertSessionHasNoErrors();

        return $run->fresh();
    }

    private function sel(PayrollRun $run, int $employeeId, string $code): object
    {
        return $run->employees()->where('employee_id', $employeeId)->firstOrFail()->items()->where('code', $code)->firstOrFail();
    }

    public function test_alur_lengkap_preview_penyesuaian_generate_setujui(): void
    {
        $rizky = $this->id('Rizky Maulana');
        $run = $this->runBaru([$rizky, $this->id('Nadia Putri')]);

        $this->assertSame(['2026-10-26', '2026-11-25', 'idle', 2], [$run->period_start->toDateString(), $run->period_end->toDateString(), $run->job_status, $run->employees()->count()]);
        $this->assertSame('5700000.00', $this->sel($run, $rizky, 'gaji_pokok')->final_amount);
        $this->assertSame('50000.00', $this->sel($run, $rizky, 'lembur')->final_amount);
        $this->assertSame('690000.00', $this->sel($run, $rizky, 'uang_makan')->final_amount, '660000/22*23 hadir (26 Okt-25 Nov)');
        $this->assertSame('64400.00', $this->sel($run, $rizky, 'pph21')->final_amount, '1% fiktif dari 6440000');

        // penyesuaian (pengganti ubah sel, AUDIT.md §52): bonus kena pajak menaikkan PPh21, reimburse tidak
        $bonus = Component::create(['code' => 'bonus', 'name' => 'Bonus', 'type' => 'earning', 'is_taxable' => true, 'default_amount' => '0']);
        $reimburse = Component::create(['code' => 'reimburse', 'name' => 'Reimburse', 'type' => 'earning', 'is_taxable' => false, 'default_amount' => '0']);
        $audit = DB::table('audit_logs')->count();
        $tambah = fn (array $d) => $this->actingAs($this->hr)->post("/pay/run/{$run->id}/penyesuaian", $d + ['employee_id' => $rizky, 'note' => 'uji']);
        $tambah(['component_id' => $bonus->id, 'amount' => '1.000.000'])->assertSessionHasNoErrors();
        $tambah(['component_id' => $reimburse->id, 'amount' => '300.000'])->assertSessionHasNoErrors();
        $tambah(['component_id' => $bonus->id, 'amount' => '-5'])->assertSessionHasErrors('amount');
        $tambah(['component_id' => $bonus->id, 'amount' => '0'])->assertSessionHasErrors('amount');
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/penyesuaian", ['employee_id' => $rizky, 'component_id' => $bonus->id, 'amount' => '1'])->assertSessionHasErrors('note');
        $this->assertSame('1000000.00', $this->sel($run, $rizky, 'adj_'.$bonus->id)->final_amount);
        $this->assertSame('Bonus (penyesuaian)', $this->sel($run, $rizky, 'adj_'.$bonus->id)->name);
        $this->assertSame('74400.00', $this->sel($run, $rizky, 'pph21')->final_amount, '1% dari 7440000: reimburse tidak kena pajak');
        $this->assertSame($audit + 2, DB::table('audit_logs')->count(), 'penyesuaian dicatat di audit log');
        $this->assertSame('7415600.00', (string) $run->employees()->where('employee_id', $rizky)->value('take_home_pay'), '7740000 penambah - 250000 bpjs - 74400 pph21');

        $this->actingAs($this->hr)->get("/pay/run/{$run->id}?baru=1")->assertOk()->assertSee('Bonus (penyesuaian)')->assertSee('Tambah penyesuaian')->assertSee('Reimburse');

        // hitung ulang (preview lagi) tetap membawa penyesuaian
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/pratinjau", ['master_id' => $this->master->id, 'scope' => 'individual', 'pilihan' => [$rizky, $this->id('Nadia Putri')]]);
        $this->assertSame('1000000.00', $this->sel($run, $rizky, 'adj_'.$bonus->id)->final_amount);

        // hapus penyesuaian reimburse
        $adj = \App\Domain\Payroll\Models\RunAdjustment::where('component_id', $reimburse->id)->firstOrFail();
        $this->actingAs($this->hr)->delete("/pay/run/{$run->id}/penyesuaian/{$adj->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, $run->employees()->where('employee_id', $rizky)->firstOrFail()->items()->where('code', 'adj_'.$reimburse->id)->count());

        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate")->assertSessionHasNoErrors();
        $run->refresh();
        $this->assertSame([PayrollRun::PENDING, null], [$run->status, $run->approver_user_id], 'belum diputuskan siapa pun');
        $this->assertTrue((bool) $run->employees()->where('employee_id', $rizky)->value('is_blocking'));
        $this->assertSame('1000000.00', $this->sel($run, $rizky, 'adj_'.$bonus->id)->final_amount, 'penyesuaian dibawa ke generate');
        $this->assertNotNull($this->master->fresh()->currentVersion->locked_at, 'versi master terkunci');
        $this->assertNotNull(ReferenceTableVersion::firstOrFail()->locked_at, 'versi tabel referensi terkunci');
        $this->assertSame(1, DB::table('pay_run_reference_versions')->where('run_id', $run->id)->count());

        // setelah generate: tidak bisa diedit / dihapus
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/penyesuaian", ['employee_id' => $rizky, 'component_id' => $bonus->id, 'amount' => '1', 'note' => 'telat'])->assertSessionHasErrors('run');
        $this->actingAs($this->hr)->delete("/pay/run/{$run->id}")->assertSessionHasErrors('run');

        // hanya Manager di departemen HR yang bisa menyetujui; Manager lain hanya melihat
        $managerIt = User::where('username', 'manager.it')->firstOrFail();
        $this->actingAs($managerIt)->get("/pay/approval/{$run->id}")->assertOk()->assertDontSee('>Setujui</button>', false);
        $this->actingAs($managerIt)->post("/pay/approval/{$run->id}/setujui")->assertForbidden();

        $this->actingAs($this->managerHr)->get('/pay/approval')->assertOk()->assertSee('Payroll November 2026');
        $this->actingAs($this->managerHr)->get("/pay/approval/{$run->id}")->assertOk()->assertSee('Bonus (penyesuaian)')->assertSee('Penyesuaian (1)');
        $this->actingAs($this->managerHr)->post("/pay/approval/{$run->id}/setujui")->assertRedirect();
        $this->assertSame([PayrollRun::APPROVED, $this->managerHr->id], [$run->fresh()->status, (int) $run->fresh()->approver_user_id], 'yang menyetujui tercatat');
        $this->assertSame(2, TaxMonthlyHistory::where('run_id', $run->id)->count());
        $this->assertSame([2026, 11], [TaxMonthlyHistory::first()->tax_year, TaxMonthlyHistory::first()->tax_month]);
        $this->assertSame('250000.00', TaxMonthlyHistory::where('employee_id', $rizky)->value('tax_deduction'), 'iuran pengurang pajak (bpjs_tk) tercatat');
        $this->assertSame(['generated', 'approved'], DB::table('audit_logs')->where('entity_type', PayrollRun::class)->orderBy('id')->pluck('action')->all());
    }

    public function test_cegah_payroll_ganda_lalu_ditolak_dan_dibuat_ulang(): void
    {
        $rizky = $this->id('Rizky Maulana');
        $pertama = $this->runBaru([$rizky]);
        $this->actingAs($this->hr)->post("/pay/run/{$pertama->id}/generate");

        $kedua = $this->runBaru([$rizky, $this->id('Nadia Putri')]);
        $this->assertSame([$this->id('Nadia Putri')], $kedua->employees()->pluck('employee_id')->all());
        $this->assertSame('sudah punya payroll aktif di periode ini', collect($kedua->skipped_employees)->firstWhere('employee_id', $rizky)['alasan']);

        // penjaga di database: unique key
        $this->expectsDatabaseUnique(fn () => RunEmployee::whereKey($kedua->employees()->value('id'))->update(['employee_id' => $rizky, 'is_blocking' => true]));

        $this->actingAs($this->managerHr)->post("/pay/approval/{$pertama->id}/tolak", ['alasan' => ''])->assertSessionHasErrors('alasan');
        $this->actingAs($this->managerHr)->post("/pay/approval/{$pertama->id}/tolak", ['alasan' => 'Lembur salah'])->assertRedirect();
        $this->assertSame(PayrollRun::REJECTED, $pertama->fresh()->status);
        $this->assertFalse((bool) $pertama->employees()->value('is_blocking'));

        $ketiga = $this->runBaru([$rizky]);
        $this->assertSame([$rizky], $ketiga->employees()->pluck('employee_id')->all(), 'karyawan bebas lagi setelah ditolak');
    }

    public function test_generate_dua_draft_periode_sama_tidak_error_dan_mencatat_alasan(): void
    {
        $rizky = $this->id('Rizky Maulana');
        $pertama = $this->runBaru([$rizky]);
        $kedua = $this->runBaru([$rizky]); // preview dua-duanya sebelum ada yang digenerate
        $this->assertSame(1, $kedua->employees()->count());

        $this->actingAs($this->hr)->post("/pay/run/{$pertama->id}/generate")->assertRedirect();
        $this->actingAs($this->hr)->post("/pay/run/{$kedua->id}/generate")->assertRedirect("/pay/run/{$kedua->id}");

        $kedua->refresh();
        $this->assertSame([PayrollRun::DRAFT, 'failed'], [$kedua->status, $kedua->job_status]);
        $this->assertStringContainsString('Tidak ada karyawan yang bisa digenerate', (string) $kedua->job_error);
        $this->assertSame('sudah punya payroll aktif di periode ini', collect($kedua->skipped_employees)->firstWhere('employee_id', $rizky)['alasan'] ?? null);
        $this->actingAs($this->hr)->get("/pay/run/{$kedua->id}")->assertOk()->assertSee('Rizky Maulana');
        $this->assertSame(PayrollRun::PENDING, $pertama->fresh()->status);
    }

    public function test_tanpa_approval_generate_langsung_final_dan_approver_tidak_wajib(): void
    {
        User::whereIn('username', ['manager.hr', 'manager.hr2'])->update(['is_active' => false]); // tanpa penyetuju pun boleh
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        DB::table('companies')->where('id', $this->hr->company_id)->update(['payroll_butuh_approval' => false]);

        $rizky = $this->id('Rizky Maulana');
        $run = $this->runBaru([$rizky]);
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate")->assertSessionHasNoErrors();

        $run->refresh();
        $this->assertSame([PayrollRun::APPROVED, null], [$run->status, $run->approver_user_id]);
        $this->assertNotNull($run->approved_at);
        $this->assertSame(1, TaxMonthlyHistory::where('run_id', $run->id)->count(), 'riwayat pajak tetap dicatat');
        $this->assertSame(1, DB::table('pay_payslips')->where('employee_id', $rizky)->count(), 'slip langsung terbit');
        $this->assertSame(['generated', 'approved'], DB::table('audit_logs')->where('entity_type', PayrollRun::class)->orderBy('id')->pluck('action')->all());
    }

    public function test_admin_mengatur_setelan_approval(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($admin)->put('/pay/approver/pengaturan', ['payroll_butuh_approval' => 0])->assertSessionHasNoErrors();
        $this->assertFalse((bool) DB::table('companies')->where('id', $this->hr->company_id)->value('payroll_butuh_approval'));
        $this->actingAs($this->hr)->put('/pay/approver/pengaturan', ['payroll_butuh_approval' => 1])->assertForbidden();
    }

    public function test_semua_manager_departemen_hr_bisa_menyetujui_yang_pertama_dicatat(): void
    {
        // dua Manager di departemen HRD sama-sama penyetuju; Manager IT tidak
        $managerHr2 = User::where('username', 'manager.hr2')->firstOrFail();
        $this->assertSame(['manager.hr', 'manager.hr2'], \App\Domain\Payroll\Run\PenyetujuPayroll::daftar()->pluck('username')->sort()->values()->all());

        $run = $this->runBaru([$this->id('Rizky Maulana')]);
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate")->assertSessionHasNoErrors();
        $this->actingAs($this->managerHr)->get('/dashboard')->assertSee(route('pay.approval.show', $run->id));
        $this->actingAs($managerHr2)->get('/dashboard')->assertSee(route('pay.approval.show', $run->id));

        $this->actingAs($managerHr2)->post("/pay/approval/{$run->id}/setujui")->assertSessionHasNoErrors();
        $this->assertSame([PayrollRun::APPROVED, $managerHr2->id], [$run->fresh()->status, (int) $run->fresh()->approver_user_id]);
        // keputusan kedua ditolak: sudah diputuskan
        $this->actingAs($this->managerHr)->post("/pay/approval/{$run->id}/tolak", ['alasan' => 'telat'])->assertSessionHasErrors();
        $this->assertSame(PayrollRun::APPROVED, $run->fresh()->status);
    }

    public function test_nilai_negatif_ditolak_dan_thp_minus_tidak_bisa_digenerate(): void
    {
        $rizky = $this->id('Rizky Maulana');
        $run = $this->runBaru([$rizky]);
        $re = $run->employees()->firstOrFail();
        $potongan = Component::create(['code' => 'potongan_lain', 'name' => 'Potongan lain', 'type' => 'deduction', 'default_amount' => '0']);
        $kirim = fn (string $n) => $this->actingAs($this->hr)->post("/pay/run/{$run->id}/penyesuaian", ['employee_id' => $rizky, 'component_id' => $potongan->id, 'amount' => $n, 'note' => 'uji']);
        $kirim('-5000')->assertSessionHasErrors('amount');

        $kirim('99000000')->assertSessionHasNoErrors();
        $this->assertTrue((float) $re->fresh()->take_home_pay < 0);
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate")->assertSessionHasErrors('run');
        $this->assertSame(PayrollRun::DRAFT, $run->fresh()->status);
    }

    public function test_setujui_dua_kali_tidak_error(): void
    {
        $run = $this->runBaru([$this->id('Rizky Maulana')]);
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate");
        $this->actingAs($this->managerHr)->post("/pay/approval/{$run->id}/setujui")->assertSessionHasNoErrors();
        $this->actingAs($this->managerHr)->post("/pay/approval/{$run->id}/setujui")->assertSessionHasErrors('run');
        $this->assertSame(1, TaxMonthlyHistory::where('run_id', $run->id)->count());
    }

    public function test_karyawan_resign_sebelum_periode_dilewati(): void
    {
        $rizky = $this->id('Rizky Maulana');
        Employee::whereKey($rizky)->update(['resign_date' => '2026-10-01']);
        $run = $this->runBaru([$rizky, $this->id('Nadia Putri')]);
        $this->assertSame([$this->id('Nadia Putri')], $run->employees()->pluck('employee_id')->map(fn ($v) => (int) $v)->all());
        $this->assertStringContainsString('resign', collect($run->skipped_employees)->firstWhere('employee_id', $rizky)['alasan']);
    }

    public function test_batalkan_proses_yang_macet(): void
    {
        $run = $this->runBaru([$this->id('Rizky Maulana')]);
        $mulai = now()->subMinutes(5);
        $run->forceFill(['job_type' => 'generate', 'job_status' => 'running', 'job_started_at' => $mulai])->save();
        RunEmployee::where('run_id', $run->id)->update(['is_blocking' => true]);

        // belum 15 menit: belum boleh dibatalkan
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/batalkan")->assertSessionHasErrors('run');

        Carbon::setTestNow(now()->addMinutes(20));
        $this->actingAs($this->hr)->get("/pay/run/{$run->id}")->assertOk()->assertSee('Batalkan proses');
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/batalkan")->assertSessionHasNoErrors();

        $run->refresh();
        $this->assertSame([PayrollRun::DRAFT, 'failed'], [$run->status, $run->job_status]);
        $this->assertFalse((bool) $run->employees()->value('is_blocking'), 'karyawan tidak terkunci oleh proses yang dibatalkan');
        $this->assertFalse(PayrollRun::prosesMasihBerlaku((int) $run->id, $mulai), 'job lama yang telat selesai tidak boleh menimpa');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'job_cancelled')->count());

        // bisa diproses ulang
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate")->assertSessionHasNoErrors();
        $this->assertSame(PayrollRun::PENDING, $run->fresh()->status);
    }

    public function test_kunci_periode_absensi_setelah_generate(): void
    {
        $rizky = $this->id('Rizky Maulana');
        $run = $this->runBaru([$rizky]);
        $data = ['nik' => Employee::findOrFail($rizky)->nik, 'tanggal' => '2026-11-08', 'jam_masuk' => '08:00'];

        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate");
        $this->actingAs($this->hr)->from('/pay/absensi')->post('/pay/absensi', $data)->assertSessionHasErrors('data');

        $this->actingAs($this->managerHr)->post("/pay/approval/{$run->id}/tolak", ['alasan' => 'ulang']);
        $this->actingAs($this->hr)->post('/pay/absensi', $data)->assertSessionHasNoErrors();
    }

    public function test_hapus_draft_tanpa_approver_dan_error_rumus(): void
    {
        $run = $this->runBaru([$this->id('Rizky Maulana')]);
        $this->actingAs($this->hr)->delete("/pay/run/{$run->id}")->assertRedirect();
        $this->assertSame(0, PayrollRun::count());
        $this->assertSame(0, RunEmployee::count());

        User::whereIn('username', ['manager.hr', 'manager.hr2'])->update(['is_active' => false]); // tidak ada penyetuju aktif
        $run = $this->runBaru([$this->id('Rizky Maulana')]);
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate")->assertSessionHasErrors('run');
        User::whereIn('username', ['manager.hr', 'manager.hr2'])->update(['is_active' => true]);
        $this->assertSame(PayrollRun::DRAFT, $run->fresh()->status);

        // karyawan tanpa kontrak dilewati dengan alasan
        $joko = $this->id('Joko Susilo');
        $run2 = $this->runBaru([$joko]);
        $this->assertSame('tidak punya kontrak yang berlaku di periode ini', collect($run2->skipped_employees)->firstWhere('employee_id', $joko)['alasan'] ?? null);
    }

    public function test_cakupan_karyawan_tidak_bisa_menarik_karyawan_perusahaan_lain(): void
    {
        $b = $this->perusahaanLain();
        $rizky = $this->id('Rizky Maulana');

        // perorangan: id karyawan perusahaan B diselipkan ke pilihan -> diabaikan
        $run = $this->runBaru([$rizky, (int) $b['karyawan']->id]);
        $this->assertSame([$rizky], $run->employees()->pluck('employee_id')->map(fn ($v) => (int) $v)->all());

        // per departemen: departemen milik B -> tidak ada karyawan yang ikut
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/pratinjau", ['master_id' => $this->master->id, 'scope' => 'department', 'pilihan' => [(int) $b['departemen']->id]]);
        $this->assertSame(0, $run->fresh()->employees()->count());
        $this->assertFalse(RunEmployee::withoutGlobalScopes()->where('employee_id', $b['karyawan']->id)->exists());

        // semua: hanya karyawan perusahaan sendiri
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/pratinjau", ['master_id' => $this->master->id, 'scope' => 'all', 'pilihan' => []]);
        $ids = $run->fresh()->employees()->pluck('employee_id')->all();
        $this->assertNotEmpty($ids);
        $this->assertNotContains((int) $b['karyawan']->id, array_map('intval', $ids));
    }

    public function test_run_perusahaan_lain_tidak_bisa_dibuka(): void
    {
        $b = $this->perusahaanLain();
        $runB = app(CurrentCompany::class)->runAs((int) $b['company']->id, fn () => PayrollRun::create([
            'title' => 'Rahasia B', 'period_start' => '2026-10-26', 'period_end' => '2026-11-25', 'created_by' => $b['users']['HR']->id,
        ]));
        $this->actingAs($this->hr)->get("/pay/run/{$runB->id}")->assertNotFound();
        $this->actingAs($this->hr)->get('/pay/run')->assertDontSee('Rahasia B');
        $this->actingAs($this->hr)->delete("/pay/run/{$runB->id}")->assertNotFound();
    }

    private function expectsDatabaseUnique(\Closure $f): void
    {
        try {
            $f();
            $this->fail('unique key uq_pre_one_active_per_period seharusnya menolak');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_tarik_kembali_payroll_yang_menunggu_approval(): void
    {
        $rizky = $this->id('Rizky Maulana');
        $run = $this->runBaru([$rizky]);
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/tarik")->assertSessionHasErrors('run'); // masih draft
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate")->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->get("/pay/run/{$run->id}")->assertOk()->assertSee('Tarik kembali');

        $this->actingAs($this->managerHr)->post("/pay/run/{$run->id}/tarik")->assertForbidden(); // bukan hak Generate Payroll
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/tarik", ['alasan' => 'lembur salah'])->assertSessionHasNoErrors();
        $run->refresh();
        $this->assertSame([PayrollRun::DRAFT, null], [$run->status, $run->generated_at]);
        $this->assertFalse((bool) $run->employees()->value('is_blocking'));
        $this->assertSame(['alasan' => 'lembur salah'], array_intersect_key((array) json_decode((string) DB::table('audit_logs')->where('action', 'withdrawn')->value('new_values'), true), ['alasan' => 1]));
        $this->actingAs($this->managerHr)->post("/pay/approval/{$run->id}/setujui")->assertSessionHasErrors('run');

        // perbaiki lalu generate ulang dan setujui
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/pratinjau", ['master_id' => $this->master->id, 'scope' => 'individual', 'pilihan' => [$rizky]])->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate")->assertSessionHasNoErrors();
        $this->actingAs($this->managerHr)->post("/pay/approval/{$run->id}/setujui")->assertSessionHasNoErrors();
        $this->assertSame(PayrollRun::APPROVED, $run->fresh()->status);
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/tarik")->assertSessionHasErrors('run');
        $this->actingAs($this->hr)->get("/pay/run/{$run->id}")->assertOk()->assertSee('Tambah penyesuaian</b> di payroll periode berikutnya', false);
    }

    public function test_detail_payroll_checklist_kesiapan_ringkasan_dan_filter(): void
    {
        $run = $this->runBaru([$this->id('Rizky Maulana'), $this->id('Nadia Putri')]);
        $hal = $this->actingAs($this->hr)->get("/pay/run/{$run->id}")->assertOk()
            ->assertSee('Persiapan sebelum generate')->assertSee('Master Payroll Terbit')->assertSee('Siap di-generate')
            ->assertSee('<div class="ket">2 karyawan</div>', false)->assertSee('Departemen / manager')->assertDontSee('Pilihan (departemen / karyawan)');
        $this->assertStringContainsString('name="scope" value="all"', $hal->getContent());

        $dept = (string) $run->employees()->value('department_name');
        $this->actingAs($this->hr)->get("/pay/run/{$run->id}?karyawan_id[]=".$this->id('Nadia Putri'))->assertOk()->assertSee('1</b> dari 2 karyawan', false);
        $this->actingAs($this->hr)->get("/pay/run/{$run->id}?departemen[]=".urlencode($dept))->assertOk()->assertSee('2</b> dari 2 karyawan', false);

        // penyetuju tidak ada: item wajib belum terpenuhi, tombol generate nonaktif
        User::whereIn('username', ['manager.hr', 'manager.hr2'])->update(['is_active' => false]);
        $this->actingAs($this->hr)->get("/pay/run/{$run->id}")->assertOk()->assertSee('Belum ada penyetuju; atur di menu Approver Payroll')->assertSee('Belum siap')
            ->assertSee('Lengkapi persiapan yang wajib dulu');
    }
}
