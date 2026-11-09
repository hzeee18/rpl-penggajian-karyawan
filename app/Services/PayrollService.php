<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Karyawan;
use App\Models\Kontrak;
use App\Models\Leave;
use App\Models\MasterPayroll;
use App\Models\Notifikasi;
use App\Models\Overtime;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Models\Payslip;
use App\Models\PayslipComponent;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PayrollService
{
    /** Lewati pembuatan PDF saat finalisasi (dipakai seeder; PDF dibuat saat slip diunduh). */
    public static bool $tanpaPdf = false;

    /**
     * Karyawan aktif yang punya kontrak disetujui & berlaku di periode.
     * Relasi kontrakAktif diisi dengan kontrak yang berlaku di akhir periode.
     */
    public function karyawanPeriode(Carbon $awal, Carbon $akhir)
    {
        Kontrak::sinkronAktif();
        $berlaku = fn ($q) => $q->where('status_approval', 'disetujui')
            ->where('tgl_mulai', '<=', $akhir->toDateString())
            ->where(fn ($q) => $q->whereNull('tgl_akhir')->orWhere('tgl_akhir', '>=', $awal->toDateString()));

        return Karyawan::with(['departemen', 'pajak.golongan', 'kontrak' => fn ($q) => $berlaku($q)->with('komponen.component')])
            ->where('is_active', true)
            ->whereHas('kontrak', $berlaku)
            ->orderBy('nik')
            ->get()
            ->each(fn ($k) => $k->setRelation('kontrakAktif', $k->kontrak->sortByDesc('tgl_mulai')->first()));
    }

    /** Rekap absensi, cuti, dan lembur satu karyawan dalam rentang periode. */
    public function rekapKaryawan(int $karyawanId, Carbon $awal, Carbon $akhir): array
    {
        $absen = Attendance::where('karyawan_id', $karyawanId)
            ->whereBetween('tanggal', [$awal->toDateString(), $akhir->toDateString()])
            ->get()->countBy('status');

        $cuti = ['cuti' => 0, 'izin' => 0, 'sakit' => 0];
        $leaves = Leave::where('karyawan_id', $karyawanId)
            ->where('tgl_mulai', '<=', $akhir->toDateString())
            ->where('tgl_selesai', '>=', $awal->toDateString())->get();
        foreach ($leaves as $l) {
            $mulai = $l->tgl_mulai->max($awal);
            $selesai = $l->tgl_selesai->min($akhir);
            $cuti[$l->jenis] += Leave::hariKerja($mulai, $selesai);
        }

        $lembur = Overtime::where('karyawan_id', $karyawanId)
            ->whereBetween('tanggal', [$awal->toDateString(), $akhir->toDateString()])->get();

        return [
            'hadir' => ($absen['hadir'] ?? 0) + ($absen['terlambat'] ?? 0),
            'terlambat' => $absen['terlambat'] ?? 0,
            'alfa' => $absen['alfa'] ?? 0,
            'cuti' => $cuti['cuti'],
            'izin' => $cuti['izin'],
            'sakit' => $cuti['sakit'],
            'jam_lembur' => (float) $lembur->sum('durasi'),
            'lembur' => $lembur,
        ];
    }

    /** Rekap semua karyawan untuk dicek HR sebelum generate. */
    public function rekap(string $periode): array
    {
        $master = MasterPayroll::untuk($periode);
        [$awal, $akhir] = $master->rentang($periode);

        $baris = $this->karyawanPeriode($awal, $akhir)->map(fn ($k) => [
            'karyawan' => $k,
            'rekap' => $this->rekapKaryawan($k->id, $awal, $akhir),
        ]);

        return compact('master', 'awal', 'akhir', 'baris');
    }

    /** Nilai variabel bawaan rumus untuk satu karyawan di satu periode (lihat App\Support\Rumus::VARIABEL). */
    public function variabel(Karyawan $k, array $rekap, MasterPayroll $master, float $gp): array
    {
        $lembur = $rekap['lembur'] ?? collect();

        return [
            'GP' => $gp, 'HK' => max((int) $master->hari_kerja, 1),
            'HADIR' => $rekap['hadir'] ?? 0, 'TERLAMBAT' => $rekap['terlambat'] ?? 0, 'SAKIT' => $rekap['sakit'] ?? 0,
            'IZIN' => $rekap['izin'] ?? 0, 'CUTI' => $rekap['cuti'] ?? 0, 'ALFA' => $rekap['alfa'] ?? 0,
            'JAM_LEMBUR' => (float) ($rekap['jam_lembur'] ?? 0),
            'JAM_LEMBUR_1' => (float) $lembur->sum(fn ($o) => min((float) $o->durasi, 1)),
            'JAM_LEMBUR_LANJUT' => (float) $lembur->sum(fn ($o) => max((float) $o->durasi - 1, 0)),
            'HARI_LEMBUR' => $lembur->count(), 'MASA_KERJA' => $k->hitungMasaKerja(), 'NILAI' => 0,
        ];
    }

    /** Variabel yang dipakai sebagai "jumlah" di slip (mis. 18 hari, 7 jam). */
    private const SATUAN = ['HADIR', 'SAKIT', 'IZIN', 'CUTI', 'ALFA', 'TERLAMBAT', 'JAM_LEMBUR', 'JAM_LEMBUR_1', 'JAM_LEMBUR_LANJUT', 'HARI_LEMBUR'];

    /**
     * Hitung komponen gaji satu karyawan. Return [payslip_component_id => [jumlah, nominal, c]].
     * Komponen dengan rumus dihitung berurutan (yang dirujuk lebih dulu); gaji pokok dari kontrak; PPh21 dari TER.
     */
    public function hitungKaryawan(Karyawan $k, array $rekap, MasterPayroll $master, Carbon $akhir): array
    {
        $kontrak = $k->kontrakAktif;
        $gp = (float) $kontrak->gaji_pokok;
        $semua = PayslipComponent::where('status_berlaku', 'aktif')->get();
        $sistem = $semua->whereIn('dasar_bayar', PayslipComponent::DASAR_OTOMATIS)->keyBy('dasar_bayar');
        $terpasang = $kontrak->komponen->where('is_active', true)->keyBy('payslip_component_id');
        $vars = $this->variabel($k, $rekap, $master, $gp) + $semua->pluck('kode')->filter()->mapWithKeys(fn ($kd) => [strtoupper($kd) => 0])->all();
        $vars['GP'] = $gp;
        $hasil = [];

        if ($c = $sistem->get('gaji_pokok')) {
            $hasil[$c->id] = ['jumlah' => 1, 'nominal' => round($gp), 'c' => $c];
        }

        // komponen yang berlaku: otomatis (alfa) + yang dipasang di kontrak
        $berlaku = $semua->filter(fn ($c) => ! in_array($c->dasar_bayar, ['gaji_pokok', 'pajak'])
            && ($c->isOtomatis() || $terpasang->has($c->id)));
        $pakaiRumus = $berlaku->filter(fn ($c) => filled($c->rumus))->keyBy(fn ($c) => strtoupper($c->kode));
        $urut = \App\Support\Rumus::urutan($pakaiRumus->map(fn ($c) => \App\Support\Rumus::referensi($c->rumus))->all());

        foreach ($urut as $kode) {
            $c = $pakaiRumus[$kode];
            $kk = $terpasang->get($c->id);
            $nominal = max(0, round(\App\Support\Rumus::hitung($c->rumus, ['NILAI' => (float) ($kk?->nilai ?? $c->nilai_default)] + $vars)));
            $vars[$kode] = $nominal;
            $satuan = collect(\App\Support\Rumus::referensi($c->rumus))->map(fn ($r) => str_starts_with($r, 'JAM_LEMBUR') ? 'JAM_LEMBUR' : $r)->first(fn ($r) => in_array($r, self::SATUAN));
            if ($nominal > 0) {
                $hasil[$c->id] = ['jumlah' => $satuan ? $vars[$satuan] : 1, 'nominal' => $nominal, 'c' => $c];
            }
        }

        // komponen lama tanpa rumus (data sebelum fitur rumus): nominal tetap / persen GP
        foreach ($berlaku->filter(fn ($c) => blank($c->rumus)) as $c) {
            $kk = $terpasang->get($c->id);
            $nilai = ($kk?->tipe_nilai ?? $c->tipe_nilai) === 'persen' ? $gp * ($kk?->nilai ?? $c->nilai_default) / 100 : ($kk?->nilai ?? $c->nilai_default);
            if ($nilai > 0) {
                $hasil[$c->id] = ['jumlah' => 1, 'nominal' => round($nilai), 'c' => $c];
            }
        }

        if (($c = $sistem->get('pajak')) && $k->pajak) {
            $brutoPajak = collect($hasil)->filter(fn ($h) => $h['c']->jenis === 'penambah' && $h['c']->kena_pajak)->sum('nominal');
            $pph = $k->pajak->hitungPPh21($brutoPajak, $akhir->toDateString());
            if ($pph > 0) {
                $hasil[$c->id] = ['jumlah' => 1, 'nominal' => $pph, 'c' => $c];
            }
        }

        return $hasil;
    }

    /** Generate (atau generate ulang) payroll draft satu periode. */
    public function generate(string $periode): Payroll
    {
        $master = MasterPayroll::untuk($periode);
        [$awal, $akhir] = $master->rentang($periode);

        return DB::transaction(function () use ($periode, $master, $awal, $akhir) {
            $payroll = Payroll::firstOrNew(['periode' => $periode]);
            if ($payroll->exists && ! $payroll->bisaDigenerate()) {
                throw new RuntimeException('Payroll periode ini sudah diajukan atau final, tidak bisa di-generate ulang.');
            }

            $karyawan = $this->karyawanPeriode($awal, $akhir);
            if ($karyawan->isEmpty()) {
                throw new RuntimeException('Tidak ada karyawan dengan kontrak aktif pada periode ini.');
            }

            $payroll->fill([
                'master_payroll_id' => $master->id,
                'tgl_generate' => now()->toDateString(),
                'status' => 'draft',
            ])->save();
            $payroll->details()->delete();

            foreach ($karyawan as $k) {
                $rekap = $this->rekapKaryawan($k->id, $awal, $akhir);
                foreach ($this->hitungKaryawan($k, $rekap, $master, $akhir) as $componentId => $h) {
                    PayrollDetail::create([
                        'payroll_id' => $payroll->id,
                        'karyawan_id' => $k->id,
                        'payslip_component_id' => $componentId,
                        'jumlah' => $h['jumlah'],
                        'nominal' => $h['nominal'],
                    ]);
                }
            }

            return $payroll;
        });
    }

    /** Ringkasan per karyawan: gaji pokok, penambah, potongan, pph, thp. */
    public function ringkasan(Payroll $payroll, ?int $departemenId = null)
    {
        $details = $payroll->details()->with(['component', 'karyawan.departemen'])
            ->when($departemenId, fn ($q) => $q->whereHas('karyawan', fn ($q) => $q->where('departemen_id', $departemenId)))
            ->get()->groupBy('karyawan_id');

        return $details->map(function ($rows) {
            $bruto = $rows->filter(fn ($r) => $r->component->jenis === 'penambah')->sum('nominal');
            $potongan = $rows->filter(fn ($r) => $r->component->jenis === 'pengurang')->sum('nominal');

            return (object) [
                'karyawan' => $rows->first()->karyawan,
                'gaji_pokok' => $rows->firstWhere('component.dasar_bayar', 'gaji_pokok')?->nominal ?? 0,
                'bruto' => $bruto,
                'pph21' => $rows->firstWhere('component.dasar_bayar', 'pajak')?->nominal ?? 0,
                'potongan' => $potongan,
                'thp' => $bruto - $potongan,
                'rows' => $rows,
            ];
        })->sortBy('karyawan.nik')->values();
    }

    /** Dipanggil saat Manager HR menyetujui: payroll final, buat slip PDF, kirim notifikasi. */
    public function finalisasi(Payroll $payroll): void
    {
        $payroll->update(['status' => 'final']);

        foreach ($this->ringkasan($payroll) as $r) {
            $slip = Payslip::updateOrCreate(
                ['payroll_id' => $payroll->id, 'karyawan_id' => $r->karyawan->id],
                ['total_bruto' => $r->bruto, 'total_potongan' => $r->potongan, 'thp' => $r->thp],
            );
            if (! self::$tanpaPdf) {
                $this->generatePdf($slip);
            }

            if ($userId = $r->karyawan->user?->id) {
                Notifikasi::kirim($userId, 'Slip gaji tersedia',
                    'Slip gaji periode ' . $payroll->namaPeriode() . ' sudah bisa dilihat dan diunduh.', 'payslip', $slip->id);
            }
        }
    }

    /** Buat file PDF slip gaji dan simpan path-nya. */
    public function generatePdf(Payslip $slip): string
    {
        $slip->loadMissing('karyawan.departemen', 'payroll');
        $path = 'slip/' . $slip->payroll->periode . '/' . $slip->karyawan->nik . '.pdf';
        $pdf = Pdf::loadView('slip.pdf', ['slip' => $slip, 'details' => $slip->details()])->setPaper('a4');
        Storage::disk('local')->put($path, $pdf->output());
        $slip->update(['file_pdf' => $path]);

        return $path;
    }

    /** Tanggal yang masuk periode payroll yang sudah diajukan / final tidak boleh diubah. */
    public function tanggalTerkunci($tanggal): bool
    {
        $tanggal = Carbon::parse($tanggal);
        $master = MasterPayroll::aktif();
        foreach (Payroll::whereIn('status', ['menunggu', 'final'])->pluck('periode') as $periode) {
            [$awal, $akhir] = $master->rentang($periode);
            if ($tanggal->between($awal, $akhir)) {
                return true;
            }
        }

        return false;
    }
}
