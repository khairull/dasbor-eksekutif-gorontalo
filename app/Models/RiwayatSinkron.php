<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiwayatSinkron extends Model
{
    protected $table = 'riwayat_sinkron';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['mulai' => 'datetime', 'selesai' => 'datetime'];
}
