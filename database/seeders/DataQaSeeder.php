<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Slip\BuatPdfSlip;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Data QA terkini (snapshot database dev) untuk instalasi baru, mis. laptop teman satu tim.
 *
 *   php artisan migrate:fresh
 *   php artisan db:seed --class=DataQaSeeder
 *
 * Snapshot dibuat dengan `php artisan seed:ekspor-qa` ke database/seeders/data/qa_snapshot.json.
 * - Hanya untuk database BARU (menolak bila sudah ada user/karyawan); tabel di snapshot dikosongkan lalu diisi.
 * - Kolom terenkripsi disimpan polos di snapshot lalu dienkripsi ulang dengan APP_KEY instalasi ini.
 * - Semua password akun diset ke "password".
 * - PDF slip dibuat ulang (file PDF tidak ikut snapshot).
 * Tidak dipanggil DatabaseSeeder.
 */
final class DataQaSeeder extends Seeder
{
    public const FILE = 'seeders/data/qa_snapshot.json';

    public const PASSWORD = 'password';

    /** Tabel sistem yang tidak ikut snapshot. */
    public const ABAIKAN = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    /** Kolom terenkripsi (cast `encrypted` / Crypt::encryptString), tergantung APP_KEY. */
    public const ENKRIPSI = ['karyawan' => ['npwp', 'bank_account_no'], 'pay_run_employees' => ['bank_account_no']];

    public function run(): void
    {
        $file = database_path(self::FILE);
        if (! is_file($file)) {
            throw new RuntimeException('Snapshot tidak ditemukan: '.$file.'. Buat dengan `php artisan seed:ekspor-qa`.');
        }
        if (DB::table('users')->exists() || DB::table('karyawan')->exists()) {
            throw new RuntimeException('Database sudah berisi user/karyawan. DataQaSeeder hanya untuk database baru: jalankan `php artisan migrate:fresh` dulu (tanpa --seed).');
        }
        $snapshot = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($snapshot) {
                if (DB::getDriverName() === 'sqlite') {
                    // di dalam transaksi SQLite PRAGMA foreign_keys tidak berlaku; tunda pemeriksaan sampai commit
                    DB::statement('PRAGMA defer_foreign_keys = ON');
                }
                foreach ($snapshot['tabel'] as $tabel => $baris) {
                    if (! Schema::hasTable($tabel)) {
                        continue;
                    }
                    DB::table($tabel)->delete(); // isi bawaan migrasi (mis. perusahaan DEFAULT) diganti snapshot
                    foreach (array_chunk(array_map(fn ($b) => $this->siapkan($tabel, $b), $baris), 200) as $potong) {
                        DB::table($tabel)->insert($potong);
                    }
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->pdfSlip();
        $this->command?->info('Data QA terpasang dari snapshot '.($snapshot['dibuat'] ?? '').'. Semua akun memakai password "'.self::PASSWORD.'".');
    }

    /** @param array<string, mixed> $b */
    private function siapkan(string $tabel, array $b): array
    {
        foreach (self::ENKRIPSI[$tabel] ?? [] as $kolom) {
            if (isset($b[$kolom]) && $b[$kolom] !== '') {
                $b[$kolom] = Crypt::encryptString((string) $b[$kolom]);
            }
        }
        if ($tabel === 'users') {
            $b['password'] = Hash::make(self::PASSWORD);
            $b['remember_token'] = null;
        }
        if ($tabel === 'pay_payslips') {
            $b['file_path'] = null; // dibuat ulang
        }

        return $b;
    }

    /** PDF slip dibuat ulang per perusahaan (berpassword, folder per perusahaan). */
    private function pdfSlip(): void
    {
        $konteks = app(CurrentCompany::class);
        foreach (DB::table('pay_payslips')->distinct()->pluck('company_id') as $companyId) {
            $konteks->runAs((int) $companyId, function () {
                foreach (Payslip::whereNull('file_path')->get() as $slip) {
                    app(BuatPdfSlip::class)->handle($slip);
                }
            });
        }
    }

    /**
     * Isi snapshot dari database saat ini (dipakai perintah seed:ekspor-qa). Kolom generated dilewati,
     * kolom terenkripsi didekripsi, password tidak disalin.
     *
     * @return array{dibuat: string, tabel: array<string, list<array<string, mixed>>>}
     */
    public static function ekspor(): array
    {
        $tabel = [];
        foreach (Schema::getTables() as $t) {
            $nama = $t['name'];
            if (in_array($nama, self::ABAIKAN, true)) {
                continue;
            }
            $generated = collect(Schema::getColumns($nama))->filter(fn ($c) => ! empty($c['generation']))->pluck('name')->all();
            $tabel[$nama] = DB::table($nama)->orderBy(Schema::hasColumn($nama, 'id') ? 'id' : DB::raw('1'))->get()->map(function ($r) use ($nama, $generated) {
                $b = array_diff_key((array) $r, array_flip($generated));
                foreach (self::ENKRIPSI[$nama] ?? [] as $kolom) {
                    if (isset($b[$kolom]) && $b[$kolom] !== '') {
                        $b[$kolom] = Crypt::decryptString((string) $b[$kolom]);
                    }
                }
                unset($b['password'], $b['remember_token']);

                return $b;
            })->all();
        }

        return ['dibuat' => now()->toDateTimeString(), 'tabel' => $tabel];
    }
}
