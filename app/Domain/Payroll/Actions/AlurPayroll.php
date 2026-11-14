<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Company\Models\Company;
use App\Domain\Payroll\Engine\Desimal;
use App\Domain\Payroll\Events\PayrollDisetujui;
use App\Domain\Payroll\Jobs\ProsesGenerate;
use App\Domain\Payroll\Jobs\ProsesPratinjau;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Item;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\RunAdjustment;
use App\Domain\Payroll\Models\RunEmployee;
use App\Domain\Payroll\Models\TaxMonthlyHistory;
use App\Domain\Payroll\Run\HitungRunKaryawan;
use App\Domain\Payroll\Run\KontrakPeriode;
use App\Domain\Payroll\Run\PenyetujuPayroll;
use App\Models\Departemen;
use App\Models\Privilege;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Use case alur payroll: buat run (draft), mulai preview (queue), ubah/kembalikan sel, generate (queue),
 * setujui/tolak (Manager approver), hapus draft. Status: draft -> pending_approval -> approved | rejected.
 */
final class AlurPayroll
{
    public function __construct(private readonly AuditLogger $audit, private readonly HitungRunKaryawan $hitung) {}

    public function buat(string $judul, string $periode): PayrollRun
    {
        $this->pastikanHakSendiri('create');
        // periode harus sudah dibuat di Kalender Payroll > Periode (AUDIT.md §54)
        $p = PayPeriod::where('period_month', $periode)->first()
            ?? throw ValidationException::withMessages(['periode' => 'Periode '.nama_periode($periode).' belum dibuat. Buat dulu di Kalender Payroll.']);

        return PayrollRun::create([
            'title' => $judul, 'period_start' => $p->start_date->toDateString(), 'period_end' => $p->end_date->toDateString(),
            'status' => PayrollRun::DRAFT, 'job_status' => 'idle', 'created_by' => Auth::id(),
        ]);
    }

    /** @param list<int> $pilihan id departemen / karyawan */
    public function mulaiPratinjau(PayrollRun $run, int $masterId, string $lingkup, array $pilihan): void
    {
        $this->pastikanHakSendiri('update');
        DB::transaction(function () use (&$run, $masterId, $lingkup, $pilihan) {
            $run = $this->kunciBaris($run); // cegah dua proses jalan bersamaan (klik ganda)
            $this->pastikanDraftDanSiap($run);
            $this->siapkanPratinjau($run, $masterId, $lingkup, $pilihan);
        });

        ProsesPratinjau::dispatch((int) $run->id, (int) $run->company_id);
    }

    /** @param list<int> $pilihan */
    private function siapkanPratinjau(PayrollRun $run, int $masterId, string $lingkup, array $pilihan): void
    {
        $master = Master::where('is_active', true)->findOrFail($masterId);
        if ($master->current_version_id === null) {
            throw ValidationException::withMessages(['master_id' => 'Master payroll ini belum punya versi terbit.']);
        }
        if ($lingkup !== 'all' && $pilihan === []) {
            throw ValidationException::withMessages(['pilihan' => 'Pilih minimal satu departemen atau karyawan.']);
        }
        $run->forceFill([
            'master_version_id' => $master->current_version_id, 'employee_scope' => $lingkup,
            'employee_selection' => $lingkup === 'all' ? null : array_values(array_map('intval', $pilihan)),
            'job_type' => 'preview', 'job_status' => 'queued', 'job_error' => null,
        ])->save();
    }

