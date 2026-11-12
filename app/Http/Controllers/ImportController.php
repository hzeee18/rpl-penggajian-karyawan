<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Departemen;
use App\Models\Karyawan;
use App\Models\Kontrak;
use App\Models\Leave;
use App\Models\Notifikasi;
use App\Models\Overtime;
use App\Models\Pajak;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Import data dari Excel: unduh template -> isi -> upload -> validasi -> preview -> simpan.
 */
class ImportController extends Controller
{
    /** Kolom template per jenis data + contoh isi. */
    public const TEMPLATE = [
        'karyawan' => [
            'judul' => 'Data Karyawan',
            'kolom' => ['nik', 'nama', 'jenis_kelamin', 'kode_departemen', 'jabatan', 'tgl_masuk', 'status_ptkp', 'status_kepegawaian', 'email', 'telepon', 'alamat', 'nama_bank', 'no_rekening'],
            'contoh' => ['K0101', 'Budi Santoso', 'L', 'IT', 'Programmer', '2026-01-05', 'TK/0', 'kontrak', 'budi@mail.com', '0812345678', 'Jl. Merdeka 1', 'BCA', '1234567890'],
        ],
        'kontrak' => [
            'judul' => 'Kontrak',
            'kolom' => ['nik', 'jenis', 'tgl_mulai', 'tgl_akhir', 'gaji_pokok'],
            'contoh' => ['K0101', 'PKWT', '2026-01-05', '2027-01-04', '6000000'],
        ],
        'absensi' => [
            'judul' => 'Absensi',
            'kolom' => ['nik', 'tanggal', 'jam_masuk', 'jam_keluar', 'status'],
            'contoh' => ['K0101', '2026-11-02', '08:00', '17:00', 'hadir'],
        ],
        'cuti' => [
            'judul' => 'Cuti, Izin, Sakit',
            'kolom' => ['nik', 'jenis', 'tgl_mulai', 'tgl_selesai', 'keterangan'],
            'contoh' => ['K0101', 'cuti', '2026-11-10', '2026-11-11', 'Acara keluarga'],
        ],
        'lembur' => [
            'judul' => 'Lembur',
            'kolom' => ['nik', 'tanggal', 'jam_mulai', 'jam_selesai', 'keterangan'],
            'contoh' => ['K0101', '2026-11-03', '17:00', '20:00', 'Deploy aplikasi'],
        ],
    ];

    public function __construct(private ApprovalService $approval)
    {
    }

    public function index(Request $request)
    {
        $riwayat = \App\Models\ImportExcel::with('user.karyawan')
            ->when($request->q, fn ($q, $s) => $q->where('nama_file', 'like', "%$s%"))
            ->latest('id')->paginate(10)->withQueryString();
        $lihat = $request->lihat ? \App\Models\ImportExcel::find($request->lihat) : null;

        return view('import.index', ['template' => self::TEMPLATE, 'riwayat' => $riwayat, 'lihat' => $lihat]);
    }

