<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Indikator extends Model
{
    protected $table = 'indikator';
    protected $guarded = [];
    protected $casts = ['meta' => 'array', 'nilai' => 'float', 'tanggal' => 'date:Y-m-d'];
}
