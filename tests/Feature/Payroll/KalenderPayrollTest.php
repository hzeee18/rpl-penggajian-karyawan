<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** AUDIT.md §53: Kalender Payroll = tab Periode (pay_periods menyambung) + tab Hari Libur (dengan jenis). */
final class KalenderPayrollTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanPayroll;

    protected bool $seed = true;

    private User $hr;

    private Master $master;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 09:00', 'Asia/Jakarta'));
        $this->artisan('payroll:aktifkan', ['kode' => 'DEFAULT']);
        $this->hr = User::where('username', 'hr')->firstOrFail();
        $this->master = $this->siapkanPayroll(); // cut off 26 bulan lalu s/d 25
    }

    private function rentang(string $bulan): array
    {
        $p = PayPeriod::where('period_month', $bulan)->firstOrFail();

        return [$p->start_date->toDateString(), $p->end_date->toDateString()];
    }

    public function test_periode_dibuat_dari_cut_off_dan_selalu_menyambung(): void
    {
        // November sudah dibuat di siapkanPayroll(); tombol membuat bulan sesudah periode terakhir, tanggal otomatis lewat konfirmasi
        $this->actingAs($this->hr)->get('/pay/libur')->assertOk()->assertSee('Buat periode Desember 2026 (26 November 2026 – 25 Desember 2026)?')
            ->assertDontSee('Aturan bawaan dari Cut Off');
        $this->actingAs($this->hr)->post('/pay/libur/periode')->assertSessionHasNoErrors()->assertRedirect(route('pay.libur.index'));
        $this->assertSame(['2026-10-26', '2026-11-25'], $this->rentang('2026-11'));
        $this->assertSame(['2026-11-26', '2026-12-25'], $this->rentang('2026-12'));

        // November selesai lebih awal: Desember ikut mulai lebih awal
        $nov = PayPeriod::where('period_month', '2026-11')->firstOrFail();
        $this->actingAs($this->hr)->put("/pay/libur/periode/{$nov->id}", ['end_date' => '2026-11-24'])->assertSessionHasNoErrors();
        $this->assertSame(['2026-10-26', '2026-11-24'], $this->rentang('2026-11'));
        $this->assertSame(['2026-11-25', '2026-12-25'], $this->rentang('2026-12'));

        $this->actingAs($this->hr)->put("/pay/libur/periode/{$nov->id}", ['end_date' => '2026-10-01'])->assertSessionHasErrors('end_date');
        $this->actingAs($this->hr)->put("/pay/libur/periode/{$nov->id}", ['end_date' => '2026-12-30'])->assertSessionHasErrors('end_date');

        $this->actingAs($this->hr)->get('/pay/libur')->assertOk()->assertSee('November 2026')->assertSee('Desember 2026')->assertSee('Buat periode berikutnya');
        $this->assertSame(2, DB::table('audit_logs')->where('entity_type', PayPeriod::class)->where('action', 'created')->count());
        $this->assertSame(2, PayPeriod::count());
    }

    public function test_payroll_memakai_periode_dan_periode_terkunci_setelah_dipakai(): void
    {
        // periode harus dibuat dulu di Kalender Payroll
        $this->actingAs($this->hr)->post('/pay/run', ['title' => 'Payroll Januari 2027', 'periode' => '2027-01'])->assertSessionHasErrors('periode');
        $this->actingAs($this->hr)->post('/pay/run', ['title' => 'Payroll November 2026', 'periode' => '2026-11'])->assertSessionHasNoErrors();
        $run = PayrollRun::latest('id')->firstOrFail();
        $this->assertSame(['2026-10-26', '2026-11-25'], [$run->period_start->toDateString(), $run->period_end->toDateString()]);

        $nov = PayPeriod::where('period_month', '2026-11')->firstOrFail();
        $this->actingAs($this->hr)->put("/pay/libur/periode/{$nov->id}", ['end_date' => '2026-11-20'])->assertSessionHasErrors('end_date');

        // periode yang diubah dipakai payroll berikutnya
        $this->actingAs($this->hr)->delete("/pay/run/{$run->id}");
        $this->actingAs($this->hr)->put("/pay/libur/periode/{$nov->id}", ['end_date' => '2026-11-20'])->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->post('/pay/libur/periode')->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->post('/pay/run', ['title' => 'Payroll Desember 2026', 'periode' => '2026-12'])->assertSessionHasNoErrors();
        $des = PayrollRun::latest('id')->firstOrFail();
        $this->assertSame('2026-11-21', $des->period_start->toDateString(), 'Desember mulai sehari setelah November selesai');
    }

    public function test_tab_hari_libur_dengan_jenis_dan_kalender(): void
    {
        $this->actingAs($this->hr)->post('/pay/libur/periode');
        $this->actingAs($this->hr)->post('/pay/libur', ['holiday_date' => '2026-11-10', 'type' => 'perusahaan', 'name' => 'Ulang tahun perusahaan'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->post('/pay/libur', ['holiday_date' => '2026-11-11', 'type' => 'bebas', 'name' => 'x'])->assertSessionHasErrors('type');
        $this->assertSame('perusahaan', DB::table('pay_holidays')->value('type'));

        $nov = PayPeriod::where('period_month', '2026-11')->value('id');
        $this->actingAs($this->hr)->get('/pay/libur?tab=libur&bulan=2026-11')->assertOk()->assertSee('November 2026')->assertSee(e(route('pay.libur.index', ['tab' => 'libur', 'bulan' => '2026-12'])), false)->assertSee('Ulang tahun perusahaan')->assertSee('Libur perusahaan')->assertSee('Hari istirahat');
        $this->actingAs($this->hr)->get('/pay/libur')->assertOk()->assertSee('Hari kerja');
        $this->actingAs(User::where('username', 'rizky')->firstOrFail())->get('/pay/libur')->assertForbidden();
    }

    public function test_hari_libur_bisa_diubah_dan_dihapus_dari_kalender(): void
    {
        $this->actingAs($this->hr)->post('/pay/libur/periode');
        $this->actingAs($this->hr)->post('/pay/libur', ['holiday_date' => '2026-11-10', 'type' => 'nasional', 'name' => 'Libur A']);
        $id = (int) DB::table('pay_holidays')->value('id');

        // sel kalender mengarah ke form ubah; tanggal kosong mengarah ke form tambah dengan tanggal terisi
        $nov = PayPeriod::where('period_month', '2026-11')->value('id');
        $this->actingAs($this->hr)->get('/pay/libur?tab=libur&bulan=2026-11')->assertOk()
            ->assertSee(e(route('pay.libur.index', ['tab' => 'libur', 'bulan' => '2026-11', 'ubah' => $id])), false)
            ->assertSee(e(route('pay.libur.index', ['tab' => 'libur', 'bulan' => '2026-11', 'baru' => 1, 'holiday_date' => '2026-11-11'])), false);
        $this->actingAs($this->hr)->get("/pay/libur?tab=libur&ubah={$id}")->assertOk()->assertSee('Ubah Hari libur')->assertSee('Libur A');
        $this->actingAs($this->hr)->get('/pay/libur?tab=libur&baru=1&holiday_date=2026-11-11')->assertOk()->assertSee('value="2026-11-11"', false);

        $this->actingAs($this->hr)->put("/pay/libur/{$id}", ['holiday_date' => '2026-11-12', 'type' => 'cuti_bersama', 'name' => 'Libur B'])->assertSessionHasNoErrors()
            ->assertRedirect(route('pay.libur.index', ['tab' => 'libur', 'bulan' => '2026-11', 'ubah' => $id]));
        $this->assertSame(['2026-11-12', 'cuti_bersama', 'Libur B'], array_values((array) DB::table('pay_holidays')->select('holiday_date', 'type', 'name')->first()));
        $this->actingAs($this->hr)->delete("/pay/libur/{$id}")->assertRedirect(route('pay.libur.index', ['tab' => 'libur']));
        $this->assertSame(0, DB::table('pay_holidays')->count());
    }

    public function test_generate_payroll_menampilkan_periode_berikutnya(): void
    {
        $this->actingAs($this->hr)->get('/pay/run')->assertOk()->assertSee('Periode berikutnya')->assertSee('Generate payroll November 2026');
        $this->actingAs($this->hr)->get('/pay/run?baru=1&periode=2026-11')->assertOk()->assertSee('value="Payroll November 2026"', false)->assertSee('· Belum diproses"', false);

        $this->actingAs($this->hr)->post('/pay/run', ['title' => 'Payroll November 2026', 'periode' => '2026-11'])->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->get('/pay/run')->assertOk()->assertSee('Semua periode sudah punya payroll')->assertSee('Buka Kalender Payroll');
        $this->actingAs($this->hr)->post('/pay/libur/periode');
        $this->actingAs($this->hr)->get('/pay/run')->assertOk()->assertSee('Generate payroll Desember 2026');
    }

    public function test_periode_yang_belum_dipakai_bisa_dihapus(): void
    {
        $this->actingAs($this->hr)->post('/pay/libur/periode'); // Desember
        $nov = PayPeriod::where('period_month', '2026-11')->firstOrFail();
        $des = PayPeriod::where('period_month', '2026-12')->firstOrFail();

        $this->actingAs($this->hr)->get('/pay/libur')->assertOk()->assertSee(route('pay.libur.periode.destroy', $des->id))->assertDontSee(route('pay.libur.periode.destroy', $nov->id))
            ->assertSee('Hanya periode terakhir yang bisa dihapus')->assertSee(route('pay.libur.index', ['periode_ubah' => $nov->id]), false);
        $this->actingAs($this->hr)->delete("/pay/libur/periode/{$nov->id}")->assertSessionHasErrors('periode'); // bukan yang paling akhir
        $this->actingAs($this->hr)->delete("/pay/libur/periode/{$des->id}")->assertSessionHasNoErrors();
        $this->assertNull(PayPeriod::find($des->id));

        // yang sudah dipakai payroll tidak bisa dihapus
        $this->actingAs($this->hr)->post('/pay/run', ['title' => 'Payroll November 2026', 'periode' => '2026-11']);
        $this->actingAs($this->hr)->delete("/pay/libur/periode/{$nov->id}")->assertSessionHasErrors('periode');
        $this->assertNotNull(PayPeriod::find($nov->id));
        $this->actingAs(User::where('username', 'rizky')->firstOrFail())->delete("/pay/libur/periode/{$nov->id}")->assertForbidden();
    }

    public function test_filter_absensi_per_periode_kalender_dan_rentang_bebas(): void
    {
        $nov = PayPeriod::where('period_month', '2026-11')->firstOrFail();
        $this->actingAs($this->hr)->put("/pay/libur/periode/{$nov->id}", ['end_date' => '2026-11-20'])->assertSessionHasNoErrors();

        // periode dari Kalender Payroll: 26 Okt - 20 Nov (bukan aturan cut off 25 Nov)
        $hal = $this->actingAs($this->hr)->get('/pay/absensi?periode=2026-11')->assertOk()->assertSee('26 Okt 2026 – 20 Nov 2026')->assertSee('Pilih tanggal sendiri')->assertDontSee('Belum diproses');
        $this->assertStringNotContainsString('Sel, 24 Nov 2026', $hal->getContent());

        // rentang bebas
        $this->actingAs($this->hr)->get('/pay/absensi?periode=bebas&dari=2026-11-23&sampai=2026-11-24')->assertOk()
            ->assertSee('23 Nov 2026 – 24 Nov 2026')->assertSee('Sel, 24 Nov 2026')->assertDontSee('Rab, 25 Nov 2026');
        // bulan tanpa periode: pakai cut off
        $this->actingAs($this->hr)->get('/pay/absensi?periode=2027-03')->assertOk()->assertSee('Maret 2027')->assertSee('26 Feb 2027 – 25 Mar 2027');
    }

    public function test_hari_libur_di_periode_yang_sudah_dikirim_atau_final_terkunci(): void
    {
        $this->actingAs($this->hr)->post('/pay/libur', ['holiday_date' => '2026-11-10', 'type' => 'nasional', 'name' => 'Libur A'])->assertSessionHasNoErrors();
        $id = (int) DB::table('pay_holidays')->value('id');
        $this->actingAs($this->hr)->post('/pay/run', ['title' => 'Payroll November 2026', 'periode' => '2026-11']);
        $run = PayrollRun::latest('id')->firstOrFail();
        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/pratinjau", ['master_id' => $this->master->id, 'scope' => 'all']);

        // masih draft: boleh diubah (dihitung ulang saat preview)
        $this->actingAs($this->hr)->put("/pay/libur/{$id}", ['holiday_date' => '2026-11-10', 'type' => 'perusahaan', 'name' => 'Libur B'])->assertSessionHasNoErrors();

        $this->actingAs($this->hr)->post("/pay/run/{$run->id}/generate")->assertSessionHasNoErrors(); // menunggu persetujuan
        $this->actingAs($this->hr)->put("/pay/libur/{$id}", ['holiday_date' => '2026-11-10', 'type' => 'nasional', 'name' => 'Ubah'])->assertSessionHasErrors('holiday_date');
        $this->actingAs($this->hr)->delete("/pay/libur/{$id}")->assertSessionHasErrors('holiday_date');
        $this->actingAs($this->hr)->post('/pay/libur', ['holiday_date' => '2026-11-11', 'type' => 'nasional', 'name' => 'Baru'])->assertSessionHasErrors('holiday_date');
        $this->actingAs($this->hr)->put("/pay/libur/{$id}", ['holiday_date' => '2026-12-01', 'type' => 'nasional', 'name' => 'Pindah keluar'])->assertSessionHasErrors('holiday_date');
        $this->actingAs($this->hr)->post('/pay/libur', ['holiday_date' => '2026-12-01', 'type' => 'nasional', 'name' => 'Di luar periode'])->assertSessionHasNoErrors();
        $this->assertSame('Libur B', DB::table('pay_holidays')->where('id', $id)->value('name'));

        $nov = PayPeriod::where('period_month', '2026-11')->value('id');
        $this->actingAs($this->hr)->get('/pay/libur?tab=libur&bulan=2026-11')->assertOk()->assertSee('Terkunci: tanggal ini masuk payroll yang menunggu persetujuan')
            ->assertDontSee(e(route('pay.libur.index', ['tab' => 'libur', 'bulan' => '2026-11', 'baru' => 1, 'holiday_date' => '2026-11-12'])), false)
            ->assertSee(e(route('pay.libur.index', ['tab' => 'libur', 'bulan' => '2026-11', 'baru' => 1, 'holiday_date' => '2026-11-27'])), false)
            ->assertDontSee(route('pay.libur.destroy', $id));
    }

    /** AUDIT.md §74: daftar hari libur di bawah kalender hanya bulan yang ditampilkan. */
    public function test_daftar_hari_libur_mengikuti_bulan_kalender(): void
    {
        \App\Domain\Payroll\Models\Holiday::create(['holiday_date' => '2026-11-03', 'type' => 'perusahaan', 'name' => 'Libur November Uji']);
        \App\Domain\Payroll\Models\Holiday::create(['holiday_date' => '2026-12-04', 'type' => 'perusahaan', 'name' => 'Libur Desember Uji']);
        $this->actingAs($this->hr)->get('/pay/libur?tab=libur&bulan=2026-11')->assertOk()->assertSee('Libur November Uji')->assertDontSee('Libur Desember Uji');
        $this->actingAs($this->hr)->get('/pay/libur?tab=libur&bulan=2026-12')->assertOk()->assertSee('Libur Desember Uji')->assertDontSee('Libur November Uji');
    }
}
