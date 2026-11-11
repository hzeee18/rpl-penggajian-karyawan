<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Import;

/** Jenis data kehadiran payroll. Kolom CSV tetap per jenis (PAYROLL_SPEC.md "Import CSV"). */
enum JenisKehadiran: string
{
    case Absensi = 'attendances';
    case Leave = 'leaves';
    case Lembur = 'overtimes';

    /** @return list<string> */
    public function kolom(): array
    {
        return match ($this) {
            self::Absensi => ['nik', 'tanggal', 'jam_masuk', 'jam_keluar', 'jam_terlambat'],
            self::Leave => ['nik', 'kode_tipe', 'tanggal_mulai', 'tanggal_selesai', 'keterangan'],
            self::Lembur => ['nik', 'tanggal', 'durasi_jam', 'keterangan'],
        };
    }

    /** @return list<string> contoh baris template */
    public function contoh(): array
    {
        return match ($this) {
            self::Absensi => ['K0001', '2026-11-02', '08:00', '17:00', '0'],
            self::Leave => ['K0001', 'cuti', '2026-11-10', '2026-11-11', 'Acara keluarga'],
            self::Lembur => ['K0001', '2026-11-07', '3', 'Deploy aplikasi'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Absensi => 'Absensi',
            self::Leave => 'Leave',
            self::Lembur => 'Lembur',
        };
    }

    /** Segmen URL dan url menu (pay-...) */
    public function slug(): string
    {
        return match ($this) {
            self::Absensi => 'absensi',
            self::Leave => 'leave',
            self::Lembur => 'lembur',
        };
    }

    public static function dariSlug(string $slug): self
    {
        foreach (self::cases() as $c) {
            if ($c->slug() === $slug) {
                return $c;
            }
        }

        throw new \InvalidArgumentException("Jenis kehadiran tidak dikenal: {$slug}");
    }
}
