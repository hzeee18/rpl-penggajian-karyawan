<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\PayslipComponent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KomponenController extends Controller
{
    public function index(Request $request)
    {
        $komponen = PayslipComponent::where('dasar_bayar', '!=', 'pajak') // PPh21 diatur di menu Pajak & Tarif TER
            ->when($request->q, fn ($q, $s) => $q->where(fn ($q) => $q->where('nama', 'like', "%$s%")->orWhere('kode', 'like', "%$s%")))
            ->orderBy('id')->get();
        $ubah = $request->has('baru') ? new PayslipComponent(['jenis' => 'penambah', 'tipe_nilai' => 'nominal', 'dasar_bayar' => 'rumus', 'rumus' => 'GP * 10%', 'status_berlaku' => 'aktif', 'kena_pajak' => true])
            : ($komponen->firstWhere('id', $request->integer('ubah')) ?? $komponen->firstWhere('kode', 'UM') ?? $komponen->first());

        $contoh = \App\Models\Karyawan::whereHas('kontrakAktif')->orderBy('nama')->get(['id', 'nama', 'nik']);

        return view('master.komponen.index', compact('komponen', 'ubah', 'contoh'));
    }

    public function create()
    {
        return view('master.komponen.form', ['komponen' => new PayslipComponent(['status_berlaku' => 'aktif', 'tipe_nilai' => 'nominal'])]);
    }

    public function store(Request $request)
    {
        $k = PayslipComponent::create($this->validasi($request));

        return redirect()->route('komponen.index', ['ubah' => $k->id])->with('sukses', 'Komponen gaji ditambahkan.');
    }

    public function edit(PayslipComponent $komponen)
    {
        return view('master.komponen.form', compact('komponen'));
    }

    public function update(Request $request, PayslipComponent $komponen)
    {
        $komponen->update($this->validasi($request, $komponen));

        return redirect()->route('komponen.index', ['ubah' => $komponen->id])->with('sukses', 'Komponen ' . $komponen->nama . ' disimpan.');
    }

    public function destroy(PayslipComponent $komponen)
    {
        if ($komponen->isSistem() || $komponen->dasar_bayar === 'alfa') {
            return back()->with('gagal', 'Komponen sistem tidak bisa dihapus, cukup dinonaktifkan.');
        }
        if (\App\Models\PayrollDetail::where('payslip_component_id', $komponen->id)->exists()
            || \App\Models\KontrakKomponen::where('payslip_component_id', $komponen->id)->exists()) {
            return back()->with('gagal', 'Komponen sudah dipakai di kontrak atau payroll. Nonaktifkan saja.');
        }
        $komponen->delete();

        return back()->with('sukses', 'Komponen dihapus.');
    }

    /** Pratinjau hasil rumus untuk satu karyawan contoh di periode berjalan (dipanggil saat mengetik rumus). */
    public function pratinjau(Request $request, \App\Services\PayrollService $svc)
    {
        $rumus = (string) $request->input('rumus');
        $kode = strtoupper((string) $request->input('kode'));
        $pesan = $this->cekRumus($rumus, $kode, $request->integer('id') ?: null);
        if ($pesan) {
            return response()->json(['ok' => false, 'pesan' => $pesan]);
        }
        $k = \App\Models\Karyawan::with('kontrakAktif.komponen')->whereHas('kontrakAktif')->find($request->integer('karyawan_id'))
            ?? \App\Models\Karyawan::with('kontrakAktif.komponen')->whereHas('kontrakAktif')->orderBy('nama')->first();
        [$keadaan, $master] = \App\Http\Controllers\PayrollController::periodeTarget();
        if ($keadaan !== 'siap') { // pakai periode terakhir yang sudah berjalan supaya ada data kehadiran
            $master = \App\Models\MasterPayroll::whereNotNull('tgl_mulai')->where('tgl_mulai', '<=', now()->toDateString())->orderByDesc('tgl_mulai')->first() ?? $master;
        }
        [$awal, $akhir] = $master->rentang($master->periode);
        $rekap = $svc->rekapKaryawan($k->id, $awal, $akhir);
        $vars = $svc->variabel($k, $rekap, $master, (float) $k->kontrakAktif->gaji_pokok);
        foreach ($svc->hitungKaryawan($k, $rekap, $master, $akhir) as $h) {
            $vars[strtoupper($h['c']->kode)] = $h['nominal'];
        }
        $vars += PayslipComponent::pluck('kode')->mapWithKeys(fn ($kd) => [strtoupper($kd) => 0])->all();
        $vars['NILAI'] = (float) $request->input('nilai_default', 0);
        $dipakai = collect(\App\Support\Rumus::referensi($rumus))->mapWithKeys(fn ($r) => [$r => $vars[$r] ?? 0]);

        return response()->json([
            'ok' => true, 'nilai' => rupiah(max(0, round(\App\Support\Rumus::hitung($rumus, $vars)))),
            'karyawan' => $k->nama . ' · ' . nama_periode($master->periode),
            'rincian' => $dipakai->map(fn ($v, $n) => $n . ' = ' . (in_array($n, ['GP']) || $v >= 1000 ? rupiah($v) : ($v + 0)))->values(),
        ]);
    }

    /** Nama yang boleh dipakai di rumus: variabel bawaan + kode komponen lain. */
    private function namaDikenal(?string $kodeSendiri): array
    {
        return array_values(array_unique(array_merge(array_keys(\App\Support\Rumus::VARIABEL),
            PayslipComponent::whereNotNull('kode')->pluck('kode')->map(fn ($k) => strtoupper($k))->reject(fn ($k) => $k === $kodeSendiri)->all())));
    }

    /** Cek rumus: sintaks, nama dikenal, dan tidak saling merujuk melingkar. Return pesan error atau null. */
    private function cekRumus(string $rumus, string $kode, ?int $id): ?string
    {
        if ($p = \App\Support\Rumus::cek($rumus, $this->namaDikenal($kode))) {
            return $p;
        }
        $graf = PayslipComponent::whereNotNull('rumus')->when($id, fn ($q) => $q->whereKeyNot($id))->get()
            ->mapWithKeys(fn ($c) => [strtoupper($c->kode) => \App\Support\Rumus::referensi($c->rumus)])->all();
        $graf[$kode] = \App\Support\Rumus::referensi($rumus);
        try {
            \App\Support\Rumus::urutan($graf);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function validasi(Request $request, ?PayslipComponent $k = null): array
    {
        $request->merge(['kode' => strtoupper(trim((string) $request->kode))]);
        $sistem = $k?->isSistem();
        $data = $request->validate([
            'kode' => ['required', 'regex:/^[A-Z][A-Z0-9_]{0,9}$/', \App\Domain\Company\Tenancy\TenantRule::unique('payslip_component', 'kode')->ignore($k),
                function ($a, $v, $gagal) use ($k) { if ($v !== 'GP' && array_key_exists($v, \App\Support\Rumus::VARIABEL)) $gagal('Kode ' . $v . ' sudah dipakai sebagai variabel bawaan.'); }],
            'nama' => 'required|max:100',
            'jenis' => 'required|in:penambah,pengurang',
            'berlaku' => 'nullable|in:kontrak,semua',
            'rumus' => $sistem ? 'nullable' : 'required|max:255',
            'nilai_default' => 'nullable|numeric|min:0',
            'status_berlaku' => 'required|in:aktif,nonaktif',
        ], ['kode.regex' => 'Kode pakai huruf besar/angka/garis bawah, diawali huruf, maks 10 karakter (mis. UM, TUNJ_JABATAN).']);
        if ($sistem && $data['kode'] !== $k->kode) {
            throw \Illuminate\Validation\ValidationException::withMessages(['kode' => 'Kode komponen sistem tidak bisa diubah.']);
        }
        if (! $sistem && ($pesan = $this->cekRumus($data['rumus'], $data['kode'], $k?->id))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['rumus' => $pesan]);
        }

        $hasil = [
            'kode' => $data['kode'], 'nama' => $data['nama'], 'jenis' => $data['jenis'], 'status_berlaku' => $data['status_berlaku'],
            'kena_pajak' => $request->boolean('kena_pajak'), 'nilai_default' => (float) ($data['nilai_default'] ?? 0), 'tipe_nilai' => 'nominal',
        ];
        if (! $sistem) {
            $hasil['rumus'] = trim($data['rumus']);
            $lama = $k?->dasar_bayar;
            $hasil['dasar_bayar'] = $lama === 'alfa' ? 'alfa'
                : (($data['berlaku'] ?? 'kontrak') === 'semua' ? 'semua' : (in_array($lama, ['harian', 'lembur', 'thr', 'bulanan', 'rumus']) ? $lama : 'rumus'));
        }

        return $hasil;
    }
}