    public function template(string $jenis)
    {
        abort_unless(isset(self::TEMPLATE[$jenis]), 404);
        $t = self::TEMPLATE[$jenis];

        $sheet = ($book = new Spreadsheet())->getActiveSheet();
        $sheet->setTitle($jenis);
        $sheet->fromArray([$t['kolom'], $t['contoh']]);
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        foreach (range(1, count($t['kolom'])) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        // semua kolom sebagai teks supaya NIK & tanggal tidak berubah format
        $sheet->getStyle('A:Z')->getNumberFormat()->setFormatCode('@');

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), "template-{$jenis}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function upload(Request $request)
    {
        $request->validate([
            'jenis' => 'required|in:' . implode(',', array_keys(self::TEMPLATE)),
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
            'periode' => 'nullable|date_format:Y-m',
        ]);
        $jenis = $request->jenis;
        if ($jenis === 'kontrak' && \App\Domain\Payroll\ModulPayroll::aktif()) {
            // payroll baru: kontrak dibuat di menu Kontrak Payroll (AUDIT.md §29)
            return back()->with('gagal', 'Kontrak sekarang dibuat di menu Kontrak Payroll.');
        }
        $rentang = $request->periode && in_array($jenis, ['absensi', 'cuti', 'lembur'])
            ? \App\Models\MasterPayroll::untuk($request->periode)->rentang($request->periode) : null;

        try {
            $rows = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        } catch (Throwable $e) {
            return back()->with('gagal', 'File tidak bisa dibaca. Pastikan pakai template .xlsx.');
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows) ?? []);
        $kolom = self::TEMPLATE[$jenis]['kolom'];
        if (array_slice($header, 0, count($kolom)) !== $kolom) {
            return back()->with('gagal', 'Header kolom tidak sesuai template ' . $jenis . '. Unduh ulang templatenya.');
        }

        $data = [];
        $errors = [];
        foreach ($rows as $i => $row) {
            if (count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue; // baris kosong
            }
            $item = array_combine($kolom, array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_slice(array_pad($row, count($kolom), null), 0, count($kolom))));
            [$item, $err] = $this->validasiBaris($jenis, $item, $data);
            $tglBaris = $item['tanggal'] ?? $item['tgl_mulai'] ?? null;
            if ($rentang && $tglBaris && ($tglBaris < $rentang[0]->toDateString() || $tglBaris > $rentang[1]->toDateString())) {
                $err[] = 'tanggal ' . $tglBaris . ' di luar periode ' . nama_periode($request->periode);
            }
            if ($err) {
                $errors[] = 'Baris ' . ($i + 2) . ': ' . implode('; ', $err);
            }
            $data[] = $item;
        }

        if (! $data) {
            return back()->with('gagal', 'File tidak berisi data.');
        }
        $namaFile = $request->file('file')->getClientOriginalName();
        if ($errors) {
            $log = \App\Models\ImportExcel::create([
                'user_id' => $request->user()->id, 'jenis' => $jenis, 'nama_file' => $namaFile, 'status' => 'error',
                'baris' => count($data), 'baris_error' => count($errors), 'catatan' => implode("\n", $errors),
            ]);

            return redirect()->route('import.index', ['lihat' => $log->id])->with('gagal', 'Import gagal: ' . count($errors) . ' baris error. Perbaiki file lalu upload ulang.');
        }

        session(['import' => ['jenis' => $jenis, 'data' => $data, 'file' => $namaFile]]);

