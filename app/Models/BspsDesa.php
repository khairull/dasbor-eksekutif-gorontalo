<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BspsDesa extends Model
{
    protected $table = 'bsps_desa';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['lat' => 'float', 'lng' => 'float', 'synced_at' => 'datetime'];
}
