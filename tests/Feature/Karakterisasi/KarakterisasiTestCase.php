<?php

declare(strict_types=1);

namespace Tests\Feature\Karakterisasi;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tes karakterisasi (AGENTS.md Fase 1): merekam perilaku aplikasi lama SEBELUM multi-tenant (1b),
 * agar setiap perubahan tampilan/isi/alur terdeteksi.
 *
 * - Data: DatabaseSeeder lama (deterministik: mt_srand/faker seed 2026) dengan waktu dikunci.
 * - Snapshot di __snapshots__/{driver}/. Snapshot yang belum ada dibuat otomatis.
 *   Untuk memperbarui snapshot secara sadar: set env KARAKTERISASI_UPDATE=1.
 */
abstract class KarakterisasiTestCase extends TestCase
{
    use RefreshDatabase;

    /** Waktu terkunci: Rabu, 11 November 2026 10:00 WIB. */
    protected const SEKARANG = '2026-11-11 10:00:00';

    private static bool $siap = false;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::SEKARANG, 'Asia/Jakarta'));
        Storage::fake('local'); // slip/laporan PDF tidak ditulis ke storage asli
    }

    /** Seed sekali per kelas dengan waktu terkunci, lalu setiap test di dalam transaksi. */
    protected function refreshTestDatabase(): void
    {
        if (! self::$siap) {
            Carbon::setTestNow(Carbon::parse(self::SEKARANG, 'Asia/Jakarta'));
            $this->artisan('migrate:fresh');
            $this->seed();
            $this->app[Kernel::class]->setArtisan(null);
            $this->updateLocalCacheOfInMemoryDatabases();
            RefreshDatabaseState::$migrated = true;
            self::$siap = true;
        }

        $this->beginDatabaseTransaction();
    }

    public static function tearDownAfterClass(): void
    {
        // kelas test lain harus migrate ulang dengan seed-nya sendiri
        self::$siap = false;
        RefreshDatabaseState::$migrated = false;

        parent::tearDownAfterClass();
    }

    /** Hapus bagian yang berubah tiap request (token CSRF). */
    protected function normalisasi(string $html): string
    {
        $html = preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="TOKEN"', $html);
        $html = preg_replace('/<meta name="csrf-token" content="[^"]*"/', '<meta name="csrf-token" content="TOKEN"', $html);

        return str_replace("\r\n", "\n", (string) $html);
    }

    /** Bandingkan dengan snapshot; buat snapshot baru bila belum ada (atau KARAKTERISASI_UPDATE=1). */
    protected function cocokSnapshot(string $nama, string $isi): void
    {
        $file = __DIR__.'/__snapshots__/'.DB::getDriverName().'/'.$nama;

        if (! is_file($file) || getenv('KARAKTERISASI_UPDATE') === '1') {
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, $isi);
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertSame(file_get_contents($file), $isi, "Snapshot berubah: {$nama}");
    }

    /** @param  array<mixed>  $data */
    protected function cocokSnapshotJson(string $nama, array $data): void
    {
        $this->cocokSnapshot($nama, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }
}
