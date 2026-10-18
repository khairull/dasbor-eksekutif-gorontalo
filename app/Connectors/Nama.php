<?php

namespace App\Connectors;

use Illuminate\Support\Str;

/** Merapikan penulisan nama wilayah dari huruf kapital semua. */
class Nama
{
    public static function rapikan(string $s): string
    {
        $s = Str::title(Str::lower(trim($s)));
        // angka romawi tetap kapital: Dembe Ii -> Dembe II
        return preg_replace_callback('/\b(I{1,3}|Iv|V|Vi{1,3}|Ix|X)\b/', fn ($m) => strtoupper($m[1]), $s);
    }

    public static function kabKota(string $s): string
    {
        $s = self::rapikan($s);
        return preg_replace('/^Kabupaten\s+/', 'Kab. ', $s);
    }
}
