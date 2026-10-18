<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Komoditas extends Model
{
    protected $table = 'komoditas';
    protected $primaryKey = 'variant_id';
    public $incrementing = false;
    protected $guarded = [];
}
