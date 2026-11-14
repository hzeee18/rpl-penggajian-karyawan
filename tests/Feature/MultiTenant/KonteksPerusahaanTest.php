<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenant;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Models\Departemen;
use App\Models\Karyawan;
use App\Models\Kontrak;
use App\Models\User;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** Fase 1b: aturan konteks perusahaan (CurrentCompany) dan trait BelongsToCompany. */
final class KonteksPerusahaanTest extends TestCase
{
    use RefreshDatabase;

    private int $default;

    private int $lain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->default = (int) Company::where('code', Company::DEFAULT_CODE)->value('id');
        $this->lain = (int) Company::create(['code' => 'LAIN', 'name' => 'Perusahaan Lain'])->id;
    }

    private function konteks(): CurrentCompany
    {
        return app(CurrentCompany::class);
    }

    private function dept(string $kode): Departemen
    {
        return Departemen::create(['kode' => $kode, 'nama' => "Dept {$kode}"]);
    }

    public function test_kode_test_tanpa_login_memakai_perusahaan_default(): void
    {
        $this->assertSame($this->default, (int) $this->dept('A1')->company_id);
    }

    public function test_run_as_mengisi_dan_membatasi_lalu_konteks_dipulihkan(): void
    {
        $this->dept('A1');
        $lain = $this->konteks()->runAs($this->lain, function () {
            $this->dept('B1');

            return Departemen::pluck('kode')->all();
        });

        $this->assertSame(['B1'], $lain);
        $this->assertSame(['A1'], Departemen::whereIn('kode', ['A1', 'B1'])->pluck('kode')->all());
    }

    public function test_user_login_menentukan_perusahaan(): void
    {
        $this->konteks()->runAs($this->lain, fn () => $this->dept('B1'));
        $userLain = new User(['username' => 'x']);
        $userLain->forceFill(['company_id' => $this->lain]);

        $this->actingAs($userLain);

        $this->assertSame($this->lain, $this->konteks()->id());
        $this->assertSame(['B1'], Departemen::pluck('kode')->all());
    }

    public function test_request_http_tanpa_login_tidak_melihat_data_dan_tidak_bisa_create(): void
    {
        $this->dept('A1');
        $this->konteks()->enterHttpRequest();

        try {
            $this->assertNull($this->konteks()->id());
            $this->assertFalse($this->konteks()->isLintasPerusahaan());
            $this->assertSame(0, Departemen::count());

            $this->expectException(RuntimeException::class);
            $this->dept('X1');
        } finally {
            $this->konteks()->leaveHttpRequest();
        }
    }

    public function test_artisan_tanpa_konteks_tidak_melihat_data_dan_tidak_bisa_create(): void
    {
        $this->dept('A1');
        $this->konteks()->useFallback(null); // seperti artisan/scheduler: tanpa user, tanpa runAs

        $this->assertNull($this->konteks()->id());
        $this->assertFalse($this->konteks()->isLintasPerusahaan());
        $this->assertSame(0, Departemen::count());

        $this->expectException(RuntimeException::class);
        $this->dept('C1');
    }

    public function test_lintas_perusahaan_eksplisit_membaca_semua_tapi_create_tetap_ditolak(): void
    {
        $this->dept('A1');
        $this->konteks()->runAs($this->lain, fn () => $this->dept('B1'));

        $semua = $this->konteks()->lintasPerusahaan('uji: baca semua perusahaan', fn () => Departemen::whereIn('kode', ['A1', 'B1'])->pluck('kode')->all());
        $this->assertEqualsCanonicalizing(['A1', 'B1'], $semua);
        $this->assertFalse($this->konteks()->isLintasPerusahaan(), 'mode lintas berakhir setelah callback');

        // runAs di dalam lintas: bingkai terdalam yang berlaku
        $this->assertSame(['B1'], $this->konteks()->lintasPerusahaan('uji', fn () => $this->konteks()->runAs($this->lain, fn () => Departemen::pluck('kode')->all())));

        $this->expectException(RuntimeException::class);
        $this->konteks()->lintasPerusahaan('uji: create', fn () => $this->dept('C1'));
    }

    public function test_lintas_perusahaan_wajib_alasan(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->konteks()->lintasPerusahaan('  ', fn () => null);
    }

    public function test_migration_berjalan_lintas_perusahaan(): void
    {
        $this->dept('A1');
        $this->konteks()->runAs($this->lain, fn () => $this->dept('B1'));
        $this->konteks()->useFallback(null);

        event(new MigrationsStarted('up'));
        $selamaMigration = Departemen::whereIn('kode', ['A1', 'B1'])->count();
        event(new MigrationsEnded('up'));

        $this->assertSame(2, $selamaMigration);
        $this->assertSame(0, Departemen::count(), 'setelah migration kembali tanpa konteks');
    }

    public function test_perintah_kontrak_aktifkan_berjalan_per_perusahaan(): void
    {
        $siapkan = function (string $nik) {
            $karyawan = Karyawan::create(['nik' => $nik, 'nama' => "Karyawan {$nik}"]);

            return Kontrak::create([
                'karyawan_id' => $karyawan->id, 'jenis' => 'PKWT', 'tgl_mulai' => now()->subDay()->toDateString(),
                'gaji_pokok' => 5000000, 'status_approval' => 'disetujui', 'is_active' => false,
            ]);
        };
        $kontrakA = $siapkan('A-001');
        $kontrakB = $this->konteks()->runAs($this->lain, fn () => $siapkan('B-001'));
        $this->konteks()->useFallback(null); // perintah artisan: tanpa konteks bawaan

        $this->artisan('kontrak:aktifkan')->assertSuccessful();

        $this->assertTrue((bool) DB::table('kontrak')->where('id', $kontrakA->id)->value('is_active'));
        $this->assertTrue((bool) DB::table('kontrak')->where('id', $kontrakB->id)->value('is_active'));
    }

    public function test_company_id_tidak_bisa_dipindah(): void
    {
        $dept = $this->dept('A1');

        // lewat mass assignment diabaikan (company_id tidak fillable di model lama)
        $dept->update(['company_id' => $this->lain]);
        $this->assertSame($this->default, (int) $dept->fresh()->company_id);

        // dipaksa pun ditolak
        $this->expectException(RuntimeException::class);
        $dept->forceFill(['company_id' => $this->lain])->save();
    }

    public function test_pivot_manager_departemen_ikut_terisi_company_id(): void
    {
        [$dept, $karyawan] = $this->konteks()->runAs($this->lain, fn () => [
            $this->dept('B1'),
            Karyawan::create(['nik' => 'B-001', 'nama' => 'Manager B']),
        ]);

        $this->konteks()->runAs($this->lain, fn () => $dept->aturManager([$karyawan->id]));

        $this->assertDatabaseHas('departemen_manager', ['departemen_id' => $dept->id, 'karyawan_id' => $karyawan->id, 'company_id' => $this->lain]);
        $this->assertSame(['Manager B'], $this->konteks()->runAs($this->lain, fn () => $dept->fresh()->managers->pluck('nama')->all()));
    }
}
