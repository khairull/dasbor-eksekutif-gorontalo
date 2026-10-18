<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SumberData extends Model
{
    protected $table = 'sumber_data';
    protected $guarded = [];
    protected $hidden = ['token'];
    protected $casts = [
        'token' => 'encrypted',   // dienkripsi dengan APP_KEY, tidak pernah dikirim ke peramban
        'opsi' => 'array',
        'aktif' => 'boolean',
        'minta_sinkron' => 'boolean',
        'terakhir_sinkron' => 'datetime',
    ];

    public function riwayat(): HasMany
    {
        return $this->hasMany(RiwayatSinkron::class)->latest('mulai');
    }

    public function opsi(string $key, mixed $default = null): mixed
    {
        return data_get($this->opsi ?? [], $key, $default);
    }
}
