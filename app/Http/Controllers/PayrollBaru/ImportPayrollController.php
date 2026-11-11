<?php

declare(strict_types=1);

namespace App\Http\Controllers\PayrollBaru;

use App\Domain\Payroll\Actions\SimpanImport;
use App\Domain\Payroll\Actions\UnggahImport;
use App\Domain\Payroll\Actions\ValidasiUlangImport;
use App\Domain\Payroll\Import\JenisKehadiran;
use App\Domain\Payroll\Models\ImportBatch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Import CSV absensi/leave/lembur: unggah -> preview (baris error merah) -> perbaiki/hapus -> simpan. */
final class ImportPayrollController extends Controller
{
    public function index()
    {
        return view('payroll-baru.import.index', [
            'batch' => ImportBatch::orderByDesc('id')->limit(30)->get(),
            'jenis' => JenisKehadiran::cases(),
        ]);
    }

    public function template(string $jenis)
    {
        $j = JenisKehadiran::dariSlug($jenis);
        $isi = implode(',', $j->kolom())."\n".implode(',', $j->contoh())."\n";

        return response()->streamDownload(fn () => print ($isi), "template-{$j->slug()}.csv", ['Content-Type' => 'text/csv']);
    }

    public function upload(Request $request, UnggahImport $unggah)
    {
        $data = $request->validate([
            'jenis' => ['required', Rule::in(array_map(fn ($j) => $j->slug(), JenisKehadiran::cases()))],
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);
        $batch = $unggah->handle(JenisKehadiran::dariSlug($data['jenis']), $request->file('file'));

        return redirect()->route('pay.import.show', $batch->id)
            ->with($batch->valid_rows === $batch->total_rows ? 'sukses' : 'gagal', "{$batch->valid_rows} dari {$batch->total_rows} baris valid.");
    }

    public function show(Request $request, int $id)
    {
        $batch = ImportBatch::with('rows')->findOrFail($id);

        return view('payroll-baru.import.show', [
            'batch' => $batch,
            'jenis' => JenisKehadiran::from($batch->type),
            'ubah' => $request->integer('ubah') ? $batch->rows->firstWhere('id', $request->integer('ubah')) : null,
        ]);
    }

    public function ubahBaris(Request $request, int $id, int $baris, ValidasiUlangImport $validasi)
    {
        $batch = $this->batchPreview($id);
        $row = $batch->rows()->findOrFail($baris);
        $kolom = JenisKehadiran::from($batch->type)->kolom();
        $row->forceFill(['payload' => array_map(fn ($v) => (string) $v, $request->only($kolom) + array_fill_keys($kolom, ''))])->save();
        $validasi->handle($batch);

        return redirect()->route('pay.import.show', $batch->id)->with('sukses', "Baris {$row->row_no} diperbarui.");
    }

    public function hapusBaris(int $id, int $baris, ValidasiUlangImport $validasi)
    {
        $batch = $this->batchPreview($id);
        $batch->rows()->findOrFail($baris)->delete();
        $validasi->handle($batch);

        return redirect()->route('pay.import.show', $batch->id)->with('sukses', 'Baris dihapus.');
    }

    public function simpan(int $id, SimpanImport $simpan)
    {
        $jumlah = $simpan->handle($this->batchPreview($id));

        return redirect()->route('pay.import.index')->with('sukses', "Import disimpan: {$jumlah} data.");
    }

    public function batal(int $id)
    {
        $this->batchPreview($id)->forceFill(['status' => 'cancelled'])->save();

        return redirect()->route('pay.import.index')->with('sukses', 'Import dibatalkan.');
    }

    private function batchPreview(int $id): ImportBatch
    {
        $batch = ImportBatch::findOrFail($id);
        abort_unless($batch->status === 'previewing', 422, 'Batch ini sudah disimpan atau dibatalkan.');

        return $batch;
    }
}
