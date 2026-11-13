<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payroll\Engine\Desimal;
use App\Domain\Payroll\Events\PayrollDisetujui;
use App\Domain\Payroll\Jobs\ProsesGenerate;
use App\Domain\Payroll\Jobs\ProsesPratinjau;
use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\Item;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PeriodConfig;
use App\Domain\Payroll\Models\RunEmployee;
use App\Domain\Payroll\Models\TaxMonthlyHistory;
use App\Domain\Payroll\Run\HitungRunKaryawan;
use App\Models\Privilege;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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
        $config = PeriodConfig::first();
        if ($config === null) {
            throw ValidationException::withMessages(['periode' => 'Atur cut off payroll dulu di menu Cut Off.']);
        }
        try {
            [$mulai, $selesai] = $config->rentang($periode);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['periode' => $e->getMessage()]);
        }

        return PayrollRun::create([
            'title' => $judul, 'period_start' => $mulai->toDateString(), 'period_end' => $selesai->toDateString(),
            'status' => PayrollRun::DRAFT, 'job_status' => 'idle', 'created_by' => Auth::id(),
        ]);
    }

    /** @param list<int> $pilihan id departemen / karyawan */
    public function mulaiPratinjau(PayrollRun $run, int $masterId, string $lingkup, array $pilihan): void
    {
        $this->pastikanHakSendiri('update');
        $this->pastikanDraftDanSiap($run);
        $master = Master::where('is_active', true)->findOrFail($masterId);
        if ($master->current_version_id === null) {
            throw ValidationException::withMessages(['master_id' => 'Master payroll ini belum punya versi terbit.']);
        }
        if ($lingkup !== 'all' && $pilihan === []) {
            throw ValidationException::withMessages(['pilihan' => 'Pilih minimal satu departemen / karyawan.']);
        }
        $run->forceFill([
            'master_version_id' => $master->current_version_id, 'employee_scope' => $lingkup,
            'employee_selection' => $lingkup === 'all' ? null : array_values(array_map('intval', $pilihan)),
            'job_type' => 'preview', 'job_status' => 'queued', 'job_error' => null,
        ])->save();

        ProsesPratinjau::dispatch((int) $run->id, (int) $run->company_id);
    }

    /** Ubah nilai akhir satu sel (override) atau kembalikan ke hasil rumus ($nilai = null). Tanpa audit log. */
    public function ubahSel(PayrollRun $run, RunEmployee $re, string $kode, ?string $nilai): void
    {
        $this->pastikanHakSendiri('update');
        $this->pastikanDraftDanSiap($run);
        $item = $re->items()->where('code', $kode)->firstOrFail();
        if ($nilai !== null && ! preg_match('/^-?\d{1,13}(\.\d{1,2})?$/', $nilai)) {
            throw ValidationException::withMessages(['nilai' => 'Nilai harus angka (maks. 2 desimal).']);
        }
        $item->forceFill($nilai === null
            ? ['is_overridden' => false, 'final_amount' => $item->calculated_amount]
            : ['is_overridden' => true, 'final_amount' => Desimal::uang($nilai)])->save();

        $kontrak = Contract::findOrFail($re->contract_id);
        $this->hitung->handle($run->loadMissing('masterVersion'), $re->employee()->firstOrFail(), $kontrak);
    }

    public function generate(PayrollRun $run): void
    {
        $this->pastikanHakSendiri('create');
        $this->pastikanDraftDanSiap($run);
        if (! $run->employees()->exists()) {
            throw ValidationException::withMessages(['run' => 'Lihat preview dulu sebelum generate.']);
        }
        $approver = User::find(Auth::user()?->approver_user_id);
        if ($approver === null || ! $approver->is_active) {
            throw ValidationException::withMessages(['run' => 'Akun Anda belum punya approver payroll aktif. Minta Admin mengatur di menu Approver Payroll.']);
        }
        $run->forceFill(['job_type' => 'generate', 'job_status' => 'queued', 'job_error' => null])->save();

        ProsesGenerate::dispatch((int) $run->id, (int) $run->company_id, Auth::id());
    }

    public function setujui(PayrollRun $run, User $user): void
    {
        $this->pastikanApprover($run, $user);
        DB::transaction(function () use ($run) {
            $run->forceFill(['status' => PayrollRun::APPROVED, 'approved_at' => now()])->save();
            $kodePph = Item::where('master_version_id', $run->master_version_id)->where('item_role', 'pph21')->pluck('code')->all();
            foreach ($run->employees()->with('items')->get() as $re) {
                $pph = $re->items->first(fn ($i) => in_array($i->code, $kodePph, true));
                TaxMonthlyHistory::updateOrCreate(['employee_id' => $re->employee_id, 'run_id' => $run->id], [
                    'tax_year' => (int) $run->period_end->format('Y'), 'tax_month' => (int) $run->period_end->format('n'),
                    'gross_taxable' => $re->gross_taxable, 'tax_deduction' => $re->tax_deduction, 'pph21' => $pph?->final_amount ?? '0.00',
                ]);
            }
            $this->audit->log('approved', $run, new: ['status' => PayrollRun::APPROVED]);
        });
        event(new PayrollDisetujui((int) $run->id, (int) $run->company_id));
    }

    public function tolak(PayrollRun $run, User $user, string $alasan): void
    {
        $this->pastikanApprover($run, $user);
        DB::transaction(function () use ($run, $alasan) {
            $run->forceFill(['status' => PayrollRun::REJECTED, 'rejected_at' => now(), 'reject_reason' => $alasan])->save();
            // karyawan bebas lagi: HR membuat payroll baru dari awal
            RunEmployee::where('run_id', $run->id)->update(['is_blocking' => false]);
            $this->audit->log('rejected', $run, new: ['alasan' => $alasan]);
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
            throw ValidationException::withMessages(['run' => 'Payroll ini sudah digenerate; data tidak bisa diubah.']);
        }
        if ($run->sedangDiproses()) {
            throw ValidationException::withMessages(['run' => 'Payroll sedang diproses, tunggu sampai selesai.']);
        }
    }

    private function pastikanApprover(PayrollRun $run, User $user): void
    {
        if ($run->status !== PayrollRun::PENDING) {
            throw ValidationException::withMessages(['run' => 'Payroll ini tidak sedang menunggu persetujuan.']);
        }
        if ((int) $run->approver_user_id !== (int) $user->id) {
            throw new HttpException(403, 'Anda bukan approver payroll ini.');
        }
    }
}
