<?php

declare(strict_types=1);

namespace Tests\Feature\Karakterisasi;

use App\Models\Approval;
use App\Models\Attendance;
use App\Models\ImportExcel;
use App\Models\Karyawan;
use App\Models\Kontrak;
use App\Models\Notifikasi;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;

/**
 * Alur penting aplikasi lama yang harus tetap sama setelah multi-tenant:
 * payroll (generate -> ajukan -> setujui / tolak), penguncian absensi, approval kontrak, import.
 */
final class AlurLamaTest extends KarakterisasiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PayrollService::$tanpaPdf = true; // PDF slip dibuat saat diunduh (sama seperti seeder)
    }

    protected function tearDown(): void
    {
        PayrollService::$tanpaPdf = false;
        parent::tearDown();
    }

    public function test_generate_ajukan_lalu_manager_hr_setujui_menerbitkan_slip(): void
    {
        $hr = $this->user('hr');
        $managerHr = $this->user('manager.hr');

        $generate = $this->hasil($this->actingAs($hr)->post('/payroll/generate', ['periode' => '2026-11']));
        $payroll = Payroll::where('periode', '2026-11')->firstOrFail();
        $setelahGenerate = ['respon' => $generate, 'payroll' => $this->payroll($payroll), 'approval' => $this->approval($payroll)];

        $approval = Approval::where('jenis_ref', 'payroll')->where('ref_id', $payroll->id)->where('approver_id', $managerHr->id)->firstOrFail();
        $setujui = $this->hasil($this->actingAs($managerHr)->post("/approval/{$approval->id}/setujui", ['catatan' => 'OK']));

        $this->cocokSnapshotJson('alur/payroll-setujui.json', [
            'setelah_generate' => $setelahGenerate,
            'setujui' => $setujui,
            'payroll' => $this->payroll($payroll->fresh()),
            'approval' => $this->approval($payroll),
            'slip' => Payslip::where('payroll_id', $payroll->id)->with('karyawan:id,nik')->get()
                ->sortBy('karyawan.nik')->map(fn ($s) => [$s->karyawan->nik, $s->total_bruto, $s->total_potongan, $s->thp])->values(),
            'notifikasi_baru' => Notifikasi::where('is_read', false)->orderBy('id')->get(['user_id', 'judul', 'jenis_ref'])->toArray(),
        ]);
    }

    public function test_manager_hr_menolak_lalu_hr_generate_ulang(): void
    {
        $hr = $this->user('hr');
        $this->actingAs($hr)->post('/payroll/generate', ['periode' => '2026-11']);
        $payroll = Payroll::where('periode', '2026-11')->firstOrFail();

        $hr2 = $this->user('manager.hr2');
        $approval = Approval::where('jenis_ref', 'payroll')->where('ref_id', $payroll->id)->where('approver_id', $hr2->id)->firstOrFail();
        $tolakTanpaCatatan = $this->hasil($this->actingAs($hr2)->from('/approval')->post("/approval/{$approval->id}/tolak", ['catatan' => '']));
        $tolak = $this->hasil($this->actingAs($hr2)->post("/approval/{$approval->id}/tolak", ['catatan' => 'Lembur belum lengkap']));
        $setelahTolak = ['payroll' => $this->payroll($payroll->fresh()), 'approval' => $this->approval($payroll)];

        $ulang = $this->hasil($this->actingAs($hr)->post('/payroll/generate', ['periode' => '2026-11']));

        $this->cocokSnapshotJson('alur/payroll-tolak.json', [
            'tolak_tanpa_catatan' => $tolakTanpaCatatan,
            'tolak' => $tolak,
            'setelah_tolak' => $setelahTolak,
            'generate_ulang' => $ulang,
            'payroll' => $this->payroll($payroll->fresh()),
            'approval' => $this->approval($payroll),
        ]);
    }

    public function test_absensi_di_periode_final_dikunci(): void
    {
        $hr = $this->user('hr');
        $rizky = $this->user('rizky')->karyawan;
        $data = ['karyawan_id' => $rizky->id, 'jam_masuk' => '08:00', 'jam_keluar' => '17:00', 'status' => 'hadir'];

        $periodeFinal = $this->hasil($this->actingAs($hr)->from('/absensi')->post('/absensi', $data + ['tanggal' => '2026-10-04'])); // Minggu, periode Oktober (final)
        $periodeDraft = $this->hasil($this->actingAs($hr)->from('/absensi')->post('/absensi', $data + ['tanggal' => '2026-11-08'])); // Minggu, periode November (draft)

        $this->cocokSnapshotJson('alur/absensi-kunci.json', [
            'periode_final' => $periodeFinal,
            'periode_draft' => $periodeDraft,
            'tersimpan' => Attendance::where('karyawan_id', $rizky->id)
                ->where(fn ($q) => $q->whereDate('tanggal', '2026-10-04')->orWhereDate('tanggal', '2026-11-08'))
                ->orderBy('tanggal')->pluck('tanggal')->map(fn ($t) => substr((string) $t, 0, 10))->all(),
        ]);
    }

    public function test_manager_divisi_menyetujui_perpanjangan_kontrak(): void
    {
        $managerIt = $this->user('manager.it');
        $nadia = Karyawan::where('nama', 'Nadia Putri')->firstOrFail();
        $baru = Kontrak::where('karyawan_id', $nadia->id)->where('status_approval', 'menunggu')->firstOrFail();
        $approval = Approval::where('jenis_ref', 'kontrak')->where('ref_id', $baru->id)->firstOrFail();

        $setujui = $this->hasil($this->actingAs($managerIt)->post("/approval/{$approval->id}/setujui", ['catatan' => 'Lanjut']));

        $this->cocokSnapshotJson('alur/kontrak-setujui.json', [
            'respon' => $setujui,
            'kontrak' => Kontrak::where('karyawan_id', $nadia->id)->orderBy('id')
                ->get(['nomor', 'jenis', 'tgl_mulai', 'tgl_akhir', 'gaji_pokok', 'status_approval', 'is_active'])->toArray(),
            'notifikasi_baru' => Notifikasi::where('is_read', false)->orderBy('id')->get(['user_id', 'judul', 'jenis_ref'])->toArray(),
        ]);
    }

    public function test_import_absensi_csv_upload_preview_simpan(): void
    {
        $hr = $this->user('hr');
        $nik = $this->user('rizky')->karyawan->nik;
        $csv = "nik,tanggal,jam_masuk,jam_keluar,status\n{$nik},2026-11-08,08:00,17:00,hadir\nTIDAKADA,2026-11-08,08:00,17:00,hadir\n";
        $valid = "nik,tanggal,jam_masuk,jam_keluar,status\n{$nik},2026-11-08,08:00,17:00,hadir\n";

        $error = $this->hasil($this->actingAs($hr)->from('/import')->post('/import/upload', ['jenis' => 'absensi', 'file' => UploadedFile::fake()->createWithContent('absensi.csv', $csv)]));
        $upload = $this->hasil($this->actingAs($hr)->from('/import')->post('/import/upload', ['jenis' => 'absensi', 'file' => UploadedFile::fake()->createWithContent('absensi.csv', $valid)]));
        $preview = $this->actingAs($hr)->get('/import/preview');
        $simpan = $this->hasil($this->actingAs($hr)->post('/import/simpan'));

        $this->cocokSnapshot('alur/import-preview.html', $this->normalisasi((string) $preview->getContent()));
        $this->cocokSnapshotJson('alur/import-absensi.json', [
            'upload_dengan_error' => $error,
            'upload' => $upload,
            'simpan' => $simpan,
            'tersimpan' => Attendance::whereHas('karyawan', fn ($q) => $q->where('nik', $nik))->where('tanggal', '2026-11-08')->get(['jam_masuk', 'jam_keluar', 'status'])->toArray(),
            'log_import' => ImportExcel::orderBy('id')->get(['jenis', 'nama_file', 'status', 'baris', 'baris_error', 'catatan'])->toArray(),
        ]);
    }

    // ---- helper ----

    private function user(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function hasil(TestResponse $r): array
    {
        return [
            'status' => $r->getStatusCode(),
            'ke' => $r->headers->get('Location'),
            'sukses' => session('sukses'),
            'gagal' => session('gagal'),
            'errors' => $this->errors(session('errors')),
        ];
    }

    /** session('errors') bisa berupa ViewErrorBag atau array (tergantung siklus flash). */
    private function errors(mixed $errors): array
    {
        if ($errors instanceof ViewErrorBag) {
            return $errors->getBag('default')->toArray();
        }

        return is_array($errors) ? $errors : [];
    }

    /** @return array<string, mixed> */
    private function payroll(Payroll $p): array
    {
        $detail = $p->details()->with(['karyawan:id,nik', 'component:id,kode,nama'])->get()
            ->sortBy(fn ($d) => [$d->karyawan->nik, $d->payslip_component_id])
            ->map(fn ($d) => [$d->karyawan->nik, $d->component->kode ?? $d->component->nama, $d->jumlah, $d->nominal])->values();

        return ['periode' => $p->periode, 'status' => $p->status, 'tgl_generate' => (string) $p->tgl_generate, 'detail' => $detail];
    }

    /** @return list<array<string, mixed>> */
    private function approval(Payroll $p): array
    {
        return Approval::where('jenis_ref', 'payroll')->where('ref_id', $p->id)->orderBy('id')
            ->with('approver:id,username')->get()
            ->map(fn ($a) => ['approver' => $a->approver?->username, 'level' => $a->level, 'status' => $a->status, 'catatan' => $a->catatan])
            ->all();
    }
}
