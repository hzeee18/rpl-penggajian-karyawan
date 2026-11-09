<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\GolonganTer;
use App\Models\Pajak;
use App\Models\TarifTer;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/** Pajak & Tarif TER: golongan di kiri, lapisan tarif golongan terpilih di kanan (Figma). */
class PajakController extends Controller
{
    public function index(Request $request)
    {
        $golongan = GolonganTer::with('pajak')->withCount('tarif')->orderBy('kode')->get();
        $aktif = $golongan->firstWhere('id', $request->integer('golongan')) ?? $golongan->first();
        $semuaPtkp = Pajak::with('golongan')->orderBy('status_ptkp')->get();

        // versi tarif (tanggal berlaku) & lapisan
        $versi = $aktif ? TarifTer::where('golongan_ter_id', $aktif->id)->distinct()->orderByDesc('berlaku_mulai')->pluck('berlaku_mulai')->map(fn ($d) => \Carbon\Carbon::parse($d)->toDateString())->unique()->values() : collect();
        $pilihVersi = $request->versi ?? $versi->first();
        $lapisan = $aktif ? TarifTer::where('golongan_ter_id', $aktif->id)->where('berlaku_mulai', $pilihVersi)->orderBy('lapisan')->get() : collect();

        // cari nominal -> lompat ke halaman lapisan yang cocok
        $nominal = (float) preg_replace('/\D/', '', (string) $request->nominal);
        $cocok = $nominal ? $lapisan->first(fn ($t) => $nominal >= $t->batas_bawah && ($t->batas_atas === null || $nominal <= $t->batas_atas)) : null;
        $hal = $cocok ? (int) ceil(($lapisan->search($cocok) + 1) / 10) : $request->integer('page', 1);
        $tarif = new LengthAwarePaginator($lapisan->forPage($hal, 10)->values(), $lapisan->count(), 10, $hal, ['path' => $request->url(), 'query' => $request->except('page', 'nominal')]);

        // simulasi cepat
        $sim = null;
        $simBruto = $request->input('sim_bruto', '8000000');
        $simPtkp = $request->input('sim_ptkp', $semuaPtkp->firstWhere('status_ptkp', 'TK/1')?->id);
        if ($simBruto && $simPtkp) {
            $bruto = (float) preg_replace('/\D/', '', $simBruto);
            $p = $semuaPtkp->firstWhere('id', (int) $simPtkp);
            if ($p) {
                $t = TarifTer::where('golongan_ter_id', $p->golongan_ter_id)->where('berlaku_mulai', '<=', now()->toDateString())
                    ->where('batas_bawah', '<=', $bruto)->where(fn ($q) => $q->whereNull('batas_atas')->orWhere('batas_atas', '>=', $bruto))->orderByDesc('berlaku_mulai')->first();
                $sim = (object) ['gol' => $p->golongan->kode, 'lapisan' => $t?->lapisan, 'persen' => $t?->persen ?? 0, 'pph' => round($bruto * ($t?->persen ?? 0) / 100)];
            }
        }

        return view('master.pajak.index', compact('golongan', 'aktif', 'semuaPtkp', 'versi', 'pilihVersi', 'tarif', 'cocok', 'sim'));
    }

    public function simpanGolongan(Request $request)
    {
        $data = $request->validate(['kode' => 'required|max:5|unique:golongan_ter,kode', 'nama' => 'required|max:100']);
        $g = GolonganTer::create($data + ['is_active' => true]);

        return redirect()->route('pajak.index', ['golongan' => $g->id])->with('sukses', 'Golongan ' . $g->kode . ' ditambahkan. Tambahkan lapisan tarifnya lewat tombol Ubah.');
    }

    public function ubahGolongan(Request $request, GolonganTer $golongan)
    {
        $data = $request->validate([
            'kode' => ['required', 'max:5', Rule::unique('golongan_ter', 'kode')->ignore($golongan)],
            'nama' => 'required|max:100',
            'ptkp' => 'array', 'ptkp.*' => 'exists:pajak,id',
        ]);
        $golongan->update(['kode' => $data['kode'], 'nama' => $data['nama'], 'is_active' => $request->boolean('is_active')]);
        if (! empty($data['ptkp'])) {
            Pajak::whereIn('id', $data['ptkp'])->update(['golongan_ter_id' => $golongan->id]);
        }

        return redirect()->route('pajak.index', ['golongan' => $golongan->id])->with('sukses', 'Golongan ' . $golongan->kode . ' disimpan.');
    }

    public function hapusGolongan(GolonganTer $golongan)
    {
        if ($golongan->pajak()->exists()) {
            return back()->with('gagal', 'Golongan masih dipakai status PTKP. Pindahkan PTKP-nya dulu.');
        }
        $golongan->delete();

        return redirect()->route('pajak.index')->with('sukses', 'Golongan dihapus.');
    }

    public function simpanPtkp(Request $request)
    {
        Pajak::create($request->validate([
            'status_ptkp' => 'required|max:10|unique:pajak,status_ptkp', 'nilai_ptkp' => 'required|numeric|min:0', 'golongan_ter_id' => 'required|exists:golongan_ter,id',
        ]));

        return back()->with('sukses', 'Status PTKP ditambahkan.');
    }

    public function ubahPtkp(Request $request, Pajak $pajak)
    {
        $pajak->update($request->validate([
            'status_ptkp' => ['required', 'max:10', Rule::unique('pajak')->ignore($pajak)], 'nilai_ptkp' => 'required|numeric|min:0', 'golongan_ter_id' => 'required|exists:golongan_ter,id',
        ]));

        return back()->with('sukses', 'Status PTKP diubah.');
    }

    public function simpanTarif(Request $request)
    {
        $data = $request->validate([
            'golongan_ter_id' => 'required|exists:golongan_ter,id', 'lapisan' => 'required|integer|min:1',
            'batas_bawah' => 'required|numeric|min:0', 'batas_atas' => 'nullable|numeric|gte:batas_bawah',
            'persen' => 'required|numeric|min:0|max:100', 'berlaku_mulai' => 'required|date', 'dasar_hukum' => 'nullable|max:100',
        ]);
        TarifTer::create($data);

        return redirect()->route('pajak.index', ['golongan' => $data['golongan_ter_id']])->with('sukses', 'Lapisan tarif ditambahkan.');
    }

    public function ubahTarif(Request $request, TarifTer $tarif)
    {
        $tarif->update($request->validate(['batas_bawah' => 'required|numeric|min:0', 'batas_atas' => 'nullable|numeric', 'persen' => 'required|numeric|min:0|max:100']));

        return back()->with('sukses', 'Lapisan tarif diubah.');
    }

    public function hapusTarif(TarifTer $tarif)
    {
        $tarif->delete();

        return back()->with('sukses', 'Lapisan tarif dihapus.');
    }
}
