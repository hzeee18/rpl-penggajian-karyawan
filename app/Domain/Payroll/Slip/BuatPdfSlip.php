<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Slip;

use App\Domain\Company\Models\Company;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Models\RunEmployee;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Buat PDF slip dari snapshot run (bukan dari kontrak/master terkini), dengan password (dompdf/CPDF)
 * bila setelan perusahaan slip_pakai_password aktif (AUDIT.md §50).
 * File disimpan per perusahaan: payslips/{company_id}/{run_id}/{nomor_karyawan}.pdf
 */
final class BuatPdfSlip
{
    public function handle(Payslip $slip): Payslip
    {
        $re = RunEmployee::with(['run', 'items'])->findOrFail($slip->run_employee_id);
        $company = Company::findOrFail($re->company_id);
        $password = $company->slip_pakai_password ? PasswordSlip::untuk($re) : null;
        if ($company->slip_pakai_password && $password === null) {
            $slip->forceFill(['file_path' => null, 'generated_at' => null, 'note' => 'PDF belum terbit: nomor karyawan atau nomor rekening belum lengkap untuk password.'])->save();

            return $slip;
        }

        $pdf = Pdf::loadView('payroll.slip.pdf', [
            're' => $re, 'company' => $company,
            'penambah' => $re->items->where('type', 'earning')->values(),
            'pengurang' => $re->items->where('type', 'deduction')->values(),
            'tandaTangan' => $company->signature_path && Storage::disk('local')->exists($company->signature_path)
                ? 'data:image/png;base64,'.base64_encode((string) Storage::disk('local')->get($company->signature_path)) : null,
        ])->setPaper('a4');

        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        // pemilik (owner) password acak per file: hanya dipakai PDF reader untuk hak penuh, tidak disimpan
        if ($password !== null) {
            $dompdf->getCanvas()->get_cpdf()->setEncryption($password, bin2hex(random_bytes(16)), ['print']);
        }

        $path = "payslips/{$re->company_id}/{$re->run_id}/".preg_replace('/[^A-Za-z0-9_-]/', '_', $re->employee_no).'.pdf';
        Storage::disk('local')->put($path, $dompdf->output());
        $slip->forceFill(['file_path' => $path, 'generated_at' => now(), 'note' => null])->save();

        return $slip;
    }
}
