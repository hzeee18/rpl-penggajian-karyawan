<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\Leave;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    public function index(Request $request)
    {
        [$periode, $awal, $akhir] = $this->rentangFilter($request);
        $cuti = $request->user()->batasi(Leave::with('karyawan.departemen'))
            ->where('tgl_mulai', '<=', $akhir->toDateString())->where('tgl_selesai', '>=', $awal->toDateString())
            ->when($request->karyawan_id, fn ($q, $k) => $q->where('karyawan_id', $k))
            ->when($request->jenis, fn ($q, $j) => $q->where('jenis', $j))
            ->orderByDesc('tgl_mulai')->paginate(10)->withQueryString();
        $ubah = $request->ubah ? Leave::find($request->ubah) : null;

        return view('cuti.index', [
            'cuti' => $cuti, 'periode' => $periode, 'awal' => $awal, 'akhir' => $akhir,
            'karyawan' => $this->pilihanKaryawan($request),
            'form' => $ubah ?? new Leave(['jenis' => 'cuti', 'tgl_mulai' => now(), 'tgl_selesai' => now()]),
        ]);
    }

    public function create(Request $request)
    {
        return view('cuti.form', ['leave' => new Leave(['jenis' => 'cuti', 'tgl_mulai' => now(), 'tgl_selesai' => now()]), 'karyawan' => $this->pilihanKaryawan($request)]);
    }

    public function store(Request $request)
    {
        $data = $this->validasi($request);
        DB::transaction(function () use ($data) {
            $leave = Leave::create($data + ['sumber_input' => 'manual']);
            $leave->kurangiSisaCuti();
        });

        return $this->kembali('Data cuti/izin/sakit disimpan.');
    }

    public function edit(Request $request, Leave $leave)
    {
        return view('cuti.form', ['leave' => $leave, 'karyawan' => $this->pilihanKaryawan($request)]);
    }

    public function update(Request $request, Leave $leave)
    {
        $this->pastikanTidakTerkunci($leave->tgl_mulai, $leave->tgl_selesai);
        DB::transaction(function () use ($request, $leave) {
            $leave->kembalikanSisaCuti();
            $data = $this->validasi($request);
            $leave->update($data);
            $leave->refresh()->kurangiSisaCuti();
        });

        return $this->kembali('Data cuti/izin/sakit diubah.');
    }

    public function destroy(Leave $leave)
    {
        $this->pastikanTidakTerkunci($leave->tgl_mulai, $leave->tgl_selesai);
        DB::transaction(function () use ($leave) {
            $leave->kembalikanSisaCuti();
            $leave->delete();
        });

        return back()->with('sukses', 'Data dihapus, sisa cuti dikembalikan.');
    }

    private function validasi(Request $request): array
    {
        $data = $request->validate([
            'karyawan_id' => 'required|exists:karyawan,id',
            'jenis' => 'required|in:' . implode(',', array_keys(Leave::JENIS)),
            'tgl_mulai' => 'required|date',
            'tgl_selesai' => 'required|date|after_or_equal:tgl_mulai',
            'keterangan' => 'nullable|max:255',
        ]);
        $this->pastikanLingkup($request, $data['karyawan_id']);
        $this->pastikanTidakTerkunci($data['tgl_mulai'], $data['tgl_selesai']);

        if ($data['jenis'] === 'cuti') {
            $hari = Leave::hariKerja($data['tgl_mulai'], $data['tgl_selesai']);
            $sisa = Karyawan::find($data['karyawan_id'])->fresh()->sisa_cuti;
            if ($hari > $sisa) {
                throw ValidationException::withMessages(['tgl_selesai' => "Sisa cuti tidak cukup. Butuh {$hari} hari, sisa {$sisa} hari."]);
            }
        }

        return $data;
    }
}
