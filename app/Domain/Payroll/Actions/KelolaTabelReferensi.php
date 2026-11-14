<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Models\ReferenceTable;
use App\Domain\Payroll\Models\ReferenceTableRow;
use App\Domain\Payroll\Models\ReferenceTableVersion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Use case tabel referensi berversi. Tabel nasional (company_id NULL, mis. TER) hanya boleh diubah Super Admin.
 * Isi versi terbit/terkunci tidak bisa diubah: salin ke versi baru.
 */
final class KelolaTabelReferensi
{
    public function __construct(private readonly CurrentCompany $konteks, private readonly AuditLogger $audit) {}

    /** @param array{code: string, name: string, description?: ?string} $data */
    public function buat(array $data, bool $nasional): ReferenceTable
    {
        if ($nasional && ! $this->konteks->isSuperAdmin()) {
            throw new HttpException(403, 'Tabel nasional hanya bisa dibuat Super Admin.');
        }

        return DB::transaction(function () use ($data, $nasional): ReferenceTable {
            $milik = $nasional ? ['company_id' => null] : [];
            $tabel = ReferenceTable::create($data + $milik);
            ReferenceTableVersion::create($milik + ['reference_table_id' => $tabel->id, 'version_no' => 1, 'status' => 'draft', 'created_by' => Auth::id()]);
            $this->audit->log('created', $tabel, new: $data + ['nasional' => $nasional]);

            return $tabel;
        });
    }

    /** @param array<string, ?string> $data key_value, range_from, range_to, result_number, result_text, sort_order */
    public function simpanBaris(ReferenceTableVersion $versi, array $data, ?ReferenceTableRow $ubah = null): ReferenceTableRow
    {
        $this->pastikanBolehUbah($versi);
        if (isset($data['range_from'], $data['range_to']) && bccomp((string) $data['range_to'], (string) $data['range_from'], 2) < 0) {
            throw ValidationException::withMessages(['range_to' => 'Batas atas harus lebih besar atau sama dengan batas bawah.']);
        }

        return DB::transaction(function () use ($versi, $data, $ubah): ReferenceTableRow {
            if ($ubah !== null) {
                $lama = $ubah->getAttributes();
                $ubah->fill($data)->save();
                $this->audit->log('updated', $ubah, old: $lama, new: $ubah->getAttributes());

                return $ubah;
            }
            $baris = ReferenceTableRow::create($data + ['company_id' => $versi->company_id, 'version_id' => $versi->id]);
            $this->audit->log('created', $baris, new: $data);

            return $baris;
        });
    }

    public function hapusBaris(ReferenceTableVersion $versi, ReferenceTableRow $baris): void
    {
        $this->pastikanBolehUbah($versi);
        DB::transaction(function () use ($baris) {
            $this->audit->log('deleted', $baris, old: $baris->getAttributes());
            $baris->delete();
        });
    }

    public function terbitkan(ReferenceTableVersion $versi, ?string $berlakuMulai): void
    {
        $this->pastikanBolehUbah($versi);
        if ($versi->rows()->count() === 0) {
            throw ValidationException::withMessages(['versi' => 'Versi belum berisi baris.']);
        }
        DB::transaction(function () use ($versi, $berlakuMulai) {
            $versi->forceFill(['status' => 'published', 'published_at' => now(), 'effective_from' => $berlakuMulai])->save();
            $this->audit->log('published', $versi, new: ['version_no' => $versi->version_no, 'effective_from' => $berlakuMulai]);
        });
    }

    public function salin(ReferenceTableVersion $sumber): ReferenceTableVersion
    {
        $this->pastikanBolehKelola($sumber);

        return DB::transaction(function () use ($sumber): ReferenceTableVersion {
            $baru = ReferenceTableVersion::create([
                'company_id' => $sumber->company_id, 'reference_table_id' => $sumber->reference_table_id,
                'version_no' => (int) ReferenceTableVersion::where('reference_table_id', $sumber->reference_table_id)->max('version_no') + 1,
                'status' => 'draft', 'created_by' => Auth::id(),
            ]);
            foreach ($sumber->rows as $row) {
                ReferenceTableRow::create(collect($row->getAttributes())->except(['id', 'version_id'])->all() + ['version_id' => $baru->id]);
            }
            $this->audit->log('copied', $baru, new: ['dari_versi' => $sumber->version_no]);

            return $baru;
        });
    }

    private function pastikanBolehKelola(ReferenceTableVersion $versi): void
    {
        if ($versi->isNasional() && ! $this->konteks->isSuperAdmin()) {
            throw new HttpException(403, 'Tabel nasional hanya bisa diubah Super Admin.');
        }
    }

    private function pastikanBolehUbah(ReferenceTableVersion $versi): void
    {
        $this->pastikanBolehKelola($versi);
        if (! $versi->bisaDiubah()) {
            throw ValidationException::withMessages(['versi' => 'Versi ini sudah terbit atau terkunci. Salin ke versi baru untuk mengubah.']);
        }
    }
}
