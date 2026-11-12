<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payroll\Engine\Parser;
use App\Domain\Payroll\Engine\RumusException;
use App\Domain\Payroll\Engine\ValidasiMaster;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Item;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\MasterVersion;
use App\Domain\Payroll\Models\Param;
use App\Domain\Payroll\Models\Variable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Use case master payroll berversi: buat master, ubah isi versi draft (variabel/parameter/item),
 * terbitkan (setelah validasi), salin ke versi baru. Versi terbit / terkunci tidak bisa diubah.
 * Semua perubahan tercatat di audit log.
 */
final class KelolaMasterPayroll
{
    public function __construct(private readonly ValidasiMaster $validasi, private readonly AuditLogger $audit) {}

    /** @param array{code: string, name: string, description?: ?string} $data */
    public function buatMaster(array $data): Master
    {
        return DB::transaction(function () use ($data): Master {
            $master = Master::create($data);
            $master->versions()->create(['version_no' => 1, 'status' => 'draft', 'created_by' => Auth::id()]);
            $this->audit->log('created', $master, new: $data);

            return $master;
        });
    }

    /** @param array<string, mixed> $data */
    public function simpanVariabel(MasterVersion $versi, array $data, ?Variable $ubah = null): Variable
    {
        $this->pastikanDraft($versi);
        $data = $data['kind'] === 'base'
            ? array_merge($data, ['formula' => null])
            : array_merge($data, ['source' => null, 'aggregate' => null, 'filter_expression' => null, 'value_expression' => null]);
        foreach (['formula', 'filter_expression', 'value_expression'] as $kolom) {
            $this->cekSintaks($data[$kolom] ?? null, $kolom);
        }

        return $this->simpan($versi, Variable::class, $data, $ubah);
    }

    /** @param array<string, mixed> $data */
    public function simpanParam(MasterVersion $versi, array $data, ?Param $ubah = null): Param
    {
        $this->pastikanDraft($versi);

        return $this->simpan($versi, Param::class, $data, $ubah);
    }

    /** @param array<string, mixed> $data item: component_id, code, calc_type, formula, item_role, sort_order */
    public function simpanItem(MasterVersion $versi, array $data, ?Item $ubah = null): Item
    {
        $this->pastikanDraft($versi);
        $komponen = Component::findOrFail($data['component_id']);
        // nama/jenis/kena pajak DISALIN dari katalog saat item dibuat (versi lama tidak berubah bila katalog diubah)
        $data += ['name' => $komponen->name, 'type' => $komponen->type, 'is_taxable' => $komponen->is_taxable];
        if ($data['calc_type'] === 'contract') {
            $data['formula'] = null;
        }
        if ($data['calc_type'] === Item::CARA_PPH21_TER) {
            // pilihan siap pakai: rumus TER nasional, selalu dihitung terakhir sebagai pph21
            $data = ['calc_type' => 'formula', 'formula' => Item::RUMUS_PPH21_TER, 'item_role' => 'pph21'] + $data;
        }
        $this->cekSintaks($data['formula'] ?? null, 'formula');

        return $this->simpan($versi, Item::class, $data, $ubah);
    }

    public function hapus(MasterVersion $versi, Model $isi): void
    {
        $this->pastikanDraft($versi);
        DB::transaction(function () use ($isi) {
            $this->audit->log('deleted', $isi, old: $isi->getAttributes());
            $isi->delete();
        });
    }

    public function terbitkan(MasterVersion $versi): void
    {
        $this->pastikanDraft($versi);
        $error = $this->validasi->periksa($versi);
        if ($versi->items()->count() === 0) {
            $error[] = 'Versi belum punya komponen';
        }
        if ($error !== []) {
            throw ValidationException::withMessages(['versi' => $error]);
        }

        DB::transaction(function () use ($versi) {
            $versi->forceFill(['status' => 'published', 'published_at' => now()])->save();
            $versi->master->forceFill(['current_version_id' => $versi->id])->save();
            $this->audit->log('published', $versi, new: ['version_no' => $versi->version_no]);
        });
    }

    /** Salin versi (variabel, parameter, item) ke versi draft baru. */
    public function salin(MasterVersion $sumber, ?string $catatan = null): MasterVersion
    {
        return DB::transaction(function () use ($sumber, $catatan): MasterVersion {
            $sumber->load(['variables', 'params', 'items']);
            $baru = $sumber->master->versions()->create([
                'version_no' => (int) $sumber->master->versions()->max('version_no') + 1,
                'status' => 'draft', 'note' => $catatan ?? "Salinan versi {$sumber->version_no}", 'created_by' => Auth::id(),
            ]);
            foreach (['variables', 'params', 'items'] as $rel) {
                foreach ($sumber->{$rel} as $row) {
                    $baru->{$rel}()->create(collect($row->getAttributes())->except(['id', 'company_id', 'master_version_id', 'created_at', 'updated_at'])->all());
                }
            }
            $this->audit->log('copied', $baru, new: ['dari_versi' => $sumber->version_no]);

            return $baru;
        });
    }

    private function pastikanDraft(MasterVersion $versi): void
    {
        if (! $versi->bisaDiubah()) {
            throw ValidationException::withMessages(['versi' => 'Versi ini sudah terbit atau terkunci. Salin ke versi baru untuk mengubah.']);
        }
    }

    private function cekSintaks(?string $rumus, string $kolom): void
    {
        if ($rumus === null || trim($rumus) === '') {
            return;
        }
        try {
            Parser::parse($rumus);
        } catch (RumusException $e) {
            throw ValidationException::withMessages([$kolom => $e->getMessage()]);
        }
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $kelas
     * @param  T|null  $ubah
     * @return T
     */
    private function simpan(MasterVersion $versi, string $kelas, array $data, ?Model $ubah): Model
    {
        return DB::transaction(function () use ($versi, $kelas, $data, $ubah): Model {
            if ($ubah !== null) {
                $lama = $ubah->getAttributes();
                $ubah->fill($data)->save();
                $this->audit->log('updated', $ubah, old: $lama, new: $ubah->getAttributes());

                return $ubah;
            }
            $baru = $kelas::create($data + ['master_version_id' => $versi->id]);
            $this->audit->log('created', $baru, new: $data);

            return $baru;
        });
    }
}
