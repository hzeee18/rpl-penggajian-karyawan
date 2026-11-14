<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sumber data variabel base baru `tax_histories` (pay_tax_monthly_histories): akumulasi bruto dan PPh21
 * bulan-bulan sebelumnya di tahun pajak yang sama, bahan rumus PPh21 masa Desember / resign di master payroll.
 * Hanya menambah satu nilai enum pay_variables.source; data tidak diubah.
 */
return new class extends Migration
{
    private const LAMA = ['attendances', 'leaves', 'overtimes', 'employees', 'contracts', 'holidays'];

    public function up(): void
    {
        Schema::table('pay_variables', function (Blueprint $t) {
            $t->enum('source', [...self::LAMA, 'tax_histories'])->nullable()->change();
        });
    }

    public function down(): void
    {
        // tidak menghapus data: rollback ditolak selama masih ada variabel yang memakai sumber ini
        if (DB::table('pay_variables')->where('source', 'tax_histories')->exists()) {
            throw new RuntimeException('Masih ada variabel master payroll dengan sumber tax_histories. Ubah variabel tersebut dulu sebelum rollback.');
        }
        Schema::table('pay_variables', function (Blueprint $t) {
            $t->enum('source', self::LAMA)->nullable()->change();
        });
    }
};
