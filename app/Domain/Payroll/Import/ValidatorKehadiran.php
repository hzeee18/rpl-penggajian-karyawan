<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Import;

use App\Domain\Payroll\Models\Attendance;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Leave;
use App\Domain\Payroll\Models\LeaveType;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Validasi satu baris kehadiran (dipakai import CSV DAN input manual, supaya aturannya sama):
 * - NIK harus ada di perusahaan aktif; tipe leave aktif; format tanggal/jam/angka tetap.
 * - Absensi dan leave di tanggal yang sama untuk karyawan yang sama ditolak (dua arah).
 * - Leave di Sabtu/Minggu diabaikan (rentang dipecah per hari kerja).
 * - Data di periode yang sudah final ditolak (KunciPeriode).
 * - Duplikat di dalam satu file ditolak.
 */
final class ValidatorKehadiran
{
    /** @var array<string, true> kunci "A|emp|tgl" / "L|emp|tgl" yang sudah dipakai baris sebelumnya */
    private array $dipakai = [];

    public function __construct(private readonly KunciPeriode $kunci) {}

    /** Mulai file/batch baru. */
    public function reset(): void
    {
        $this->dipakai = [];
    }

    /**
     * @param  array<string, mixed>  $p  payload dengan kunci kolom CSV (JenisKehadiran::kolom())
     * @return array{rekaman: list<array<string, mixed>>, error: list<string>}
     */
    public function periksa(JenisKehadiran $jenis, array $p, ?int $abaikanId = null): array
    {
        $p = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $p);
        $error = [];

        $employee = ($p['nik'] ?? '') === '' ? null : Employee::where('nik', $p['nik'])->first();
        if ($employee === null) {
            $error[] = 'NIK '.($p['nik'] ?? '(kosong)').' tidak ditemukan';
        } elseif (! $employee->is_active) {
            $error[] = "Karyawan {$p['nik']} tidak aktif";
        }

        $rekaman = match ($jenis) {
            JenisKehadiran::Absensi => $this->absensi($p, $error),
            JenisKehadiran::Leave => $this->leave($p, $error),
            JenisKehadiran::Lembur => $this->lembur($p, $error),
        };

        if ($employee !== null && $error === []) {
            foreach ($rekaman as &$r) {
                $r['employee_id'] = (int) $employee->id;
                $this->periksaBentrok($jenis, $r, $abaikanId, $error);
            }
            unset($r);
        }