    /** Ubah nilai akhir satu sel (override) atau kembalikan ke hasil rumus ($nilai = null). Tanpa audit log. */
    /**
     * Tambah penyesuaian (AUDIT.md §52): jenis dari Katalog Komponen aktif, nominal >= 0, keterangan wajib.
     * Hanya untuk karyawan yang ada di preview; baris karyawan itu langsung dihitung ulang.
     *
     * @param  array{employee_id: int, component_id: int, amount: string, note: string}  $data
     */
    public function tambahPenyesuaian(PayrollRun $run, array $data): RunAdjustment
    {
        $this->pastikanHakSendiri('update');

        return DB::transaction(function () use ($run, $data) {
            $run = $this->kunciBaris($run);
            $this->pastikanDraftDanSiap($run);
            if (! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $data['amount']) || Desimal::banding($data['amount'], '0') <= 0) {
                throw ValidationException::withMessages(['amount' => 'Nominal harus angka lebih dari 0 (maks. 2 desimal).']);
            }
            $re = $run->employees()->where('employee_id', $data['employee_id'])->first()
                ?? throw ValidationException::withMessages(['employee_id' => 'Karyawan ini tidak ada di preview payroll.']);
            if (! Component::whereKey($data['component_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['component_id' => 'Jenis penyesuaian harus komponen aktif di Katalog Komponen.']);
            }

            $adj = RunAdjustment::create($data + ['run_id' => $run->id, 'created_by' => Auth::id()]);
            $this->audit->log('created', $adj, new: $adj->getAttributes());
            $this->hitungUlang($run, $re);

            return $adj;
        });
    }

    public function hapusPenyesuaian(PayrollRun $run, RunAdjustment $adj): void
    {
        $this->pastikanHakSendiri('update');
        DB::transaction(function () use ($run, $adj) {
            $run = $this->kunciBaris($run);
            $this->pastikanDraftDanSiap($run);
            abort_unless((int) $adj->run_id === (int) $run->id, 404);
            $this->audit->log('deleted', $adj, old: $adj->getAttributes());
            $adj->delete();
            $re = $run->employees()->where('employee_id', $adj->employee_id)->first();
            if ($re !== null) {
                $this->hitungUlang($run, $re);
            }
        });
    }

    /** Hitung ulang satu karyawan dengan kontrak yang berlaku di periode (sama dengan preview). */
    private function hitungUlang(PayrollRun $run, RunEmployee $re): void
    {
        $employee = $re->employee()->firstOrFail();
        $kontrak = KontrakPeriode::untuk($employee, $run->period_start->toImmutable(), $run->period_end->toImmutable());
        if ($kontrak->kosong()) {
            throw ValidationException::withMessages(['run' => 'Kontrak karyawan ini sudah tidak berlaku di periode payroll. Ulangi preview.']);
        }
        $this->hitung->handle($run->loadMissing('masterVersion'), $employee, $kontrak);
    }

    public function generate(PayrollRun $run): void
    {
        $this->pastikanHakSendiri('create');
        DB::transaction(function () use (&$run) {
            $run = $this->kunciBaris($run); // klik ganda: request kedua menunggu lalu ditolak karena sudah antre
            $this->pastikanDraftDanSiap($run);
            if (! $run->employees()->exists()) {
                throw ValidationException::withMessages(['run' => 'Lihat preview dulu sebelum generate.']);
            }
            $minus = $run->employees()->where('take_home_pay', '<', 0)->orderBy('nik')->pluck('full_name');
            if ($minus->isNotEmpty()) {
                throw ValidationException::withMessages(['run' => 'Gaji bersih (THP) minus untuk '.$minus->take(5)->join(', ').($minus->count() > 5 ? ' dan '.($minus->count() - 5).' lainnya' : '').'. Periksa potongan atau rumus sebelum generate.']);
            }
            if (self::butuhApproval((int) $run->company_id) && PenyetujuPayroll::daftar()->isEmpty()) {
                throw ValidationException::withMessages(['run' => 'Belum ada penyetuju payroll. Atur penyetujunya (role atau user) di menu Approver Payroll.']);
            }
            $run->forceFill(['job_type' => 'generate', 'job_status' => 'queued', 'job_error' => null])->save();
        });

        ProsesGenerate::dispatch((int) $run->id, (int) $run->company_id, Auth::id());
    }

    /** Setelan perusahaan (Approver Payroll, Admin): payroll yang di-generate perlu disetujui Manager atau langsung final. */
    public static function butuhApproval(int $companyId): bool
    {
        return (bool) (Company::whereKey($companyId)->value('payroll_butuh_approval') ?? true);
    }

    public function setujui(PayrollRun $run, User $user): void
    {
        DB::transaction(function () use ($run, $user) {
            $run = $this->kunciBaris($run); // klik ganda: request kedua melihat status sudah approved
            $this->pastikanApprover($run, $user);
            $run->forceFill(['approver_user_id' => $user->id])->save(); // dicatat: siapa yang menyetujui
            $this->finalkan($run, ['status' => PayrollRun::APPROVED, 'oleh' => $user->id]);
        });
        event(new PayrollDisetujui((int) $run->id, (int) $run->company_id));
    }

    /**
     * Jadikan run final (approved): catat riwayat pajak bulanan dan audit. Dipakai saat Manager menyetujui,
     * dan oleh ProsesGenerate bila perusahaan tidak memakai approval. Pemanggil yang memicu event slip.
     *
     * @param  array<string, mixed>  $catatanAudit
     */
    public function finalkan(PayrollRun $run, array $catatanAudit): void
    {
        DB::transaction(function () use ($run, $catatanAudit) {
            $run->forceFill(['status' => PayrollRun::APPROVED, 'approved_at' => now()])->save();
            $kodePph = Item::where('master_version_id', $run->master_version_id)->where('item_role', 'pph21')->pluck('code')->all();
            foreach ($run->employees()->with('items')->get() as $re) {
                $pph = $re->items->first(fn ($i) => in_array($i->code, $kodePph, true));
                TaxMonthlyHistory::updateOrCreate(['employee_id' => $re->employee_id, 'run_id' => $run->id], [
                    'tax_year' => (int) $run->period_end->format('Y'), 'tax_month' => (int) $run->period_end->format('n'),
                    'gross_taxable' => $re->gross_taxable, 'tax_deduction' => $re->tax_deduction, 'pph21' => $pph?->final_amount ?? '0.00',
                ]);
            }
            $this->audit->log('approved', $run, new: $catatanAudit);
        });
    }

    public function tolak(PayrollRun $run, User $user, string $alasan): void
    {
        DB::transaction(function () use ($run, $user, $alasan) {
            $run = $this->kunciBaris($run);
            $this->pastikanApprover($run, $user);
            $run->forceFill(['status' => PayrollRun::REJECTED, 'rejected_at' => now(), 'reject_reason' => $alasan, 'approver_user_id' => $user->id])->save();
            // karyawan bebas lagi: HR membuat payroll baru dari awal
            RunEmployee::where('run_id', $run->id)->update(['is_blocking' => false]);
            $this->audit->log('rejected', $run, new: ['alasan' => $alasan]);
        });
    }

    /**
     * Tarik kembali payroll yang menunggu approval (AUDIT.md §58): HR yang menemukan kesalahan setelah generate
     * mengembalikan run ke draft, memperbaiki (preview ulang / penyesuaian), lalu generate lagi. Karyawan dilepas
     * dari tanda blocking; versi master/tabel referensi yang sudah terkunci tetap terkunci.
     */
    public function tarikKembali(PayrollRun $run, ?string $alasan): void
    {
        $this->pastikanHakSendiri('update');
        DB::transaction(function () use ($run, $alasan) {
            $run = $this->kunciBaris($run);
            if ($run->status !== PayrollRun::PENDING) {
                throw ValidationException::withMessages(['run' => 'Hanya payroll yang menunggu persetujuan yang bisa ditarik kembali.']);
            }
            $run->forceFill(['status' => PayrollRun::DRAFT, 'generated_at' => null, 'approver_user_id' => null])->save();
            RunEmployee::where('run_id', $run->id)->update(['is_blocking' => false]);
            $this->audit->log('withdrawn', $run, new: ['alasan' => $alasan, 'oleh' => Auth::id()]);
        });
    }

    /**
     * Batalkan proses preview/generate yang macet (AUDIT.md §48): run kembali ke draft siap diproses ulang.
     * Preview setengah jadi dibuang; tanda blocking generate dilepas. Job yang telat selesai tidak menimpa (prosesMasihBerlaku).
     */
    public function batalkanProses(PayrollRun $run): void
    {
        $this->pastikanHakSendiri('update');
        DB::transaction(function () use ($run) {
            $run = $this->kunciBaris($run);
            if (! $run->isDraft() || ! $run->macet()) {
                throw ValidationException::withMessages(['run' => 'Proses hanya bisa dibatalkan bila sudah antre/berjalan lebih dari '.PayrollRun::BATAS_MACET_MENIT.' menit.']);
            }
            $lama = $run->only(['job_type', 'job_status', 'job_started_at']);
            if ($run->job_type === 'preview') {
                $run->employees()->delete();
            }
            RunEmployee::where('run_id', $run->id)->update(['is_blocking' => false]);
            $run->forceFill([
                'job_status' => 'failed', 'job_finished_at' => now(),
                'job_error' => 'Proses '.($run->job_type === 'generate' ? 'generate' : 'preview').' dibatalkan oleh '.Auth::user()?->namaTampil().' karena macet. Silakan ulangi.',
            ])->save();
            $this->audit->log('job_cancelled', $run, old: $lama, new: ['job_status' => 'failed']);
        });
    }

    public function hapus(PayrollRun $run): void
    {
        $this->pastikanHakSendiri('delete');
        if (! $run->isDraft() || $run->sedangDiproses()) {
            throw ValidationException::withMessages(['run' => 'Hanya payroll draft yang tidak sedang diproses yang bisa dihapus.']);
        }
        DB::transaction(function () use ($run) {
            DB::table('pay_run_reference_versions')->where('run_id', $run->id)->delete();
            $run->delete(); // fisik; rincian ikut terhapus (cascade)
        });
    }

    /**
     * Payroll hanya dibuat/diproses oleh user yang ROLE-NYA SENDIRI punya hak Generate Payroll (keputusan
     * AUDIT.md §26: role HR). Hak warisan (Manager HR mendapat hak HR di modul lama) tidak dihitung.
     */
    private function pastikanHakSendiri(string $aksi): void
    {
        $user = Auth::user();
        if ($user === null || ! Privilege::cekAkses((int) $user->role_id, 'pay-run', $aksi)) {
            throw new HttpException(403, 'Payroll hanya bisa dibuat dan diproses oleh role yang diberi hak Generate Payroll (HR).');
        }
    }

    private function pastikanDraftDanSiap(PayrollRun $run): void
    {
        if (! $run->isDraft()) {
            throw ValidationException::withMessages(['run' => 'Payroll ini sudah di-generate, jadi datanya tidak bisa diubah.']);
        }
        if ($run->sedangDiproses()) {
            throw ValidationException::withMessages(['run' => 'Payroll sedang diproses. Tunggu sampai selesai.']);
        }
    }

    /** Kunci baris run sampai transaksi selesai, kembalikan data terbaru (anti klik ganda / proses bersamaan). */
    private function kunciBaris(PayrollRun $run): PayrollRun
    {
        return DB::transactionLevel() > 0 ? PayrollRun::lockForUpdate()->findOrFail($run->id) : $run->refresh();
    }

    private function pastikanApprover(PayrollRun $run, User $user): void
    {
        if ($run->status !== PayrollRun::PENDING) {
            throw ValidationException::withMessages(['run' => 'Payroll ini tidak sedang menunggu persetujuan.']);
        }
        if (! PenyetujuPayroll::boleh($user)) {
            throw new HttpException(403, 'Anda bukan penyetuju payroll. Penyetuju diatur di menu Approver Payroll.');
        }
    }
}
