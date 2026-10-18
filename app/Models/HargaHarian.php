<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HargaHarian extends Model
{
    protected $table = 'harga_harian';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['tanggal' => 'date:Y-m-d'];
}