        return redirect()->route('import.preview');
    }

    public function preview()
    {
        $import = session('import');
        if (! $import) {
            return redirect()->route('import.index');
        }

        return view('import.preview', ['import' => $import, 'kolom' => self::TEMPLATE[$import['jenis']]['kolom'], 'judul' => self::TEMPLATE[$import['jenis']]['judul']]);
    }

    public function simpan(Request $request)
    {
        $import = session()->pull('import');
        if (! $import) {
            return redirect()->route('import.index')->with('gagal', 'Data preview sudah kedaluwarsa, upload ulang.');
        }

        try {
            DB::transaction(function () use ($import, $request) {
                foreach ($import['data'] as $row) {
                    $this->simpanBaris($import['jenis'], $row, $request->user());
                }
            });
        } catch (Throwable $e) {
            return redirect()->route('import.index')->with('gagal', 'Gagal menyimpan: ' . $e->getMessage());
        }

        \App\Models\ImportExcel::create([
            'user_id' => $request->user()->id, 'jenis' => $import['jenis'], 'nama_file' => $import['file'],
            'status' => 'berhasil', 'baris' => count($import['data']),
        ]);

        if ($import['jenis'] === 'karyawan') {
            Notifikasi::kirim(User::idsRole(Role::ADMIN), 'Karyawan baru (import)',
                count($import['data']) . ' karyawan baru di-import. Silakan buatkan akun login.', 'user', null);
        }

        return redirect()->route('import.index')->with('sukses', count($import['data']) . ' baris data ' . self::TEMPLATE[$import['jenis']]['judul'] . ' berhasil di-import.');
    }

    // ---------------------------------------------------------------

    private function tanggal($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return is_numeric($v) ? Carbon::instance(ExcelDate::excelToDateTimeObject($v))->toDateString() : Carbon::parse($v)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function jam($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject($v))->format('H:i');
        }

        return preg_match('/^([01]?\d|2[0-3])[:.]([0-5]\d)/', $v, $m) ? sprintf('%02d:%s', $m[1], $m[2]) : null;
    }

    private function validasiBaris(string $jenis, array $r, array $sebelumnya): array
    {
        $err = [];
        $r['nik'] = (string) $r['nik'];
        $karyawan = Karyawan::where('nik', $r['nik'])->first();

        if ($r['nik'] === '') {
            $err[] = 'NIK wajib diisi';
        } elseif ($jenis === 'karyawan') {
            if ($karyawan || collect($sebelumnya)->contains('nik', $r['nik'])) {
                $err[] = "NIK {$r['nik']} sudah terdaftar";
            }
        } elseif (! $karyawan) {
            $err[] = "NIK {$r['nik']} tidak ditemukan";
        }
        $r['karyawan_id'] = $karyawan?->id;

        switch ($jenis) {
            case 'karyawan':
                if (empty($r['nama'])) {
                    $err[] = 'nama wajib diisi';
                }
                $dept = Departemen::where('kode', $r['kode_departemen'])->first();
                $pajak = Pajak::where('status_ptkp', $r['status_ptkp'])->first();
                if (! $dept) {
                    $err[] = "kode departemen '{$r['kode_departemen']}' tidak ada";
                }
                if (! $pajak) {
                    $err[] = "status PTKP '{$r['status_ptkp']}' tidak ada";
                }
                if (! ($r['tgl_masuk'] = $this->tanggal($r['tgl_masuk']))) {
                    $err[] = 'tgl_masuk tidak valid (format YYYY-MM-DD)';
                }
                if ($r['jenis_kelamin'] && ! in_array(strtoupper($r['jenis_kelamin']), ['L', 'P'])) {
                    $err[] = 'jenis_kelamin harus L atau P';
                }
                if (! in_array($r['status_kepegawaian'] ?: 'kontrak', ['tetap', 'kontrak', 'harian'])) {
                    $err[] = 'status_kepegawaian harus tetap/kontrak/harian';
                }
                $r['departemen_id'] = $dept?->id;
                $r['pajak_id'] = $pajak?->id;
                break;

            case 'kontrak':
                if (! in_array($r['jenis'], ['PKWT', 'PKWTT'])) {
                    $err[] = 'jenis harus PKWT atau PKWTT';
                }
                if (! ($r['tgl_mulai'] = $this->tanggal($r['tgl_mulai']))) {
                    $err[] = 'tgl_mulai tidak valid';
                }
                $r['tgl_akhir'] = $this->tanggal($r['tgl_akhir']);
                if ($r['jenis'] === 'PKWT' && ! $r['tgl_akhir']) {
                    $err[] = 'tgl_akhir wajib untuk PKWT';
                }
                if (! is_numeric($r['gaji_pokok']) || $r['gaji_pokok'] < 0) {
                    $err[] = 'gaji_pokok harus angka';
                }
                break;

            case 'absensi':
                if (! ($r['tanggal'] = $this->tanggal($r['tanggal']))) {
                    $err[] = 'tanggal tidak valid';
                }
                $r['status'] = strtolower((string) $r['status']);
                if (! array_key_exists($r['status'], Attendance::STATUS)) {
                    $err[] = 'status harus hadir/terlambat/alfa';
                }
                $r['jam_masuk'] = $this->jam($r['jam_masuk']);
                $r['jam_keluar'] = $this->jam($r['jam_keluar']);
                if ($karyawan && $r['tanggal'] && Attendance::where('karyawan_id', $karyawan->id)->where('tanggal', $r['tanggal'])->exists()) {
                    $err[] = 'absensi tanggal ini sudah ada';
                }
                break;

            case 'cuti':
                $r['jenis'] = strtolower((string) $r['jenis']);
                if (! array_key_exists($r['jenis'], Leave::JENIS)) {
                    $err[] = 'jenis harus cuti/izin/sakit';
                }
                $r['tgl_mulai'] = $this->tanggal($r['tgl_mulai']);
                $r['tgl_selesai'] = $this->tanggal($r['tgl_selesai']);
                if (! $r['tgl_mulai'] || ! $r['tgl_selesai'] || $r['tgl_selesai'] < $r['tgl_mulai']) {
                    $err[] = 'tanggal mulai/selesai tidak valid';
                } elseif ($karyawan && $r['jenis'] === 'cuti') {
                    $dipakai = collect($sebelumnya)->where('karyawan_id', $karyawan->id)->where('jenis', 'cuti')
                        ->sum(fn ($x) => Leave::hariKerja($x['tgl_mulai'], $x['tgl_selesai']));
                    if (Leave::hariKerja($r['tgl_mulai'], $r['tgl_selesai']) + $dipakai > $karyawan->sisa_cuti) {
                        $err[] = "sisa cuti tidak cukup (sisa {$karyawan->sisa_cuti} hari)";
                    }
                }
                break;

            case 'lembur':
                if (! ($r['tanggal'] = $this->tanggal($r['tanggal']))) {
                    $err[] = 'tanggal tidak valid';
                }
                $r['jam_mulai'] = $this->jam($r['jam_mulai']);
                $r['jam_selesai'] = $this->jam($r['jam_selesai']);
                if (! $r['jam_mulai'] || ! $r['jam_selesai']) {
                    $err[] = 'jam mulai/selesai tidak valid (HH:MM)';
                }
                if ($karyawan && ! $karyawan->berhakLembur()) {
                    $err[] = 'karyawan tidak berhak lembur';
                }
                break;
        }

        return [$r, $err];
    }

    private function simpanBaris(string $jenis, array $r, User $user): void
    {
        switch ($jenis) {
            case 'karyawan':
                Karyawan::create([
                    'nik' => $r['nik'], 'nama' => $r['nama'], 'jenis_kelamin' => strtoupper((string) $r['jenis_kelamin']) ?: null,
                    'departemen_id' => $r['departemen_id'], 'jabatan' => $r['jabatan'], 'tgl_masuk' => $r['tgl_masuk'],
                    'pajak_id' => $r['pajak_id'], 'status_kepegawaian' => $r['status_kepegawaian'] ?: 'kontrak',
                    'email' => $r['email'], 'telepon' => $r['telepon'], 'alamat' => $r['alamat'],
                    'nama_bank' => $r['nama_bank'], 'no_rekening' => (string) $r['no_rekening'],
                ]);
                break;
            case 'kontrak':
                $kontrak = Kontrak::create([
                    'karyawan_id' => $r['karyawan_id'], 'jenis' => $r['jenis'], 'tgl_mulai' => $r['tgl_mulai'],
                    'tgl_akhir' => $r['jenis'] === 'PKWTT' ? null : $r['tgl_akhir'], 'gaji_pokok' => $r['gaji_pokok'],
                    'status_approval' => 'draft', 'is_active' => false,
                ]);
                $this->approval->ajukanKontrak($kontrak);
                break;
            case 'absensi':
                Attendance::create(collect($r)->only(['karyawan_id', 'tanggal', 'jam_masuk', 'jam_keluar', 'status'])->all());
                break;
            case 'cuti':
                $leave = Leave::create(collect($r)->only(['karyawan_id', 'jenis', 'tgl_mulai', 'tgl_selesai', 'keterangan'])->all() + ['sumber_input' => 'import']);
                $leave->kurangiSisaCuti();
                break;
            case 'lembur':
                Overtime::create(collect($r)->only(['karyawan_id', 'tanggal', 'jam_mulai', 'jam_selesai', 'keterangan'])->all() + [
                    'durasi' => Overtime::hitungDurasi($r['jam_mulai'], $r['jam_selesai']),
                    'diinput_oleh' => $user->id,
                ]);
                break;
        }
    }
}
