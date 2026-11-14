<?php

namespace App\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Kontrak extends Model
{
    use BelongsToCompany;

    protected $table = 'kontrak';
    protected $fillable = ['nomor', 'karyawan_id', 'kontrak_sebelumnya_id', 'jenis', 'tgl_mulai', 'tgl_akhir', 'gaji_pokok', 'status_approval', 'is_active'];
    protected $casts = ['tgl_mulai' => \App\Casts\Tanggal::class, 'tgl_akhir' => \App\Casts\Tanggal::class, 'is_active' => 'boolean', 'gaji_pokok' => 'float'];

    /** Batas waktu tombol "Perbarui kontrak" muncul: 3 bulan sebelum kontrak berakhir. */
    public const BULAN_PERBARUI = 3;

    protected static function booted(): void
    {
        // nomor baru setiap kontrak dibuat (termasuk perpanjangan)
        static::creating(function (Kontrak $k) {
            $k->nomor ??= static::buatNomor($k);
        });
    }

    /** Format: PKWT/IT/0012/XI/2026 (jenis / kode departemen / urutan per tahun / bulan romawi / tahun mulai). */
    public static function buatNomor(Kontrak $k): string
    {
        $mulai = \Carbon\Carbon::parse($k->tgl_mulai ?? now());
        $dept = Karyawan::with('departemen')->find($k->karyawan_id)?->departemen?->kode ?? 'UMUM';
        $tahun = $mulai->year;
        $urut = static::where('nomor', 'like', "%/{$tahun}")->get('nomor')
            ->map(fn ($r) => (int) (explode('/', $r->nomor)[2] ?? 0))->max() + 1;
        $romawi = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$mulai->month - 1];

        return sprintf('%s/%s/%04d/%s/%d', $k->jenis ?: 'PKWT', $dept, $urut, $romawi, $tahun);
    }

    /** Beri nomor ke kontrak lama yang belum punya, urut tanggal mulai; sekalian sambungkan rantai perpanjangan. */
    public static function isiNomorKosong(): void
    {
        foreach (static::whereNull('nomor')->orderBy('tgl_mulai')->orderBy('id')->get() as $k) {
            $k->forceFill(['nomor' => static::buatNomor($k)])->saveQuietly();
        }
        static::orderBy('tgl_mulai')->orderBy('id')->get()->groupBy('karyawan_id')->each(function ($daftar) {
            $daftar->values()->each(function ($k, $i) use ($daftar) {
                if ($i > 0 && ! $k->kontrak_sebelumnya_id) {
                    $k->forceFill(['kontrak_sebelumnya_id' => $daftar[$i - 1]->id])->saveQuietly();
                }
            });
        });
    }

    /** Nomor tampil (fallback untuk data lama). */
    public function getKodeAttribute(): string
    {
        return $this->nomor ?: 'KT-' . str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }

    public function sebelumnya()
    {
        return $this->belongsTo(Kontrak::class, 'kontrak_sebelumnya_id');
    }

    public function perpanjangan()
    {
        return $this->hasOne(Kontrak::class, 'kontrak_sebelumnya_id')->latest('id');
    }

    /** Sisa hari sampai kontrak berakhir (null untuk PKWTT). */
    public function sisaHari(): ?int
    {
        return $this->tgl_akhir ? (int) now()->startOfDay()->diffInDays($this->tgl_akhir, false) : null;
    }

    /** Kontrak aktif PKWT yang tinggal <= 3 bulan dan belum punya perpanjangan yang masih jalan. */
    public function bisaDiperbarui(): bool
    {
        if (! $this->is_active || $this->jenis !== 'PKWT' || ! $this->tgl_akhir) {
            return false;
        }
        if ($this->tgl_akhir->lt(now()->startOfDay()) || $this->tgl_akhir->gt(now()->addMonthsNoOverflow(self::BULAN_PERBARUI)->endOfDay())) {
            return false;
        }

        return ! static::where('kontrak_sebelumnya_id', $this->id)->where('status_approval', '!=', 'ditolak')->exists();
    }

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function komponen()
    {
        return $this->hasMany(KontrakKomponen::class);
    }

    public function approvals()
    {
        return $this->hasMany(Approval::class, 'ref_id')->where('jenis_ref', 'kontrak')->latest('id');
    }

    /**
     * Aktifkan kontrak yang sudah disetujui dan sudah mulai berlaku hari ini
     * (kontrak yang disetujui untuk tanggal mendatang baru aktif saat tanggalnya tiba).
     */
    public static function sinkronAktif(): void
    {
        $hariIni = now()->toDateString();
        $berlaku = static::where('status_approval', 'disetujui')->where('tgl_mulai', '<=', $hariIni)
            ->orderBy('tgl_mulai')->orderBy('id')->get()->keyBy('karyawan_id'); // yang terakhir mulai menang
        foreach ($berlaku as $karyawanId => $kontrak) {
            if (! $kontrak->is_active) {
                static::where('karyawan_id', $karyawanId)->update(['is_active' => false]);
                $kontrak->update(['is_active' => true]);
            }
        }
    }

    public function bisaDiubah(): bool
    {
        return in_array($this->status_approval, ['draft', 'ditolak']);
    }
}
