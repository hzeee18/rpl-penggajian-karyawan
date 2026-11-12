<?php

declare(strict_types=1);

namespace Tests\Feature\PayrollBaru;

use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Actions\BuatKontrak;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Item;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\MasterVersion;
use App\Domain\Payroll\Models\ReferenceTable;
use App\Domain\Payroll\Models\ReferenceTableVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\MultiTenant\BuatPerusahaanLain;
use Tests\TestCase;

/** Fase 5: master payroll berversi dan tabel referensi lewat HTTP. */
final class MasterReferensiTest extends TestCase
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
        foreach ([['gaji_pokok', 'earning', true], ['uang_makan', 'earning', true]] as [$code, $type, $kontrak]) {
            Component::create(['code' => $code, 'name' => ucwords(str_replace('_', ' ', $code)), 'type' => $type, 'is_taxable' => true, 'is_contractable' => $kontrak, 'default_amount' => '0']);
        }
    }

    private function versi(): MasterVersion
    {
        return MasterVersion::latest('id')->firstOrFail();
    }

    public function test_alur_master_buat_isi_validasi_terbit_salin_dan_uji(): void
    {
        $this->actingAs($this->hr)->post('/pay/master', ['code' => 'usual', 'name' => 'Payroll Usual'])->assertSessionHasNoErrors();
        $v = $this->versi();
        $isi = fn (string $jenis, array $data) => $this->actingAs($this->hr)->post("/pay/master/versi/{$v->id}/{$jenis}", $data);

        $isi('param', ['code' => 'pembagi', 'name' => 'Pembagi', 'value' => '22'])->assertSessionHasNoErrors();
        $isi('variabel', ['code' => 'hadir', 'name' => 'Hadir', 'kind' => 'base', 'source' => 'attendances', 'aggregate' => 'count', 'sort_order' => 1])->assertSessionHasNoErrors();
        $isi('item', ['component_id' => Component::where('code', 'gaji_pokok')->value('id'), 'code' => 'gaji_pokok', 'calc_type' => 'contract', 'item_role' => 'normal', 'sort_order' => 1])->assertSessionHasNoErrors();
        $isi('item', ['component_id' => Component::where('code', 'uang_makan')->value('id'), 'code' => 'uang_makan', 'calc_type' => 'formula',
            'formula' => "CONTRACT('uang_makan') / pembagi * (hadir + tidak_ada)", 'item_role' => 'normal', 'sort_order' => 2])->assertSessionHasNoErrors();
        $isi('variabel', ['code' => 'rusak', 'name' => 'x', 'kind' => 'derived', 'formula' => '1 +* 2', 'sort_order' => 2])->assertSessionHasErrors('formula');
        $isi('param', ['code' => 'pembagi', 'name' => 'dobel', 'value' => '1'])->assertSessionHasErrors('code');

        $this->actingAs($this->hr)->get("/pay/master/versi/{$v->id}")->assertOk()->assertSee('merujuk &#039;tidak_ada&#039;', false);
        $this->actingAs($this->hr)->post("/pay/master/versi/{$v->id}/terbitkan")->assertSessionHasErrors('versi');
        $this->assertSame('draft', $v->fresh()->status);

        $item = $v->items()->where('code', 'uang_makan')->firstOrFail();
        $isi('item', ['ubah_id' => $item->id, 'component_id' => $item->component_id, 'code' => 'uang_makan', 'calc_type' => 'formula',
            'formula' => "ROUND(CONTRACT('uang_makan') / pembagi * hadir)", 'item_role' => 'normal', 'sort_order' => 2])->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->post("/pay/master/versi/{$v->id}/terbitkan")->assertSessionHasNoErrors();

        $v->refresh();
        $this->assertSame('published', $v->status);
        $this->assertSame($v->id, (int) Master::firstOrFail()->current_version_id);
        $this->assertSame('Uang Makan', $item->fresh()->name, 'nama disalin dari katalog');

        // versi terbit tidak bisa diubah -> salin ke versi baru
        $isi('param', ['code' => 'baru', 'name' => 'x', 'value' => '1'])->assertSessionHasErrors('versi');
        $this->actingAs($this->hr)->post("/pay/master/versi/{$v->id}/salin")->assertRedirect();
        $v2 = $this->versi();
        $this->assertSame([2, 'draft', 1, 1, 2], [$v2->version_no, $v2->status, $v2->params()->count(), $v2->variables()->count(), $v2->items()->count()]);

        // uji rumus untuk satu karyawan
        $rizky = Employee::where('nama', 'Rizky Maulana')->firstOrFail();
        app(BuatKontrak::class)->handle(['employee_id' => $rizky->id, 'contract_name' => 'PKWT', 'start_date' => '2026-01-01'],
            [Component::where('code', 'gaji_pokok')->value('id') => '5700000', Component::where('code', 'uang_makan')->value('id') => '660000']);
        $this->actingAs($this->hr)->get("/pay/master/versi/{$v->id}?uji_karyawan={$rizky->id}&uji_periode=2026-11")
            ->assertOk()->assertSee('Take home pay')->assertSee('Rp 5.700.000');
        $this->assertSame(['created', 'published', 'copied'], DB::table('audit_logs')->whereIn('entity_type', [Master::class, MasterVersion::class])->orderBy('id')->pluck('action')->all());
    }

    public function test_pilihan_pph21_ter_otomatis_tanpa_mengetik_rumus(): void
    {
        // tabel TER nasional dari file UJI berisi angka FIKTIF (file resmi tidak disentuh)
        $file = tempnam(sys_get_temp_dir(), 'ter').'.csv';
        file_put_contents($file, "tabel,kunci,batas_bawah,batas_atas,hasil_angka,hasil_teks\nkategori_ptkp,TK/0,,,,A\nkategori_ptkp,K/0,,,,A\nter_bulanan,A,0,,0.01,\n");
        (new \Database\Seeders\TerPph21Seeder)->pasang($file);
        @unlink($file);
        $pph = Component::create(['code' => 'pph21', 'name' => 'PPh21', 'type' => 'deduction', 'is_taxable' => false, 'is_contractable' => false, 'default_amount' => '0']);

        $this->actingAs($this->hr)->post('/pay/master', ['code' => 'pajak', 'name' => 'Dengan PPh21'])->assertSessionHasNoErrors();
        $v = $this->versi();
        $isi = fn (array $data) => $this->actingAs($this->hr)->post("/pay/master/versi/{$v->id}/item", $data);
        $isi(['component_id' => Component::where('code', 'gaji_pokok')->value('id'), 'code' => 'gaji_pokok', 'calc_type' => 'contract', 'item_role' => 'normal', 'sort_order' => 1])->assertSessionHasNoErrors();
        // rumus & peran yang terkirim diabaikan: sistem mengisi rumus TER dan peran pph21
        $isi(['component_id' => $pph->id, 'code' => 'pph21', 'calc_type' => 'pph21_ter', 'formula' => '', 'item_role' => 'normal', 'sort_order' => 99])->assertSessionHasNoErrors();

        $item = $v->items()->where('code', 'pph21')->firstOrFail();
        $this->assertSame(['formula', Item::RUMUS_PPH21_TER, 'pph21'], [$item->calc_type, $item->formula, $item->item_role]);
        $this->actingAs($this->hr)->get("/pay/master/versi/{$v->id}")->assertOk()->assertSee('PPh21 TER otomatis');
        $this->actingAs($this->hr)->get("/pay/master/versi/{$v->id}?form=item&ubah={$item->id}")->assertOk()
            ->assertSee('<option value="pph21_ter" selected>', false);
        $this->actingAs($this->hr)->post("/pay/master/versi/{$v->id}/terbitkan")->assertSessionHasNoErrors();

        // hasil: 1% x bruto kena pajak (angka uji)
        $rizky = Employee::where('nama', 'Rizky Maulana')->firstOrFail();
        app(BuatKontrak::class)->handle(['employee_id' => $rizky->id, 'contract_name' => 'PKWT', 'start_date' => '2026-01-01'],
            [Component::where('code', 'gaji_pokok')->value('id') => '5700000']);
        $this->actingAs($this->hr)->get("/pay/master/versi/{$v->id}?uji_karyawan={$rizky->id}&uji_periode=2026-11")->assertOk()->assertSee('Rp 57.000');
    }

    public function test_tabel_referensi_perusahaan_dan_nasional(): void
    {
        // Tabel Referensi dikelola Admin perusahaan (AUDIT.md §40); HR tidak punya menu ini
        $this->actingAs($this->hr)->get('/pay/referensi')->assertForbidden();
        $sa = fn () => $this->actingAs(User::where('username', 'admin')->firstOrFail());

        $sa()->post('/pay/referensi', ['code' => 'tarif_lembur', 'name' => 'Tarif lembur'])->assertSessionHasNoErrors();
        $versi = ReferenceTableVersion::latest('id')->firstOrFail();
        $sa()->post("/pay/referensi/versi/{$versi->id}/baris", ['key_value' => 'OPS', 'result_number' => '30000'])->assertSessionHasNoErrors();
        $sa()->post("/pay/referensi/versi/{$versi->id}/baris", ['range_from' => '10', 'range_to' => '5', 'result_number' => '1'])->assertSessionHasErrors('range_to');
        $sa()->post("/pay/referensi/versi/{$versi->id}/terbitkan", ['effective_from' => '2026-01-01'])->assertSessionHasNoErrors();
        $sa()->post("/pay/referensi/versi/{$versi->id}/baris", ['key_value' => 'X', 'result_number' => '1'])->assertSessionHasErrors('versi');
        $this->assertSame((int) $this->hr->company_id, (int) ReferenceTable::where('code', 'tarif_lembur')->value('company_id'));

        // Admin perusahaan (punya menu ini) tidak boleh membuat / mengubah tabel nasional
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($admin)->post('/pay/referensi', ['code' => 'ter_nasional', 'name' => 'TER', 'nasional' => 1])->assertForbidden();
        $nasional = app(CurrentCompany::class)->lintasPerusahaan('uji: buat tabel nasional seperti Super Admin', function () {
            $t = ReferenceTable::create(['company_id' => null, 'code' => 'ter_uji', 'name' => 'TER (uji)']);

            return ReferenceTableVersion::create(['company_id' => null, 'reference_table_id' => $t->id, 'version_no' => 1, 'status' => 'draft']);
        });
        $this->actingAs($admin)->get('/pay/referensi')->assertSee('ter_uji')->assertSee('Nasional');
        $this->actingAs($admin)->post("/pay/referensi/versi/{$nasional->id}/baris", ['key_value' => 'A', 'result_number' => '0.01'])->assertForbidden();
        $this->actingAs($admin)->post("/pay/referensi/versi/{$nasional->id}/salin")->assertForbidden();

        // tabel perusahaan lain tidak terlihat dan tidak bisa dibuka
        $b = $this->perusahaanLain();
        $versiB = app(CurrentCompany::class)->runAs((int) $b['company']->id, function () {
            $t = ReferenceTable::create(['code' => 'rahasia_b', 'name' => 'Rahasia B']);

            return ReferenceTableVersion::create(['reference_table_id' => $t->id, 'version_no' => 1, 'status' => 'draft']);
        });
        $this->actingAs($admin)->get('/pay/referensi')->assertDontSee('rahasia_b');
        $this->actingAs($admin)->get("/pay/referensi/versi/{$versiB->id}")->assertNotFound();
    }
}
