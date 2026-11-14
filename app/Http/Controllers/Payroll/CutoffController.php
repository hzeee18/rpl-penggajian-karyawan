<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payroll;

use App\Domain\Audit\AuditLogger;
use App\Domain\Company\Models\Company;
use App\Domain\Payroll\Models\PeriodConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Konfigurasi cut off periode payroll (satu per perusahaan). */
final class CutoffController extends Controller
{
    public function index()
    {
        $config = PeriodConfig::first() ?? new PeriodConfig(['start_day' => 1, 'end_day' => 31, 'start_in_previous_month' => false]);
        $contoh = [];
        foreach ([now()->format('Y-m'), now()->addMonthNoOverflow()->format('Y-m')] as $periode) {
            try {
                $contoh[$periode] = $config->rentang($periode);
            } catch (InvalidArgumentException) {
                $contoh[$periode] = null;
            }
        }

        $prorata = Company::whereKey((int) auth()->user()->company_id)->value('prorata_dasar') === 'tidak' ? 'tidak' : 'kerja';

        return view('payroll.cutoff', compact('config', 'contoh', 'prorata'));
    }

    public function update(Request $request, AuditLogger $audit)
    {
        $data = $request->validate([
            'start_day' => 'required|integer|between:1,31',
            'end_day' => 'required|integer|between:1,31',
            'start_in_previous_month' => 'nullable|boolean',
        ]);
        // form HTTP mengirim teks: ubah ke angka agar perbandingan ketat di bawah benar (di test nilai sudah angka)
        $data['start_day'] = (int) $data['start_day'];
        $data['end_day'] = (int) $data['end_day'];
        $data['start_in_previous_month'] = (bool) ($data['start_in_previous_month'] ?? false);
        if (! $data['start_in_previous_month'] && $data['start_day'] > $data['end_day']) {
            return back()->withInput()->withErrors(['end_day' => 'Tanggal selesai harus setelah tanggal mulai, atau centang "mulai di bulan sebelumnya".']);
        }
        // periode berurutan harus menyambung tanpa celah/tumpang tindih, supaya tiap tanggal masuk tepat satu periode
        if (! $data['start_in_previous_month']) {
            if ($data['start_day'] !== 1) {
                return back()->withInput()->withErrors(['start_day' => 'Untuk periode dalam satu bulan, tanggal mulai harus 1. Kalau tidak, tanggal 1 sampai '.($data['start_day'] - 1).' tidak masuk periode mana pun.']);
            }
            if ($data['end_day'] !== 31) {
                return back()->withInput()->withErrors(['end_day' => 'Untuk periode dalam satu bulan, isi tanggal selesai 31 (= akhir bulan; otomatis jadi 28/29/30 di bulan pendek). Dengan '.$data['end_day'].', tanggal '.($data['end_day'] + 1).' s/d akhir bulan tidak terhitung.']);
            }
        } elseif ($data['start_day'] !== $data['end_day'] + 1) {
            return back()->withInput()->withErrors(['start_day' => 'Periode lintas bulan harus menyambung: tanggal mulai = tanggal selesai + 1 (mis. 26 s/d 25). Dengan '.$data['start_day'].' s/d '.$data['end_day'].' ada tanggal yang '.($data['start_day'] > $data['end_day'] + 1 ? 'tidak terhitung' : 'terhitung dua kali').'.']);
        }

        DB::transaction(function () use ($data, $audit) {
            $config = PeriodConfig::firstOrNew();
            $config->fill($data)->save();
            $audit->logChanges($config->wasRecentlyCreated ? 'created' : 'updated', $config);
        });

        return redirect()->route('pay.cutoff.index')->with('sukses', 'Konfigurasi cut off disimpan.');
    }

    /** Dasar prorata komponen kontrak (AUDIT.md §51): kalender | kerja | tidak. */
    public function prorata(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['prorata_dasar' => 'required|in:kerja,tidak']);
        $company = Company::findOrFail((int) $request->user()->company_id);
        $lama = $company->prorata_dasar;
        $company->forceFill($data)->save();
        $audit->log('updated', $company, old: ['prorata_dasar' => $lama], new: $data);

        return redirect()->route('pay.cutoff.index')->with('sukses', 'Dasar prorata disimpan. Berlaku untuk preview berikutnya.');
    }
}
