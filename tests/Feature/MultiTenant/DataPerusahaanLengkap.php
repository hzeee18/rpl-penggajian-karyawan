<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenant;

use App\Domain\Company\Actions\BuatPerusahaan;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Actions\BuatKontrak;
use App\Domain\Payroll\Actions\KelolaMasterPayroll;
use App\Domain\Payroll\Actions\PasangMenuPayroll;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Holiday;
use App\Domain\Payroll\Models\ImportBatch;
use App\Domain\Payroll\Models\LeaveType;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PeriodConfig;
use App\Domain\Payroll\Models\Position;
use App\Domain\Payroll\Models\ReferenceTable;
use App\Domain\Payroll\Models\ReferenceTableVersion;
use App\Models\Approval;
use App\Models\ApprovalMapping;
use App\Models\Attendance;
use App\Models\Departemen;
use App\Models\ImportExcel;
use App\Models\Karyawan;
use App\Models\Kontrak;
use App\Models\KontrakKomponen;
use App\Models\Leave;
use App\Models\LogAktivitas;
use App\Models\MasterPayroll;
use App\Models\Notifikasi;
use App\Models\Overtime;
use App\Models\Pajak;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Models\Payslip;
use App\Models\PayslipComponent;
use App\Models\Role;
use App\Models\User;

/**
 * Perusahaan kedua dengan data di SEMUA tabel bertenant; setiap teks memuat penanda RAHASIA-B.
 * Dipakai tes kebocoran tenant (Fase 1e): penanda tidak boleh muncul di sisi perusahaan lain.
 */
trait DataPerusahaanLengkap
{
    protected const PENANDA = 'RAHASIA-B';

