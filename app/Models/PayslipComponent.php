<?php

namespace App\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayslipComponent extends Model
{
    use BelongsToCompany;

    /** Dasar bayar yang dihitung otomatis untuk semua karyawan (tidak perlu dipasang di kontrak). */
    public const DASAR_OTOMATIS = ['gaji_pokok', 'alfa', 'pajak', 'semua'];

    public const DASAR_BAYAR = [
        'gaji_pokok' => 'Gaji pokok (sesuai kontrak)',
        'bulanan' => 'Tetap per bulan',
        'harian' => 'Per hari (sesuai status kehadiran)',
        'lembur' => 'Per jam lembur',
        'thr' => 'THR (prorata masa kerja)',
        'rumus' => 'Pakai rumus (dipasang di kontrak)',
        'semua' => 'Pakai rumus (semua karyawan)',
        'alfa' => 'Per hari alfa (otomatis)',
        'pajak' => 'PPh 21 TER (otomatis)',
    ];

    /** Status kehadiran yang bisa dipilih di "Dihitung per". */
    public const STATUS_HARI = ['hadir' => 'Hadir', 'sakit' => 'Sakit', 'izin' => 'Izin', 'cuti' => 'Cuti'];

    protected $table = 'payslip_component';
    protected $fillable = ['kode', 'nama', 'jenis', 'tipe_nilai', 'nilai_default', 'dasar_bayar', 'rumus', 'dihitung_per', 'status_berlaku', 'kena_pajak'];
    protected $casts = ['kena_pajak' => 'boolean', 'nilai_default' => 'float'];

    /** Daftar status yang dibayar, mis. ['hadir', 'sakit']. */
    public function statusDibayar(): array
    {
        return array_values(array_filter(explode(',', (string) $this->dihitung_per)));
    }

    /** Teks nilai ringkas untuk tabel, mis. "15% GP", "1/173 GP", "Kontrak". */
    public function nilaiRingkas(): string
    {
        if (filled($this->rumus)) {
            return $this->rumus;
        }

        return match ($this->dasar_bayar) {
            'gaji_pokok' => 'Kontrak',
            'lembur' => '1/173 GP',
            'alfa' => 'GP ÷ HK',
            'thr' => ($this->nilai_default + 0 ?: 1) . '× GP',
            'pajak' => 'TER',
            default => $this->tipe_nilai === 'persen' ? ($this->nilai_default + 0) . '% GP' : 'Rp ' . jt($this->nilai_default),
        };
    }

    /** Keterangan kecil di bawah nama komponen. */
    public function keterangan(): string
    {
        if ($this->dasar_bayar === 'pajak') return 'Tarif TER sesuai PTKP';
        if ($this->dasar_bayar === 'gaji_pokok') return 'Sesuai kontrak';
        if (filled($this->rumus)) return $this->isOtomatis() ? 'Otomatis untuk semua karyawan' : 'Dipasang di kontrak';

        return match ($this->dasar_bayar) {
            'gaji_pokok' => 'Sesuai kontrak',
            'harian' => 'Per hari ' . implode(', ', $this->statusDibayar()),
            'lembur' => '1,5× jam pertama, 2× berikut',
            'thr' => 'Prorata masa kerja',
            'alfa' => 'Per hari alfa',
            'pajak' => 'Tarif TER sesuai PTKP',
            default => 'Tetap per bulan',
        };
    }

    /** Komponen sistem yang rumusnya tidak bisa diubah (gaji pokok dari kontrak, PPh21 dari TER). */
    public function isSistem(): bool
    {
        return in_array($this->dasar_bayar, ['gaji_pokok', 'pajak']);
    }

    public function isOtomatis(): bool
    {
        return in_array($this->dasar_bayar, self::DASAR_OTOMATIS);
    }

    /** Komponen yang bisa dipasang di kontrak. */
    public function scopeUntukKontrak($q)
    {
        return $q->where('status_berlaku', 'aktif')->whereNotIn('dasar_bayar', self::DASAR_OTOMATIS);
    }
}