        return ['rekaman' => $error === [] ? $rekaman : [], 'error' => array_values(array_unique($error))];
    }

    /** @param list<string> $error */
    private function absensi(array $p, array &$error): array
    {
        $tanggal = $this->tanggal($p['tanggal'] ?? '', 'tanggal', $error);
        $masuk = $this->jam($p['jam_masuk'] ?? '', 'jam_masuk', $error);
        $keluar = $this->jam($p['jam_keluar'] ?? '', 'jam_keluar', $error);
        $telat = $this->angka($p['jam_terlambat'] ?? '', 'jam_terlambat', $error, bolehNol: true, bawaan: '0');

        return $tanggal === null ? [] : [[
            'attendance_date' => $tanggal->toDateString(), 'check_in' => $masuk, 'check_out' => $keluar, 'late_hours' => $telat,
        ]];
    }

    /** @param list<string> $error */
    private function leave(array $p, array &$error): array
    {
        $tipe = ($p['kode_tipe'] ?? '') === '' ? null : LeaveType::where('code', $p['kode_tipe'])->where('is_active', true)->first();
        if ($tipe === null) {
            $error[] = 'Tipe leave '.($p['kode_tipe'] ?? '(kosong)').' tidak ditemukan atau nonaktif';
        }
        $mulai = $this->tanggal($p['tanggal_mulai'] ?? '', 'tanggal_mulai', $error);
        $selesai = ($p['tanggal_selesai'] ?? '') === '' ? $mulai : $this->tanggal($p['tanggal_selesai'], 'tanggal_selesai', $error);
        if ($mulai === null || $selesai === null || $tipe === null) {
            return [];
        }
        if ($selesai->lt($mulai)) {
            $error[] = 'tanggal_selesai sebelum tanggal_mulai';

            return [];
        }
        if ($mulai->diffInDays($selesai) > 366) {
            $error[] = 'rentang leave lebih dari satu tahun';

            return [];
        }

        $rekaman = [];
        foreach (CarbonPeriod::create($mulai, $selesai) as $hari) {
            if (! $hari->isWeekend()) { // Sabtu/Minggu diabaikan
                $rekaman[] = ['leave_type_id' => (int) $tipe->id, 'leave_date' => $hari->toDateString(), 'note' => $this->teks($p['keterangan'] ?? null)];
            }
        }
        if ($rekaman === []) {
            $error[] = 'rentang leave hanya berisi Sabtu/Minggu';
        }

        return $rekaman;
    }

    /** @param list<string> $error */
    private function lembur(array $p, array &$error): array
    {
        $tanggal = $this->tanggal($p['tanggal'] ?? '', 'tanggal', $error);
        $durasi = $this->angka($p['durasi_jam'] ?? '', 'durasi_jam', $error, bolehNol: false);

        return $tanggal === null || $durasi === null ? [] : [[
            'overtime_date' => $tanggal->toDateString(), 'duration_hours' => $durasi, 'note' => $this->teks($p['keterangan'] ?? null),
        ]];
    }

    /** @param list<string> $error */
    private function periksaBentrok(JenisKehadiran $jenis, array $r, ?int $abaikanId, array &$error): void
    {
        $emp = $r['employee_id'];
        $tgl = $r['attendance_date'] ?? $r['leave_date'] ?? $r['overtime_date'];

        if ($this->kunci->terkunci($emp, $tgl)) {
            $error[] = "tanggal {$tgl} masuk periode payroll yang sudah final";

            return;
        }
        if ($jenis === JenisKehadiran::Lembur) {
            return; // lembur boleh lebih dari satu per hari
        }

        $sendiri = $jenis === JenisKehadiran::Absensi ? 'A' : 'L';
        $lawan = $jenis === JenisKehadiran::Absensi ? 'L' : 'A';
        $model = $jenis === JenisKehadiran::Absensi ? Attendance::class : Leave::class;
        $kolom = $jenis === JenisKehadiran::Absensi ? 'attendance_date' : 'leave_date';

        if (isset($this->dipakai["{$sendiri}|{$emp}|{$tgl}"])) {
            $error[] = "tanggal {$tgl} muncul lebih dari sekali di file";
        } elseif ($model::where('employee_id', $emp)->whereDate($kolom, $tgl)->when($abaikanId, fn ($q) => $q->whereKeyNot($abaikanId))->exists()) {
            $error[] = ($jenis === JenisKehadiran::Absensi ? 'sudah ada absensi' : 'sudah ada leave')." tanggal {$tgl}";
        }

        $bentrok = $jenis === JenisKehadiran::Absensi
            ? Leave::where('employee_id', $emp)->whereDate('leave_date', $tgl)->exists()
            : Attendance::where('employee_id', $emp)->whereDate('attendance_date', $tgl)->exists();
        if ($bentrok || isset($this->dipakai["{$lawan}|{$emp}|{$tgl}"])) {
            $error[] = 'bentrok: karyawan sudah '.($jenis === JenisKehadiran::Absensi ? 'leave' : 'hadir')." tanggal {$tgl}";
        }

        $this->dipakai["{$sendiri}|{$emp}|{$tgl}"] = true;
    }

    /** @param list<string> $error */
    private function tanggal(string $v, string $nama, array &$error): ?CarbonImmutable
    {
        try {
            $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? CarbonImmutable::createFromFormat('!Y-m-d', $v, 'Asia/Jakarta') : false;
        } catch (\Throwable) {
            $d = false; // Carbon melempar exception untuk format tidak valid
        }
        if ($d === false || $d->format('Y-m-d') !== $v) {
            $error[] = "{$nama} harus berformat YYYY-MM-DD".($v === '' ? '' : " (isi: {$v})");

            return null;
        }

        return $d;
    }

    /** @param list<string> $error */
    private function jam(string $v, string $nama, array &$error): ?string
    {
        if ($v === '') {
            return null;
        }
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
            $error[] = "{$nama} harus berformat HH:MM (isi: {$v})";

            return null;
        }

        return $v.':00';
    }

    /**
     * Angka desimal maksimal 2 digit di belakang koma (string, tanpa float), 0..24.
     *
     * @param  list<string>  $error
     */
    private function angka(string $v, string $nama, array &$error, bool $bolehNol, ?string $bawaan = null): ?string
    {
        if ($v === '' && $bawaan !== null) {
            return $bawaan;
        }
        $v = str_replace(',', '.', $v);
        if (! preg_match('/^\d{1,2}(\.\d{1,2})?$/', $v) || bccomp($v, '24', 2) > 0 || (! $bolehNol && bccomp($v, '0', 2) <= 0)) {
            $error[] = "{$nama} harus angka ".($bolehNol ? '0' : 'lebih dari 0').' sampai 24 (maks. 2 desimal)';

            return null;
        }

        return bcadd($v, '0', 2);
    }

    private function teks(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : null;

        return $v === null || $v === '' ? null : mb_substr($v, 0, 255);
    }
}