    /** @return array{company: Company, users: array<string, User>, id: array<string, int>} */
    protected function perusahaanLengkap(): array
    {
        $company = app(BuatPerusahaan::class)->handle(['code' => 'PTB', 'name' => self::PENANDA.' Perusahaan']);

        return app(CurrentCompany::class)->runAs((int) $company->id, function () use ($company) {
            $p = self::PENANDA;
            $role = fn (string $nama) => Role::where('nama', $nama)->firstOrFail()->id;

            $hrd = Departemen::create(['kode' => 'HRD', 'nama' => "{$p} Departemen HRD"]);
            $ops = Departemen::create(['kode' => 'OPS', 'nama' => "{$p} Departemen Operasional"]);
            $karyawan = fn (string $nik, string $nama, Departemen $d) => Karyawan::create([
                'departemen_id' => $d->id, 'pajak_id' => Pajak::value('id'), 'nik' => $nik, 'nama' => "{$p} {$nama}",
                'jabatan' => "{$p} Jabatan", 'tgl_masuk' => '2024-01-02', 'no_rekening' => '9990001111', 'email' => 'b@contoh.test',
                'nama_bank' => 'BANK-B', 'status_kepegawaian' => 'tetap', 'sisa_cuti' => 12, 'is_active' => true,
            ]);
            $kMgr = $karyawan('RB01', 'Manager', $hrd);
            $kHr = $karyawan('RB02', 'Staf HR', $hrd);
            $kStaf = $karyawan('RB03', 'Staf Operasional', $ops);
            $hrd->aturManager([$kMgr->id]);
            $ops->aturManager([$kMgr->id]);

            $user = fn (string $username, string $peran, ?Karyawan $k) => User::create([
                'role_id' => $role($peran), 'karyawan_id' => $k?->id, 'username' => $username, 'password' => 'password',
                'email' => "{$username}@contoh.test", 'is_active' => true,
            ]);
            $users = [
                'admin' => $user('admin.ptb', Role::ADMIN, null),
                'hr' => $user('hr.ptb', Role::HR, $kHr),
                'manager' => $user('manager.ptb', Role::MANAGER, $kMgr),
                'karyawan' => $user('staf.ptb', Role::KARYAWAN, $kStaf),
            ];

            $gp = PayslipComponent::create(['kode' => 'GP', 'nama' => "{$p} Gaji Pokok", 'jenis' => 'penambah', 'tipe_nilai' => 'nominal',
                'nilai_default' => 0, 'dasar_bayar' => 'gaji_pokok', 'status_berlaku' => 'aktif', 'kena_pajak' => true]);
            $um = PayslipComponent::create(['kode' => 'UMB', 'nama' => "{$p} Uang Makan", 'jenis' => 'penambah', 'tipe_nilai' => 'nominal',
                'nilai_default' => 500000, 'dasar_bayar' => 'bulanan', 'status_berlaku' => 'aktif', 'kena_pajak' => true]);

            $kontrak = Kontrak::create(['karyawan_id' => $kStaf->id, 'jenis' => 'PKWTT', 'tgl_mulai' => '2024-01-02',
                'gaji_pokok' => 7000000, 'status_approval' => 'disetujui', 'is_active' => true]);
            KontrakKomponen::create(['kontrak_id' => $kontrak->id, 'payslip_component_id' => $um->id, 'tipe_nilai' => 'nominal', 'nilai' => 500000, 'is_active' => true]);
            $kontrakBaru = Kontrak::create(['karyawan_id' => $kHr->id, 'jenis' => 'PKWT', 'tgl_mulai' => '2026-12-01',
                'tgl_akhir' => '2027-11-30', 'gaji_pokok' => 6500000, 'status_approval' => 'menunggu', 'is_active' => false]);

            $absen = Attendance::create(['karyawan_id' => $kStaf->id, 'tanggal' => '2026-11-02', 'jam_masuk' => '08:00', 'jam_keluar' => '17:00', 'status' => 'hadir']);
            $cuti = Leave::create(['karyawan_id' => $kStaf->id, 'jenis' => 'cuti', 'tgl_mulai' => '2026-11-03', 'tgl_selesai' => '2026-11-03', 'keterangan' => "{$p} cuti"]);
            $lembur = Overtime::create(['karyawan_id' => $kStaf->id, 'tanggal' => '2026-11-04', 'jam_mulai' => '17:00', 'jam_selesai' => '19:00',
                'durasi' => 2, 'keterangan' => "{$p} lembur", 'diinput_oleh' => $users['hr']->id]);

            $master = MasterPayroll::create(['periode' => '2026-10', 'tgl_mulai' => '2026-09-26', 'tgl_selesai' => '2026-10-25', 'tgl_cutoff' => 25, 'hari_kerja' => 22]);
            $payroll = Payroll::create(['master_payroll_id' => $master->id, 'periode' => '2026-10', 'tgl_generate' => '2026-10-27', 'status' => 'final']);
            PayrollDetail::create(['payroll_id' => $payroll->id, 'karyawan_id' => $kStaf->id, 'payslip_component_id' => $gp->id, 'jumlah' => 1, 'nominal' => 7000000]);
            $slip = Payslip::create(['payroll_id' => $payroll->id, 'karyawan_id' => $kStaf->id, 'total_bruto' => 7000000, 'total_potongan' => 0, 'thp' => 7000000]);

            $mapping = ApprovalMapping::where('jenis_ref', 'kontrak')->firstOrFail();
            $approval = Approval::create(['approval_mapping_id' => $mapping->id, 'approver_id' => $users['manager']->id, 'diajukan_oleh' => $users['hr']->id,
                'jenis_ref' => 'kontrak', 'ref_id' => $kontrakBaru->id, 'level' => 1, 'status' => 'menunggu']);
            $notif = Notifikasi::create(['user_id' => $users['manager']->id, 'judul' => "{$p} notifikasi", 'pesan' => "{$p} pesan", 'jenis_ref' => 'approval', 'ref_id' => $approval->id]);
            LogAktivitas::create(['user_id' => $users['admin']->id, 'aktivitas' => "{$p} aktivitas", 'modul' => 'User', 'created_at' => now()]);
            ImportExcel::create(['user_id' => $users['hr']->id, 'jenis' => 'absensi', 'nama_file' => "{$p}.xlsx", 'status' => 'berhasil', 'baris' => 1]);

            // payroll baru (Fase 2): menu aktif + data master bertanda
            (new PasangMenuPayroll)->berikanHak((int) $company->id);
            $jabatan = Position::create(['code' => 'RBJ', 'name' => "{$p} Jabatan"]);
            $libur = Holiday::create(['holiday_date' => '2026-11-20', 'name' => "{$p} Libur"]);
            $tipe = LeaveType::create(['code' => 'rb_tipe', 'name' => "{$p} Tipe Leave"]);
            $komponenBaru = Component::create(['code' => 'rb_komponen', 'name' => "{$p} Komponen", 'type' => 'earning', 'default_amount' => '100.00']);
            PeriodConfig::create(['start_day' => 21, 'end_day' => 20, 'start_in_previous_month' => true]);
            $batchB = ImportBatch::create(['type' => 'attendances', 'file_name' => "{$p}.csv", 'status' => 'previewing']);
            $batchB->rows()->create(['row_no' => 2, 'payload' => ['nik' => $p], 'is_valid' => false, 'error_message' => $p]);
            $absenB = \App\Domain\Payroll\Models\Attendance::create(['employee_id' => $kStaf->id, 'attendance_date' => '2026-11-05', 'late_hours' => '0']);
            $leaveB = \App\Domain\Payroll\Models\Leave::create(['employee_id' => $kStaf->id, 'leave_type_id' => $tipe->id, 'leave_date' => '2026-11-06', 'note' => "{$p} leave"]);
            $lemburB = \App\Domain\Payroll\Models\Overtime::create(['employee_id' => $kStaf->id, 'overtime_date' => '2026-11-07', 'duration_hours' => '2', 'note' => "{$p} lembur"]);
            $refB = ReferenceTable::create(['code' => 'rb_tabel', 'name' => "{$p} Tabel"]);
            $refVersiB = ReferenceTableVersion::create(['reference_table_id' => $refB->id, 'version_no' => 1, 'status' => 'draft']);
            $refVersiB->rows()->create(['key_value' => $p, 'result_text' => $p]);
            $masterB = app(KelolaMasterPayroll::class)->buatMaster(['code' => 'rb_master', 'name' => "{$p} Master"]);
            $masterVersiB = $masterB->versions()->firstOrFail();
            $masterVersiB->params()->create(['code' => 'rb_param', 'name' => "{$p} Param", 'value' => '1']);
            $kontrakBaruB = app(BuatKontrak::class)->handle(
                ['employee_id' => $kStaf->id, 'contract_name' => "{$p} Kontrak Payroll", 'start_date' => '2026-01-01'],
                [$komponenBaru->id => '123456.00'],
            );
            Employee::findOrFail($kStaf->id)->fill(['position_id' => $jabatan->id, 'has_npwp' => true, 'npwp' => '99.999.999.9-999.999'])->save();

            $runB = PayrollRun::create(['title' => "{$p} Payroll", 'period_start' => '2026-10-26', 'period_end' => '2026-11-25',
                'status' => 'pending_approval', 'created_by' => $users['hr']->id, 'approver_user_id' => $users['manager']->id]);
            $runB->employees()->create(['employee_id' => $kStaf->id, 'contract_id' => $kontrakBaruB->id, 'period_start' => '2026-10-26', 'period_end' => '2026-11-25',
                'employee_no' => 'RB03', 'nik' => 'RB03', 'full_name' => "{$p} Staf", 'ptkp_status' => 'TK/0', 'take_home_pay' => '1.00']);

            return ['company' => $company, 'users' => $users, 'id' => [
                'karyawan' => $kStaf->id, 'departemen' => $ops->id, 'komponen' => $um->id, 'kontrak' => $kontrak->id,
                'attendance' => $absen->id, 'leave' => $cuti->id, 'overtime' => $lembur->id, 'payroll' => $payroll->id,
                'payslip' => $slip->id, 'approval' => $approval->id, 'notifikasi' => $notif->id, 'user' => $users['hr']->id,
                'role' => $role(Role::HR),
                'pay_position' => $jabatan->id, 'pay_holiday' => $libur->id, 'pay_leave_type' => $tipe->id, 'pay_component' => $komponenBaru->id, 'pay_contract' => $kontrakBaruB->id,
                'pay_ref_version' => $refVersiB->id, 'pay_master_version' => $masterVersiB->id,
                'pay_run' => $runB->id,
                'pay_import' => $batchB->id, 'pay_attendance' => $absenB->id, 'pay_leave' => $leaveB->id, 'pay_overtime' => $lemburB->id,
            ]];
        });
    }
}
