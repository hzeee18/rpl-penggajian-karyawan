<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportExcel extends Model
{
    protected $table = 'import_excel';
    protected $fillable = ['user_id', 'jenis', 'nama_file', 'status', 'baris', 'baris_error', 'catatan'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
