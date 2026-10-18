<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pasar extends Model
{
    protected $table = 'pasar';
    public $incrementing = false;
    protected $guarded = [];
    protected $casts = ['lat' => 'float', 'lng' => 'float'];
}
