<?php

namespace App\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use BelongsToCompany;

    public const ADMIN = 'Admin';
    public const HR = 'HR';
    public const MANAGER = 'Manager';
    public const KARYAWAN = 'Karyawan';

    protected $fillable = ['nama'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function privileges()
    {
        return $this->hasMany(Privilege::class);
    }
}
