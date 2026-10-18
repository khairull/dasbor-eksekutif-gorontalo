<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wilayah extends Model
{
    protected $table = 'wilayah';
    protected $primaryKey = 'kode';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['lat' => 'float', 'lng' => 'float'];

    public static function levelOf(string $kode): string
    {
        return match (strlen($kode)) { 2 => 'prov', 4 => 'kab', 6 => 'kec', default => 'desa' };
    }
}
