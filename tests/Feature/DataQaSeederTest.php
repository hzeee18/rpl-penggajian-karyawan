<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payroll\Models\Payslip;
use Database\Seeders\DataQaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Snapshot data QA (database/seeders/data/qa_snapshot.json) terpasang utuh di database baru.
 */
final class DataQaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_terpasang_utuh_di_database_baru(): void
    {
        $file = database_path(DataQaSeeder::FILE);
        if (! is_file($file)) {
            $this->markTestSkipped('Snapshot QA belum dibuat (php artisan seed:ekspor-qa).');
        }
        Storage::fake('local');
        $snapshot = json_decode((string) file_get_contents($file), true);

        $this->seed(DataQaSeeder::class);

        foreach ($snapshot['tabel'] as $tabel => $baris) {
            $this->assertSame(count($baris), DB::table($tabel)->count(), "jumlah baris {$tabel}");
        }
        // kolom terenkripsi dienkripsi ulang dengan APP_KEY instalasi ini
        foreach ($snapshot['tabel']['karyawan'] as $k) {
            if (($k['bank_account_no'] ?? null) !== null) {
                $this->assertSame($k['bank_account_no'], Crypt::decryptString((string) DB::table('karyawan')->where('id', $k['id'])->value('bank_account_no')));
            }
        }
        // semua akun login dengan password "password"
        $user = $snapshot['tabel']['users'][0]['username'];
        $this->post('/login', ['username' => $user, 'password' => DataQaSeeder::PASSWORD])->assertRedirect('/dashboard');
        // PDF slip dibuat ulang
        foreach (Payslip::withoutGlobalScopes()->get() as $slip) {
            $this->assertTrue($slip->file_path === null ? $slip->note !== null : Storage::disk('local')->exists($slip->file_path));
        }

        // hanya untuk database baru
        $this->expectException(\RuntimeException::class);
        $this->seed(DataQaSeeder::class);
    }
}
