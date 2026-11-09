<?php

namespace Tests\Feature;

use App\Models\Kontrak;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KontrakPerbaruiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_semua_kontrak_punya_nomor_unik(): void
    {
        $this->assertSame(0, Kontrak::whereNull('nomor')->count());
        $this->assertSame(Kontrak::count(), Kontrak::distinct()->count('nomor'));
    }

    public function test_perbarui_kontrak_bikin_kontrak_baru_bernomor_baru(): void
    {
        $lama = Kontrak::where('is_active', true)->where('jenis', 'PKWT')->get()->first(fn ($k) => $k->bisaDiperbarui());
        $this->assertNotNull($lama, 'butuh kontrak PKWT yang habis <= 3 bulan di seeder');
        $hr = User::where('username', 'hr')->first();

        $this->actingAs($hr)->post('/kontrak', [
            'kontrak_sebelumnya_id' => $lama->id, 'karyawan_id' => $lama->karyawan_id, 'jenis' => 'PKWT',
            'tgl_mulai' => $lama->tgl_akhir->copy()->addDay()->toDateString(), 'tgl_akhir' => $lama->tgl_akhir->copy()->addYear()->toDateString(),
            'gaji_pokok' => 9000000,
        ])->assertSessionHas('sukses');

        $baru = Kontrak::where('kontrak_sebelumnya_id', $lama->id)->first();
        $this->assertNotNull($baru);
        $this->assertNotSame($lama->nomor, $baru->nomor);
        $this->assertSame('menunggu', $baru->status_approval);
        $this->assertFalse($lama->fresh()->bisaDiperbarui(), 'tombol perbarui hilang setelah diajukan');
    }

    public function test_kontrak_baru_ditolak_kalau_karyawan_sudah_punya_kontrak_aktif(): void
    {
        $aktif = Kontrak::where('is_active', true)->first();
        $this->actingAs(User::where('username', 'hr')->first())->post('/kontrak', [
            'karyawan_id' => $aktif->karyawan_id, 'jenis' => 'PKWTT', 'tgl_mulai' => now()->toDateString(), 'gaji_pokok' => 5000000,
        ])->assertSessionHasErrors('karyawan_id');
    }
}
