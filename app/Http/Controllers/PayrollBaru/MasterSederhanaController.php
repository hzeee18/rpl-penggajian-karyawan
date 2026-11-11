<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD data master payroll sederhana (satu tabel, tampilan tabel + drawer seperti halaman lama).
 * Data selalu dicari lewat model ber-scope tenant: id perusahaan lain = 404.
 * Data yang "hanya dinonaktifkan" tidak pernah dihapus fisik.
 */
abstract class MasterSederhanaController extends Controller
{
    /** Kode yang dipakai di rumus: huruf kecil, angka, garis bawah, diawali huruf. */
    protected const POLA_KODE = 'regex:/^[a-z][a-z0-9_]*$/';

    public function __construct(protected readonly AuditLogger $audit) {}

    /**
     * @return array{model: class-string<Model>, route: string, judul: string, sub: string, urut: string,
     *               kolom: array<string, string>, field: list<array{name: string, label: string, type: string, opsi?: array<string, string>, bantuan?: string}>,
     *               hapus: 'nonaktif'|'fisik'}
     */
    abstract protected function definisi(): array;

    /** @return array<string, mixed> */
    abstract protected function aturan(?Model $data): array;

    public function index(Request $request)
    {
        $d = $this->definisi();
        $daftar = $d['model']::query()
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where(array_key_first($d['kolom']), 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->orderBy($d['urut'])->get();
        $ubah = $request->has('baru') ? new $d['model'] : ($daftar->firstWhere('id', $request->integer('ubah')) ?? new $d['model']);

        return view('payroll-baru.master', ['d' => $d, 'daftar' => $daftar, 'ubah' => $ubah]);
    }

    public function store(Request $request)
    {
        $d = $this->definisi();
        $data = $this->bersihkan($request->validate($this->aturan(null)));
        $model = DB::transaction(function () use ($d, $data) {
            $model = $d['model']::create($data);
            $this->audit->log('created', $model, new: $data);

            return $model;
        });

        return redirect()->route($d['route'].'.index', ['ubah' => $model->id])->with('sukses', $d['judul'].' ditambahkan.');
    }

    public function update(Request $request, int $id)
    {
        $d = $this->definisi();
        $model = $d['model']::findOrFail($id);
        $data = $this->bersihkan($request->validate($this->aturan($model)));
        DB::transaction(function () use ($model, $data) {
            $model->fill($data)->save();
            $this->audit->logChanges('updated', $model);
        });

        return redirect()->route($d['route'].'.index', ['ubah' => $model->id])->with('sukses', $d['judul'].' disimpan.');
    }

    public function destroy(int $id)
    {
        $d = $this->definisi();
        $model = $d['model']::findOrFail($id);

        DB::transaction(function () use ($d, $model) {
            if ($d['hapus'] === 'nonaktif') {
                $model->forceFill(['is_active' => false])->save();
                $this->audit->log('deactivated', $model);
            } else {
                $this->audit->log('deleted', $model, old: $model->getAttributes());
                $model->delete();
            }
        });

        return redirect()->route($d['route'].'.index')->with('sukses', $d['hapus'] === 'nonaktif' ? $d['judul'].' dinonaktifkan.' : $d['judul'].' dihapus.');
    }

    /** Checkbox yang tidak dicentang tidak terkirim: isi false. */
    private function bersihkan(array $data): array
    {
        foreach ($this->definisi()['field'] as $f) {
            if ($f['type'] === 'checkbox') {
                $data[$f['name']] = (bool) ($data[$f['name']] ?? false);
            }
        }

        return $data;
    }
}
