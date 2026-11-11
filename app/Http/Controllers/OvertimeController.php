<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\Notifikasi;
use App\Models\Overtime;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OvertimeController extends Controller
{
    public function index(Request $request)
    {
        [$periode, $awal, $akhir] = $this->rentangFilter($request);
        $base = $request->user()->batasi(Overtime::with(['karyawan.departemen', 'penginput.karyawan']))
            ->whereBetween('tanggal', [$awal->toDateString(), $akhir->toDateString()])
            ->when($request->karyawan_id, fn ($q, $k) => $q->where('karyawan_id', $k));
        $totalJam = (clone $base)->sum('durasi');
        $lembur = $base->orderByDesc('tanggal')->orderBy('id')->paginate(10)->withQueryString(); // id: urutan tanggal kembar tetap
        $ubah = $request->ubah ? Overtime::find($request->ubah) : null;
        if ($ubah) {
            $this->pastikanLingkup($request, $ubah->karyawan_id);
        }
        $pilihan = $this->pilihanKaryawan($request)->filter->berhakLembur()->values();

        return view('lembur.index', [
            'lembur' => $lembur, 'periode' => $periode, 'awal' => $awal, 'akhir' => $akhir, 'totalJam' => $totalJam,
            'karyawan' => $pilihan, 'form' => $ubah ?? new Overtime(['tanggal' => now(), 'jam_mulai' => '17:00', 'jam_selesai' => '19:00']),
            'dept' => \App\Models\Departemen::dikelola($request->user()->karyawan_id)->first(),
        ]);
    }

    public function create(Request $request)
    {
        return view('lembur.form', ['overtime' => new Overtime(['tanggal' => now(), 'jam_mulai' => '17:00', 'jam_selesai' => '19:00']), 'karyawan' => $this->pilihanKaryawan($request)]);
    }

    public function store(Request $request)
    {
        $data = $this->validasi($request);
        $lembur = Overtime::create($data + ['diinput_oleh' => $request->user()->id]);

        // lembur dari Manager dikabarkan ke HR
        if ($request->user()->isManagerTim()) {
            Notifikasi::kirim(User::idsRole(Role::HR), 'Lembur baru dari Manager',
                $request->user()->namaTampil() . ' menginput lembur ' . $lembur->karyawan->nama . ' tanggal ' . tgl($lembur->tanggal) . " ({$lembur->durasi} jam).", 'lembur', $lembur->id);
        }

        return $this->kembali('Lembur ' . $lembur->karyawan->nama . ' tersimpan. Durasi ' . ($lembur->durasi + 0) . ' jam.');
    }

    public function edit(Request $request, Overtime $overtime)
    {
        $this->pastikanLingkup($request, $overtime->karyawan_id);

        return view('lembur.form', ['overtime' => $overtime, 'karyawan' => $this->pilihanKaryawan($request)]);
    }

    public function update(Request $request, Overtime $overtime)
    {
        $this->pastikanLingkup($request, $overtime->karyawan_id);
        $this->pastikanTidakTerkunci($overtime->tanggal);
        $overtime->update($this->validasi($request));

        return $this->kembali('Lembur diubah.');
    }

    public function destroy(Request $request, Overtime $overtime)
    {
        $this->pastikanLingkup($request, $overtime->karyawan_id);
        $this->pastikanTidakTerkunci($overtime->tanggal);
        $overtime->delete();

        return back()->with('sukses', 'Lembur dihapus.');
    }

    private function validasi(Request $request): array
    {
        $data = $request->validate([
            'karyawan_id' => ['required', \App\Domain\Company\Tenancy\TenantRule::exists('karyawan')],
            'tanggal' => 'required|date',
            'jam_mulai' => 'required|date_format:H:i',
            'jam_selesai' => 'required|date_format:H:i|different:jam_mulai',
            'keterangan' => 'nullable|max:255',
        ]);
        $this->pastikanLingkup($request, $data['karyawan_id']);
        $this->pastikanTidakTerkunci($data['tanggal']);

        if (! Karyawan::find($data['karyawan_id'])->berhakLembur()) {
            throw ValidationException::withMessages(['karyawan_id' => 'Karyawan ini tidak berhak lembur (tidak ada komponen lembur di kontrak aktifnya).']);
        }
        $data['durasi'] = Overtime::hitungDurasi($data['jam_mulai'], $data['jam_selesai']);

        return $data;
    }
}
