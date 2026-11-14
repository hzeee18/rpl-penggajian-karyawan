<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Engine\SumberData;
use App\Domain\Payroll\Import\JenisKehadiran;
use App\Domain\Payroll\Import\ValidatorKehadiran;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Holiday;
use App\Domain\Payroll\Models\LeaveType;
use App\Domain\Payroll\Models\WorkPattern;
use App\Models\Departemen;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AUDIT.md §55: hari kerja mengikuti pola (karyawan -> departemen -> bawaan -> Senin-Jumat), libur bisa khusus departemen.
 * Periode uji 26 Okt (Senin) - 25 Nov 2026 = 31 hari: Senin-Jumat 23, Senin-Sabtu 27, Senin-Minggu 31.
 */
final class PolaHariKerjaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 09:00', 'Asia/Jakarta'));
        $this->artisan('payroll:aktifkan', ['kode' => 'DEFAULT']);
        $this->hr = User::where('username', 'hr')->firstOrFail();
        $this->actingAs($this->hr);
    }

    private function hariKerja(string $nama): int
    {
        $e = Employee::with('departemen')->where('nama', $nama)->firstOrFail();
        $s = new SumberData($e, null, CarbonImmutable::parse('2026-10-26'), CarbonImmutable::parse('2026-11-25'));
        $k = new \App\Domain\Payroll\Engine\KonteksKaryawan([], $s->libur(), '2026-11-25', $s->hariKerja());

        return $k->hariKerja('2026-10-26', '2026-11-25');
    }

    private function pola(string $code, string $hari, bool $bawaan = false): WorkPattern
    {
        return WorkPattern::create(['code' => $code, 'name' => $code, 'work_days' => $hari, 'is_default' => $bawaan]);
    }

    public function test_urutan_pola_karyawan_departemen_bawaan(): void
    {
        $this->assertSame(23, $this->hariKerja('Rizky Maulana'), 'tanpa pola: Senin-Jumat');

        $this->pola('enam_hari', '1,2,3,4,5,6', true);
        $this->assertSame(27, $this->hariKerja('Rizky Maulana'), 'pola bawaan perusahaan');

        $ops = $this->pola('operasional', '1,2,3,4,5,6,7');
        Departemen::where('kode', 'IT')->firstOrFail()->forceFill(['pay_work_pattern_id' => $ops->id])->save();
        $this->assertSame(31, $this->hariKerja('Nadia Putri'), 'pola departemen');

        $kantor = $this->pola('kantor', '1,2,3,4,5');
        Employee::where('nama', 'Rizky Maulana')->update(['pay_work_pattern_id' => $kantor->id]);
        $this->assertSame(23, $this->hariKerja('Rizky Maulana'), 'pengecualian karyawan menang');
        $this->assertSame(31, $this->hariKerja('Nadia Putri'));
    }

    public function test_libur_semua_departemen_dan_libur_khusus_departemen(): void
    {
        $it = Departemen::where('kode', 'IT')->firstOrFail();
        $hrd = Departemen::where('kode', 'HRD')->firstOrFail();
        Holiday::create(['holiday_date' => '2026-11-10', 'name' => 'Libur nasional']);
        Holiday::create(['holiday_date' => '2026-11-11', 'departemen_id' => $hrd->id, 'name' => 'Libur HRD']);
        $this->assertSame(22, $this->hariKerja('Rizky Maulana'), 'libur HRD tidak berlaku untuk IT');

        Holiday::create(['holiday_date' => '2026-11-12', 'departemen_id' => $it->id, 'name' => 'Libur IT']);
        $this->assertSame(21, $this->hariKerja('Rizky Maulana'));

        // validasi form: libur khusus departemen di tanggal yang sudah libur semua departemen ditolak
        $this->post('/pay/libur', ['holiday_date' => '2026-11-10', 'type' => 'perusahaan', 'departemen_id' => $it->id, 'name' => 'dobel'])->assertSessionHasErrors('holiday_date');
        $this->post('/pay/libur', ['holiday_date' => '2026-11-11', 'type' => 'perusahaan', 'departemen_id' => $it->id, 'name' => 'Libur IT juga'])->assertSessionHasNoErrors();
        $this->post('/pay/libur', ['holiday_date' => '2026-11-11', 'type' => 'perusahaan', 'departemen_id' => $it->id, 'name' => 'dobel'])->assertSessionHasErrors('holiday_date');
        $this->assertSame($it->id, (int) Holiday::where('name', 'Libur IT juga')->value('departemen_id'));
    }

    public function test_leave_mengikuti_pola_karyawan(): void
    {
        LeaveType::create(['code' => 'cuti', 'name' => 'Cuti']);
        $nik = Employee::where('nama', 'Rizky Maulana')->value('nik');
        $periksa = fn () => app(ValidatorKehadiran::class)->periksa(JenisKehadiran::Leave, ['nik' => $nik, 'kode_tipe' => 'cuti', 'tanggal_mulai' => '2026-11-13', 'tanggal_selesai' => '2026-11-16']);
        $this->assertSame(['2026-11-13', '2026-11-16'], array_column($periksa()['rekaman'], 'leave_date'), 'Senin-Jumat: Sabtu/Minggu dilewati');

        $ops = $this->pola('operasional', '1,2,3,4,5,6,7');
        Employee::where('nama', 'Rizky Maulana')->update(['pay_work_pattern_id' => $ops->id]);
        $this->assertCount(4, $periksa()['rekaman'], 'pola tujuh hari: Sabtu/Minggu ikut dihitung');
    }

    public function test_halaman_pola_hari_kerja(): void
    {
        $this->get('/pay/libur?tab=pola')->assertOk()->assertSee('Pola Hari Kerja')->assertSee('Belum ada pola');

        $this->post('/pay/libur/pola', ['code' => 'operasional', 'name' => 'Operasional', 'hari' => [7, 1, 2, 3, 4, 5, 6], 'is_default' => 0])->assertSessionHasNoErrors();
        $this->post('/pay/libur/pola', ['code' => 'kosong', 'name' => 'Kosong'])->assertSessionHasErrors('hari');
        $ops = WorkPattern::where('code', 'operasional')->firstOrFail();
        $this->assertSame('1,2,3,4,5,6,7', $ops->work_days);

        $it = Departemen::where('kode', 'IT')->firstOrFail();
        $this->put('/pay/libur/pola-pasang', ['departemen_id' => $it->id, 'pola_id' => $ops->id])->assertSessionHasNoErrors();
        $this->assertSame($ops->id, (int) $it->fresh()->pay_work_pattern_id);
        $rizky = Employee::where('nama', 'Rizky Maulana')->firstOrFail();
        $this->put('/pay/libur/pola-pasang', ['employee_id' => $rizky->id, 'pola_id' => $ops->id])->assertSessionHasNoErrors();
        $this->get('/pay/libur?tab=pola')->assertOk()->assertSee('Senin, Selasa, Rabu, Kamis, Jumat, Sabtu, Minggu')->assertSee('Rizky Maulana');

        $this->delete("/pay/libur/pola/{$ops->id}")->assertRedirect();
        $this->assertNull($it->fresh()->pay_work_pattern_id, 'hapus pola: departemen kembali ke bawaan');
        $this->assertSame(['created', 'deleted'], DB::table('audit_logs')->where('entity_type', WorkPattern::class)->orderBy('id')->pluck('action')->all());
        $this->actingAs(User::where('username', 'rizky')->firstOrFail())->get('/pay/libur?tab=pola')->assertForbidden();
    }

    public function test_kode_pola_dinormalkan_dan_pesan_validasi_bahasa_indonesia(): void
    {
        $this->post('/pay/libur/pola', ['code' => 'KW', 'name' => 'Kantor Wilayah', 'hari' => [1, 2, 3, 4, 5]])->assertSessionHasNoErrors();
        $this->post('/pay/libur/pola', ['code' => 'Ops Shift-2', 'name' => 'Shift', 'hari' => [1, 2]])->assertSessionHasNoErrors();
        $this->assertSame(['kw', 'ops_shift_2'], WorkPattern::orderBy('id')->pluck('code')->all());

        $this->post('/pay/libur/pola', ['code' => 'kw', 'name' => 'Dobel', 'hari' => [1]])->assertSessionHasErrors(['code' => 'kode sudah dipakai.']);
        $this->post('/pay/libur/pola', ['code' => 'a@b', 'name' => 'x', 'hari' => [1]])->assertSessionHasErrors(['code' => 'Kode hanya boleh huruf, angka, spasi, strip, atau garis bawah.']);
        $this->post('/pay/libur/pola', ['code' => 'x', 'name' => '', 'hari' => [1]])->assertSessionHasErrors(['name' => 'nama wajib diisi.']);
    }
}
