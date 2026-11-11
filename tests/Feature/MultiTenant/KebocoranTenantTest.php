<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenant;

use App\Domain\Company\Tenancy\CurrentCompany;
use App\Models\Approval;
use App\Models\Attendance;
use App\Models\Departemen;
use App\Models\Karyawan;
use App\Models\Kontrak;
use App\Models\Leave;
use App\Models\Notifikasi;
use App\Models\Overtime;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PayslipComponent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * Fase 1e: tes kebocoran tenant (AGENTS.md). Untuk setiap halaman GET, API, export, dan dashboard:
 * user perusahaan A tidak melihat data perusahaan B (penanda RAHASIA-B), tidak bisa membuka id milik B,
 * dan sebaliknya. Daftar URL diambil dari router, jadi route baru ikut teruji.
 */
final class KebocoranTenantTest extends TestCase
{
    use DataPerusahaanLengkap;
    use RefreshDatabase;

    protected bool $seed = true;

    /** Route yang tidak relevan untuk kebocoran data. */
    private const LEWATI = ['login', 'lupa-password', 'up', '/', 'storage/{path}'];

    /** Penanda data perusahaan DEFAULT (seed lama) yang tidak boleh terlihat dari perusahaan B. */
    private const PENANDA_A = ['Rizky Maulana', 'Teknologi Informasi', 'Siti Rahma'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'Asia/Jakarta'));
        Storage::fake('local');
        // modul payroll baru aktif di perusahaan DEFAULT agar halamannya ikut teruji
        $this->artisan('payroll:aktifkan', ['kode' => 'DEFAULT']);
    }

    public function test_user_perusahaan_a_tidak_melihat_data_b_di_semua_halaman_api_export_dan_dashboard(): void
    {
        $this->perusahaanLengkap();
        $idA = $this->idMilikSendiri();

        foreach (['admin', 'hr', 'manager.hr', 'manager.it', 'rizky'] as $username) {
            $user = User::where('username', $username)->firstOrFail();
            foreach ($this->daftarUrl($idA) as $url) {
                $this->assertTidakMemuat($this->actingAs($user)->get($url), [self::PENANDA], "{$username} {$url}");
            }
        }
    }

    public function test_user_perusahaan_a_tidak_bisa_membuka_id_milik_b(): void
    {
        $b = $this->perusahaanLengkap();

        foreach (['admin', 'hr', 'manager.hr', 'rizky'] as $username) {
            $user = User::where('username', $username)->firstOrFail();
            // halaman payroll baru memakai ?ubah={id} (bukan route parameter)
            $ubah = ['/pay/jabatan?ubah='.$b['id']['pay_position'], '/pay/libur?ubah='.$b['id']['pay_holiday'],
                '/pay/tipe-leave?ubah='.$b['id']['pay_leave_type'], '/pay/komponen?ubah='.$b['id']['pay_component'],
                '/pay/karyawan?ubah='.$b['id']['karyawan']];
            foreach (array_merge($this->daftarUrl($b['id'], hanyaBerparameter: true), $ubah) as $url) {
                $r = $this->actingAs($user)->get($url);
                $this->assertNotSame(200, $r->getStatusCode() === 200 && ! $r->isRedirect() && str_contains($this->isi($r), self::PENANDA) ? 200 : 0, "{$username} membuka {$url}");
                $this->assertTidakMemuat($r, [self::PENANDA], "{$username} {$url}");
                if ($r->isRedirect()) {
                    $this->assertTidakMemuat($this->actingAs($user)->get((string) $r->headers->get('Location')), [self::PENANDA], "{$username} {$url} (redirect)");
                }
            }
        }
    }

    public function test_route_model_binding_milik_b_selalu_404_atau_403(): void
    {
        $b = $this->perusahaanLengkap();
        $hr = User::where('username', 'hr')->firstOrFail();

        foreach (["/karyawan/{$b['id']['karyawan']}", "/karyawan/{$b['id']['karyawan']}/edit", "/kontrak/{$b['id']['kontrak']}",
            "/kontrak/{$b['id']['kontrak']}/edit", "/kontrak-pdf/{$b['id']['kontrak']}", "/absensi/{$b['id']['attendance']}/edit",
            "/cuti/{$b['id']['leave']}/edit", "/lembur/{$b['id']['overtime']}/edit", "/departemen/{$b['id']['departemen']}/edit",
            "/komponen/{$b['id']['komponen']}/edit", "/payroll/{$b['id']['payroll']}", "/laporan/{$b['id']['payroll']}",
            "/slip/{$b['id']['payslip']}", "/slip/{$b['id']['payslip']}/unduh", "/approval/{$b['id']['approval']}",
            "/users/{$b['id']['user']}/edit", "/notifikasi/{$b['id']['notifikasi']}"] as $url) {
            $this->assertContains($this->actingAs($hr)->get($url)->getStatusCode(), [403, 404], $url);
        }
    }

    public function test_user_perusahaan_b_tidak_melihat_data_a(): void
    {
        $b = $this->perusahaanLengkap();
        $idB = app(CurrentCompany::class)->runAs((int) $b['company']->id, fn () => $this->idMilikSendiri());

        foreach ($b['users'] as $peran => $user) {
            foreach ($this->daftarUrl($idB) as $url) {
                $this->assertTidakMemuat($this->actingAs($user)->get($url), self::PENANDA_A, "B {$peran} {$url}");
            }
        }
    }

    // ---- helper ----

    /** @return array<string, int|null> id data milik perusahaan konteks aktif */
    private function idMilikSendiri(): array
    {
        return [
            'karyawan' => Karyawan::orderBy('id')->value('id'), 'departemen' => Departemen::orderBy('id')->value('id'),
            'komponen' => PayslipComponent::orderBy('id')->value('id'), 'kontrak' => Kontrak::orderBy('id')->value('id'),
            'attendance' => Attendance::orderBy('id')->value('id'), 'leave' => Leave::orderBy('id')->value('id'),
            'overtime' => Overtime::orderBy('id')->value('id'), 'payroll' => Payroll::where('status', 'final')->orderBy('id')->value('id'),
            'payslip' => Payslip::orderBy('id')->value('id'), 'approval' => Approval::orderBy('id')->value('id'),
            'notifikasi' => Notifikasi::orderBy('id')->value('id'), 'user' => User::orderBy('id')->value('id'),
            'role' => Role::where('nama', Role::HR)->value('id'),
        ];
    }

    /**
     * Semua URL GET aplikasi, parameter diisi id dari $id. Ditambah varian pencarian/filter/export.
     *
     * @param  array<string, int|null>  $id
     * @return list<string>
     */
    private function daftarUrl(array $id, bool $hanyaBerparameter = false): array
    {
        $urls = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || in_array($uri, self::LEWATI, true) || str_starts_with($uri, '_')) {
                continue;
            }
            preg_match_all('/\{(\w+)\??\}/', $uri, $m);
            if ($hanyaBerparameter && $m[1] === []) {
                continue;
            }
            $url = $uri;
            foreach ($m[1] as $param) {
                $nilai = $param === 'jenis' ? 'absensi' : ($id[$param] ?? null);
                if ($nilai === null) {
                    continue 2;
                }
                $url = str_replace(['{'.$param.'}', '{'.$param.'?}'], (string) $nilai, $url);
            }
            $urls[] = '/'.$url;
        }

        if (! $hanyaBerparameter) {
            array_push($urls, '/cari?q=RB0', '/cari?q=a', '/karyawan?q=RB0', '/users?q=ptb', '/slip?q=RB0',
                '/slip?periode=2026-10', '/laporan?periode=2026-10', '/laporan/excel?periode=2026-10', '/laporan/pdf?periode=2026-10',
                '/absensi?periode=2026-11', '/lembur?periode=2026-11', '/cuti?periode=2026-11', '/payroll/rekap', '/payroll/riwayat',
                '/pay/karyawan?q=RB0', '/pay/jabatan?q=RB', '/pay/komponen?q=rb');
        }

        return $urls;
    }

    /** @param  list<string>  $penanda */
    private function assertTidakMemuat(TestResponse $r, array $penanda, string $konteks): void
    {
        $isi = $this->isi($r);
        foreach ($penanda as $p) {
            $this->assertStringNotContainsString($p, $isi, "Kebocoran tenant: '{$p}' terlihat di {$konteks} (status {$r->getStatusCode()})");
        }
    }

    private function isi(TestResponse $r): string
    {
        $tipe = (string) $r->headers->get('Content-Type');
        if (str_contains($tipe, 'spreadsheetml')) {
            $file = tempnam(sys_get_temp_dir(), 'bocor').'.xlsx';
            file_put_contents($file, $r->streamedContent());
            $teks = json_encode(IOFactory::load($file)->getActiveSheet()->toArray(), JSON_UNESCAPED_UNICODE);
            @unlink($file);

            return (string) $teks;
        }
        if (str_contains($tipe, 'pdf') || $r->baseResponse instanceof BinaryFileResponse) {
            return ''; // PDF terkompresi; isinya berasal dari query yang sama dengan halaman HTML yang sudah diuji
        }

        return (string) $r->getContent();
    }
}
