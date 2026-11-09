<?php

namespace App\Http\Controllers;

use App\Models\Departemen;
use App\Models\Payroll;
use App\Services\PayrollService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class LaporanController extends Controller
{
    public function __construct(private PayrollService $svc)
    {
    }

    private function data(Request $request): array
    {
        // laporan hanya untuk payroll yang sudah final (disetujui Manager HR)
        $daftar = Payroll::where('status', 'final')->orderByDesc('periode')->get();
        $payroll = $daftar->firstWhere('periode', $request->periode) ?? ($request->periode ? null : $daftar->first());
        $departemenId = $request->integer('departemen_id') ?: null;
        $ringkasan = $payroll ? $this->svc->ringkasan($payroll, $departemenId) : collect();
        $departemen = Departemen::orderBy('nama')->get();

        return compact('daftar', 'payroll', 'ringkasan', 'departemen', 'departemenId');
    }

    /** Riwayat Payroll: semua periode yang pernah di-generate (draft, menunggu, ditolak, final). */
    public function index(Request $request)
    {
        $tahunAda = Payroll::orderByDesc('periode')->pluck('periode')->map(fn ($p) => substr($p, 0, 4))->unique()->values();
        $tahun = $request->input('tahun');
        $status = collect((array) $request->input('status'))->intersect(['draft', 'menunggu', 'ditolak', 'final'])->values()->all();
        $daftar = Payroll::with(['approvals.approver.karyawan', 'master'])
            ->when($tahun, fn ($q) => $q->where('periode', 'like', $tahun . '-%'))
            ->when($status, fn ($q) => $q->whereIn('status', $status))
            ->orderByDesc('periode')->paginate(12)->withQueryString();
        $ringkas = [];
        foreach ($daftar as $p) {
            $r = $this->svc->ringkasan($p);
            $setuju = $p->approvals->firstWhere('status', 'disetujui');
            $ringkas[$p->id] = ['karyawan' => $r->count(), 'thp' => $r->sum('thp'), 'bruto' => $r->sum('bruto'),
                'oleh' => $setuju?->approver?->namaTampil(), 'tgl' => $setuju?->tgl_approval];
        }
        $final = Payroll::where('status', 'final')->orderByDesc('periode')->first();

        return view('laporan.index', compact('daftar', 'ringkas', 'tahunAda', 'tahun', 'status', 'final'));
    }

    /** Detail satu periode: ringkasan komponen, rekap per departemen, daftar karyawan, approval, export. */
    public function show(Request $request, Payroll $payroll)
    {
        $payroll->load('approvals.approver.karyawan', 'master');
        $departemenId = $request->integer('departemen_id') ?: null;
        $semua = $this->svc->ringkasan($payroll);
        $ringkasan = $departemenId ? $semua->filter(fn ($r) => $r->karyawan->departemen_id === $departemenId)->values() : $semua;
        $perDept = $semua->groupBy(fn ($r) => $r->karyawan->departemen_id)->map(fn ($rows) => (object) [
            'dept' => $rows->first()->karyawan->departemen, 'thp' => $rows->sum('thp'), 'jumlah' => $rows->count(),
        ])->sortBy('dept.kode')->values();
        $komp = fn ($dasar) => $semua->sum(fn ($r) => $r->rows->filter(fn ($d) => in_array($d->component->dasar_bayar, (array) $dasar))->sum('nominal'));
        $total = [
            'Gaji pokok' => $komp('gaji_pokok'), 'Makan & transport' => $komp(['harian', 'hadir']), 'Lembur' => $komp('lembur'),
            'THR & tunjangan lain' => $semua->sum(fn ($r) => $r->rows->filter(fn ($d) => in_array($d->component->dasar_bayar, ['thr', 'bulanan', 'rumus', 'semua']) && $d->component->jenis === 'penambah')->sum('nominal')),
            'Potongan alfa' => -$komp('alfa'),
            'Potongan lain' => -$semua->sum(fn ($r) => $r->rows->filter(fn ($d) => in_array($d->component->dasar_bayar, ['rumus', 'semua']) && $d->component->jenis === 'pengurang')->sum('nominal')),
            'PPh21 (TER)' => -$komp('pajak'),
        ];
        $departemen = Departemen::with('managers')->orderBy('nama')->get();

        return view('laporan.show', compact('payroll', 'ringkasan', 'perDept', 'total', 'departemen', 'departemenId', 'semua'));
    }

    public function excel(Request $request)
    {
        $d = $this->data($request);
        abort_unless($d['payroll'], 404);

        $sheet = ($book = new Spreadsheet())->getActiveSheet();
        $sheet->setTitle($d['payroll']->periode);
        $sheet->fromArray([['Laporan Penggajian ' . $d['payroll']->namaPeriode()], ['Status: ' . $d['payroll']->status], []]);
        $sheet->fromArray(['No', 'NIK', 'Nama', 'Departemen', 'Jabatan', 'Gaji Pokok', 'Bruto', 'PPh 21', 'Total Potongan', 'Take Home Pay'], null, 'A4');
        $baris = 5;
        foreach ($d['ringkasan'] as $i => $r) {
            $sheet->fromArray([$i + 1, $r->karyawan->nik, $r->karyawan->nama, $r->karyawan->departemen?->nama, $r->karyawan->jabatan,
                $r->gaji_pokok, $r->bruto, $r->pph21, $r->potongan, $r->thp], null, 'A' . $baris++, true);
        }
        $sheet->fromArray(['', '', 'TOTAL', '', '', $d['ringkasan']->sum('gaji_pokok'), $d['ringkasan']->sum('bruto'),
            $d['ringkasan']->sum('pph21'), $d['ringkasan']->sum('potongan'), $d['ringkasan']->sum('thp')], null, 'A' . $baris, true);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A4:J4')->getFont()->setBold(true);
        $sheet->getStyle("A{$baris}:J{$baris}")->getFont()->setBold(true);
        $sheet->getStyle("F5:J{$baris}")->getNumberFormat()->setFormatCode('#,##0');
        foreach (range('A', 'J') as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'laporan-payroll-' . $d['payroll']->periode . '.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function pdf(Request $request)
    {
        $d = $this->data($request);
        abort_unless($d['payroll'], 404);

        return Pdf::loadView('laporan.pdf', $d)->setPaper('a4', 'landscape')
            ->download('laporan-payroll-' . $d['payroll']->periode . '.pdf');
    }
}
