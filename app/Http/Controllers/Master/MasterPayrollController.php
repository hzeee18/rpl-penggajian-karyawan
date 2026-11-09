<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use App\Models\MasterPayroll;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Menu Periode: daftar periode + tanggal mulai/selesai + hari kerja (layar Master Payroll di Figma). */
class MasterPayrollController extends Controller
{
    public function index(Request $request)
    {
        $periode = MasterPayroll::with(['pembuat.karyawan', 'payroll'])->whereNotNull('periode')
            ->when($request->q, fn ($q, $s) => $q->where('periode', 'like', "%$s%"))
            ->orderByDesc('periode')->get();
        if ($request->has('baru')) {
            $berikut = $periode->first() ? \Carbon\Carbon::parse($periode->first()->periode . '-01')->addMonthNoOverflow()->format('Y-m') : now()->format('Y-m');
            $dasar = MasterPayroll::aktif();
            [$m, $s] = $dasar->hitungRentang($berikut);
            $ubah = new MasterPayroll(['periode' => $berikut, 'tgl_mulai' => $m, 'tgl_selesai' => $s, 'hari_kerja' => Leave::hariKerja($m, $s),
                'tgl_cutoff' => $dasar->tgl_cutoff, 'pembagi_lembur' => $dasar->pembagi_lembur, 'pengali_jam1' => $dasar->pengali_jam1, 'pengali_jam_berikut' => $dasar->pengali_jam_berikut]);
        } else {
            $ubah = $periode->firstWhere('id', $request->integer('ubah')) ?? $periode->firstWhere('periode', now()->format('Y-m')) ?? $periode->first();
        }

        // tanggal yang sudah dipakai periode lain -> dinonaktifkan di kalender rentang
        $terpakai = $periode->filter(fn ($p) => $p->tgl_mulai && $p->tgl_selesai && $p->id !== $ubah?->id)
            ->map(fn ($p) => ['from' => $p->tgl_mulai->toDateString(), 'to' => $p->tgl_selesai->toDateString()])->values()->all();

        return view('master.master-payroll', compact('periode', 'ubah', 'terpakai'));
    }

    public function store(Request $request)
    {
        $data = $this->validasi($request);
        $m = MasterPayroll::create($data + ['dibuat_oleh' => $request->user()->id]);

        return redirect()->route('master-payroll.index', ['ubah' => $m->id])->with('sukses', 'Periode ' . nama_periode($m->periode) . ' dibuat.');
    }

    public function update(Request $request, MasterPayroll $master)
    {
        if ($master->payroll && ! $master->payroll->bisaDigenerate()) {
            return back()->with('gagal', 'Payroll periode ini sudah diajukan/final, pengaturannya tidak bisa diubah.');
        }
        $master->update($this->validasi($request, $master));

        return redirect()->route('master-payroll.index', ['ubah' => $master->id])->with('sukses', 'Periode ' . nama_periode($master->periode) . ' disimpan.');
    }

    public function destroy(MasterPayroll $master)
    {
        if ($master->payroll) {
            return back()->with('gagal', 'Periode yang sudah punya payroll tidak bisa dihapus.');
        }
        $master->delete();

        return redirect()->route('master-payroll.index')->with('sukses', 'Periode dihapus.');
    }

    private function validasi(Request $request, ?MasterPayroll $m = null): array
    {
        // rentang periode tidak boleh bertabrakan dengan periode lain
        $request->validate(['tgl_mulai' => ['required', 'date', function ($attr, $v, $gagal) use ($request, $m) {
            $bentrok = MasterPayroll::whereNotNull('tgl_mulai')->when($m, fn ($q) => $q->whereKeyNot($m->id))
                ->where('tgl_mulai', '<=', $request->tgl_selesai)->where('tgl_selesai', '>=', $v)->first();
            if ($bentrok) {
                $gagal('Rentang tanggal bertabrakan dengan periode ' . nama_periode($bentrok->periode) . ' (' . tgl($bentrok->tgl_mulai, 'j M') . ' – ' . tgl($bentrok->tgl_selesai, 'j M Y') . ').');
            }
        }]]);

        return $request->validate([
            'periode' => ['required', 'date_format:Y-m', Rule::unique('master_payroll', 'periode')->ignore($m)],
        ], ['periode.unique' => 'Periode ini sudah dibuat. Pilih bulan lain atau ubah periode yang sudah ada.']) + $request->validate([
            'tgl_mulai' => 'required|date',
            'tgl_selesai' => 'required|date|after:tgl_mulai',
            'hari_kerja' => 'required|integer|min:1|max:31',
        ]) + ['tgl_cutoff' => (int) \Carbon\Carbon::parse($request->tgl_selesai)->day];
    }
}
