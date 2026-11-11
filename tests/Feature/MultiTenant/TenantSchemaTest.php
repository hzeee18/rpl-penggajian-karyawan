<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenant;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Fase 1a: skema multi-tenant (migration 2026_11_11_000001..4). */
final class TenantSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_TABLES = [
        'users', 'roles', 'privileges',
        'karyawan', 'departemen', 'departemen_manager',
        'kontrak', 'kontrak_komponen',
        'attendance', 'leave', 'overtime',
        'notifikasi', 'approval', 'approval_mapping', 'log_aktivitas', 'import_excel',
        'payslip_component', 'master_payroll', 'payroll', 'payroll_detail', 'payslip',
    ];

    private const NON_TENANT_TABLES = ['menus', 'golongan_ter', 'pajak', 'tarif_ter', 'sessions', 'jobs', 'cache'];

    public function test_daftar_tabel_bertenant_sama_dengan_migration(): void
    {
        $migration = require database_path('migrations/2026_11_11_000002_add_company_id_to_tenant_tables.php');
        $backfill = require database_path('migrations/2026_11_11_000003_backfill_company_id_default.php');

        $this->assertSame(self::TENANT_TABLES, $migration::TABLES);
        $this->assertSame(self::TENANT_TABLES, $backfill::TABLES);
    }

    public function test_semua_tabel_bertenant_punya_company_id(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'company_id'), "{$table}.company_id belum ada");
        }
        foreach (self::NON_TENANT_TABLES as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'company_id'), "{$table} seharusnya tidak bertenant");
        }
    }

    public function test_perusahaan_default_tersedia(): void
    {
        $this->assertDatabaseHas('companies', ['code' => 'DEFAULT', 'is_active' => true]);
    }

    public function test_backfill_mengisi_hanya_baris_tanpa_company_dan_bisa_dibalik(): void
    {
        $default = $this->companyId('DEFAULT');
        $lain = $this->company('LAIN');
        $tanpa = $this->karyawan(null, 'K-NULL');
        $milikLain = $this->karyawan($lain, 'K-LAIN');

        $backfill = require database_path('migrations/2026_11_11_000003_backfill_company_id_default.php');
        $backfill->up();

        $this->assertSame($default, (int) DB::table('karyawan')->where('id', $tanpa)->value('company_id'));
        $this->assertSame($lain, (int) DB::table('karyawan')->where('id', $milikLain)->value('company_id'));

        $backfill->down();

        $this->assertNull(DB::table('karyawan')->where('id', $tanpa)->value('company_id'));
        $this->assertSame($lain, (int) DB::table('karyawan')->where('id', $milikLain)->value('company_id'));
    }

    public function test_nik_karyawan_unik_per_perusahaan(): void
    {
        [$a, $b] = [$this->company('A'), $this->company('B')];
        $this->karyawan($a, 'K0001');
        $this->karyawan($b, 'K0001');

        $this->expectException(QueryException::class);
        $this->karyawan($a, 'K0001');
    }

    public function test_kode_departemen_unik_per_perusahaan(): void
    {
        [$a, $b] = [$this->company('A'), $this->company('B')];
        $this->departemen($a, 'IT');
        $this->departemen($b, 'IT');

        $this->expectException(QueryException::class);
        $this->departemen($a, 'IT');
    }

    public function test_nama_role_unik_per_perusahaan_termasuk_level_platform(): void
    {
        [$a, $b] = [$this->company('A'), $this->company('B')];
        $this->role($a, 'HR');
        $this->role($b, 'HR');
        $this->role(null, 'Peran Platform Uji');

        try {
            $this->role(null, 'Peran Platform Uji');
            $this->fail('Role platform dengan nama sama seharusnya ditolak');
        } catch (QueryException) {
        }

        $this->expectException(QueryException::class);
        $this->role($a, 'HR');
    }

    public function test_nomor_kontrak_unik_per_perusahaan(): void
    {
        [$a, $b] = [$this->company('A'), $this->company('B')];
        $this->kontrak($a, $this->karyawan($a, 'KA'), 'PKWT/IT/0001/I/2026');
        $this->kontrak($b, $this->karyawan($b, 'KB'), 'PKWT/IT/0001/I/2026');

        $this->expectException(QueryException::class);
        $this->kontrak($a, $this->karyawan($a, 'KA2'), 'PKWT/IT/0001/I/2026');
    }

    public function test_periode_master_payroll_dan_payroll_unik_per_perusahaan(): void
    {
        [$a, $b] = [$this->company('A'), $this->company('B')];
        foreach (['master_payroll', 'payroll'] as $table) {
            DB::table($table)->insert(['company_id' => $a, 'periode' => '2026-11']);
            DB::table($table)->insert(['company_id' => $b, 'periode' => '2026-11']);

            try {
                DB::table($table)->insert(['company_id' => $a, 'periode' => '2026-11']);
                $this->fail("{$table}: periode ganda dalam satu perusahaan seharusnya ditolak");
            } catch (QueryException) {
            }
        }

        $this->assertSame(2, DB::table('payroll')->where('periode', '2026-11')->whereIn('company_id', [$a, $b])->count());
    }

    public function test_username_tetap_unik_global(): void
    {
        [$a, $b] = [$this->company('A'), $this->company('B')];
        // nama unik khusus test: seed test lama sudah memakai username 'hr'
        $this->user($a, $this->role($a, 'HR'), 'hr.uji.tenant');

        $this->expectException(QueryException::class);
        $this->user($b, $this->role($b, 'HR'), 'hr.uji.tenant');
    }

    // ---- helper (DB::table: model lama belum tenant-aware di fase 1a) ----

    private function companyId(string $code): int
    {
        return (int) DB::table('companies')->where('code', $code)->value('id');
    }

    private function company(string $code): int
    {
        return DB::table('companies')->insertGetId(['code' => $code, 'name' => "Perusahaan {$code}", 'is_active' => true]);
    }

    private function karyawan(?int $company, string $nik): int
    {
        return DB::table('karyawan')->insertGetId(['company_id' => $company, 'nik' => $nik, 'nama' => "Karyawan {$nik}"]);
    }

    private function departemen(int $company, string $kode): int
    {
        return DB::table('departemen')->insertGetId(['company_id' => $company, 'kode' => $kode, 'nama' => "Dept {$kode}"]);
    }

    private function role(?int $company, string $nama): int
    {
        return DB::table('roles')->insertGetId(['company_id' => $company, 'nama' => $nama]);
    }

    private function user(int $company, int $role, string $username): int
    {
        return DB::table('users')->insertGetId([
            'company_id' => $company, 'role_id' => $role, 'username' => $username, 'password' => 'x',
        ]);
    }

    private function kontrak(int $company, int $karyawan, string $nomor): int
    {
        return DB::table('kontrak')->insertGetId([
            'company_id' => $company, 'karyawan_id' => $karyawan, 'nomor' => $nomor,
            'jenis' => 'PKWT', 'tgl_mulai' => '2026-01-01', 'gaji_pokok' => '5000000.00',
        ]);
    }
}
