<?php

declare(strict_types=1);

namespace App\Domain\Company\Actions;

use App\Domain\Company\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Hapus PERMANEN satu perusahaan beserta seluruh datanya (AUDIT.md §76, keputusan pemilik): semua baris ber-company_id
 * (user, role, karyawan, kontrak, absensi, payroll, slip, log, dst.), tabel anak tanpa company_id ikut lewat cascade,
 * lalu file per perusahaan (slip PDF, tanda tangan). Hanya Super Admin; perusahaan DEFAULT (templat role) tidak bisa dihapus.
 * Lintas perusahaan disengaja: query eksplisit ke satu company_id, bukan lewat scope.
 */
final class HapusPerusahaan
{
    public function handle(Company $company): void
    {
        if ($company->code === Company::DEFAULT_CODE) {
            throw new RuntimeException('Perusahaan DEFAULT adalah templat role, tidak bisa dihapus.');
        }
        $this->hapusData($company, [], true);
    }

    /**
     * Kosongkan perusahaan DEFAULT menjadi templat murni (AUDIT.md §78): semua datanya dihapus kecuali role, hak akses,
     * dan approval mapping yang disalin ke perusahaan baru. Baris perusahaannya tetap ada.
     */
    public function kosongkanTemplat(): void
    {
        $default = Company::where('code', Company::DEFAULT_CODE)->firstOrFail();
        $this->hapusData($default, ['roles', 'privileges', 'approval_mapping'], false);
    }

    /** @param list<string> $simpan tabel yang tidak dihapus */
    private function hapusData(Company $company, array $simpan, bool $hapusPerusahaan): void
    {
        $id = (int) $company->id;
        $tabel = array_values(array_filter(Schema::getTableListing(schemaQualified: false),
            fn (string $t) => $t !== 'companies' && ! in_array($t, $simpan, true) && Schema::hasColumn($t, 'company_id')));

        DB::transaction(function () use ($id, $tabel, $company, $hapusPerusahaan) {
            // putus rujukan melingkar/ke tabel sendiri dulu (master <-> versi aktif, user -> approver)
            DB::table('pay_masters')->where('company_id', $id)->update(['current_version_id' => null]);
            DB::table('users')->where('company_id', $id)->update(['approver_user_id' => null]);
            // urutan hapus mengikuti foreign key tanpa mematikan pemeriksaannya: ulangi tabel yang masih ditolak
            $sisa = $tabel;
            for ($putaran = 0; $sisa !== [] && $putaran < 20; $putaran++) {
                $gagal = [];
                foreach ($sisa as $t) {
                    try {
                        DB::table($t)->where('company_id', $id)->delete();
                    } catch (QueryException) {
                        $gagal[] = $t;
                    }
                }
                if (count($gagal) === count($sisa)) {
                    break;
                }
                $sisa = $gagal;
            }
            if ($sisa !== []) {
                throw new RuntimeException('Data perusahaan tidak bisa dihapus karena masih dirujuk tabel: '.implode(', ', $sisa).'.');
            }
            if ($hapusPerusahaan) {
                DB::table('companies')->where('id', $company->id)->delete();
            }
        });

        foreach (["payslips/{$id}", "signatures/{$id}", "imports/{$id}"] as $folder) {
            Storage::disk('local')->deleteDirectory($folder);
        }
    }
}
