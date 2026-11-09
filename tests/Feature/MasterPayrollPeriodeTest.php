<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterPayrollPeriodeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_periode_yang_sudah_dibuat_ditolak(): void
    {
        $this->actingAs(User::where('username', 'hr')->first())
            ->post('/master-payroll', ['periode' => now()->format('Y-m'), 'tgl_mulai' => '2030-01-01', 'tgl_selesai' => '2030-01-30', 'hari_kerja' => 20])
            ->assertSessionHasErrors('periode');
    }
}
