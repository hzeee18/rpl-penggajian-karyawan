<?php

namespace App\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class LogAktivitas extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'log_aktivitas';
    protected $fillable = ['user_id', 'aktivitas', 'modul', 'created_at'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Catat aktivitas user yang sedang login. */
    public static function catat(string $aktivitas, string $modul): void
    {
        static::create(['user_id' => auth()->id(), 'aktivitas' => $aktivitas, 'modul' => $modul]);
    }
}
