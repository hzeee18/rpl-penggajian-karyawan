<?php

namespace App\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Notifikasi extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'notifikasi';
    protected $fillable = ['user_id', 'judul', 'pesan', 'jenis_ref', 'ref_id', 'is_read'];
    protected $casts = ['is_read' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tandaiDibaca(): void
    {
        $this->update(['is_read' => true]);
    }

    /** Kirim notifikasi ke satu atau banyak user. */
    public static function kirim($userIds, string $judul, string $pesan, ?string $jenisRef = null, ?int $refId = null): void
    {
        foreach (collect($userIds)->filter()->unique() as $id) {
            static::create(['user_id' => $id, 'judul' => $judul, 'pesan' => $pesan, 'jenis_ref' => $jenisRef, 'ref_id' => $refId]);
        }
    }

    /** Halaman tujuan saat notifikasi diklik. */
    public function link(): string
    {
        return match ($this->jenis_ref) {
            // modul lama dihapus (AUDIT.md §68): notifikasi lama diarahkan ke halaman modul payroll yang setara
            'approval' => route('pay.approval.index'),
            'kontrak' => route('pay.kontrak.index'),
            'payroll' => route('pay.run.index'),
            'payslip' => route('pay.slip.index'),
            'lembur' => route('pay.lembur.index'),
            'user' => route('users.index'),
            'karyawan' => route('users.create', ['karyawan_id' => $this->ref_id]),
            default => route('notifikasi.index'),
        };
    }
}
