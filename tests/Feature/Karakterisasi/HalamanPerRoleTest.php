<?php

declare(strict_types=1);

namespace Tests\Feature\Karakterisasi;

use App\Models\Approval;
use App\Models\Attendance;
use App\Models\Departemen;
use App\Models\Leave;
use App\Models\Overtime;
use App\Models\Payroll;
use App\Models\PayslipComponent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Setiap role membuka SEMUA halaman GET (termasuk detail dan export).
 * Direkam: status, redirect, content-type, dan isi HTML/JSON/Excel (dinormalisasi).
 * 403 untuk role yang tidak berhak juga bagian dari perilaku yang direkam.
 */
final class HalamanPerRoleTest extends KarakterisasiTestCase
{
    /** @return array<string, array{string}> */
    public static function pengguna(): array
    {
        return [
            'admin' => ['admin'],
            'hr' => ['hr'],
            'manager-hr' => ['manager.hr'],
            'manager-it' => ['manager.it'],
            'karyawan' => ['rizky'],
        ];
    }

    #[DataProvider('pengguna')]
    public function test_semua_halaman_sama_seperti_sebelum_multi_tenant(string $username): void
    {
        $this->rekamHalaman($username, $this->daftarUrl(), "halaman/{$username}.json");
    }

    /** Export/template Excel butuh ekstensi PHP zip (PhpSpreadsheet). */
    #[DataProvider('pengguna')]
    #[RequiresPhpExtension('zip')]
    public function test_export_excel_sama_seperti_sebelum_multi_tenant(string $username): void
    {
        $periode = Payroll::where('status', 'final')->orderByDesc('periode')->value('periode');

        $this->rekamHalaman($username, ['/import/template/absensi', "/laporan/excel?periode={$periode}"], "excel/{$username}.json");
    }

    /** @param  list<string>  $urls */
    private function rekamHalaman(string $username, array $urls, string $snapshot): void
    {
        $user = User::where('username', $username)->firstOrFail();
        $ringkasan = [];

        foreach ($urls as $url) {
            $response = $this->actingAs($user)->get($url);
            $status = $response->getStatusCode();
            $tipe = (string) Str::before((string) $response->headers->get('Content-Type'), ';');
            $baris = ['status' => $status, 'tipe' => $tipe];

            if ($response->isRedirect()) {
                $baris['ke'] = $response->headers->get('Location');
            } elseif ($status === 200) {
                $baris += $this->rekamIsi($username, $url, $tipe, $response);
            }

            $ringkasan[$url] = $baris;
        }

        $this->cocokSnapshotJson($snapshot, $ringkasan);
    }

    /** @return array<string, mixed> */
    private function rekamIsi(string $username, string $url, string $tipe, $response): array
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($url)), '-') ?: 'root';

        if ($tipe === 'text/html') {
            $this->cocokSnapshot("halaman/{$username}/{$slug}.html", $this->normalisasi((string) $response->getContent()));

            return [];
        }

        if ($tipe === 'application/json') {
            $this->cocokSnapshotJson("halaman/{$username}/{$slug}.json", (array) $response->json());

            return [];
        }

        if (str_contains($tipe, 'spreadsheetml')) {
            $file = tempnam(sys_get_temp_dir(), 'kar').'.xlsx';
            file_put_contents($file, $response->streamedContent());
            $sel = IOFactory::load($file)->getActiveSheet()->toArray(null, true, false, false);
            @unlink($file);
            $this->cocokSnapshotJson("halaman/{$username}/{$slug}.json", $sel);

            return [];
        }

        // PDF: isi biner memuat waktu pembuatan, cukup direkam bahwa file terbentuk
        $isi = method_exists($response->baseResponse, 'getFile')
            ? (string) file_get_contents($response->baseResponse->getFile()->getPathname())
            : (string) ($response->getContent() ?: $response->streamedContent());

        return ['pdf' => str_starts_with($isi, '%PDF')];
    }

    /** @return list<string> */
    private function daftarUrl(): array
    {
        $rizky = User::where('username', 'rizky')->firstOrFail()->karyawan;
        $hr = User::where('username', 'hr')->firstOrFail();
        $kontrak = $rizky->kontrak()->where('is_active', true)->firstOrFail();
        $final = Payroll::where('status', 'final')->orderByDesc('periode')->firstOrFail();
        $slip = $rizky->payslips()->where('payroll_id', $final->id)->firstOrFail();
        $approval = Approval::orderBy('id')->firstOrFail();
        $periodeFinal = $final->periode;

        return [
            '/dashboard',
            '/notifikasi',
            '/profil',
            '/cari?q=a',
            // admin
            '/users', '/users/create', "/users/{$hr->id}/edit",
            '/roles', '/roles/'.Role::where('nama', Role::HR)->value('id').'/hak-akses',
            '/menus',
            // master
            '/departemen', '/departemen/create', '/departemen/'.Departemen::orderBy('id')->value('id').'/edit',
            '/komponen', '/komponen/create', '/komponen/'.PayslipComponent::orderBy('id')->value('id').'/edit',
            '/pajak',
            '/master-payroll',
            // karyawan & kontrak
            '/karyawan', '/karyawan/create', "/karyawan/{$rizky->id}", "/karyawan/{$rizky->id}/edit",
            '/kontrak', '/kontrak/create', "/kontrak/{$kontrak->id}", "/kontrak/{$kontrak->id}/edit", "/kontrak-pdf/{$kontrak->id}",
            // kehadiran
            '/absensi', "/absensi?periode={$periodeFinal}", '/absensi/create',
            '/absensi/'.Attendance::where('karyawan_id', $rizky->id)->orderBy('id')->value('id').'/edit',
            '/cuti', '/cuti/create', '/cuti/'.Leave::orderBy('id')->value('id').'/edit',
            '/lembur', '/lembur/create', '/lembur/'.Overtime::orderBy('id')->value('id').'/edit',
            '/import',
            // payroll lama
            '/payroll', '/payroll/rekap', '/payroll/riwayat',
            "/payroll/{$final->id}", "/payroll/{$final->id}/karyawan/{$rizky->id}",
            '/laporan', "/laporan?periode={$periodeFinal}", "/laporan/{$final->id}",
            "/laporan/pdf?periode={$periodeFinal}",
            '/approval', "/approval/{$approval->id}",
            '/slip', "/slip?periode={$periodeFinal}", "/slip/{$slip->id}", "/slip/{$slip->id}/unduh",
        ];
    }
}
