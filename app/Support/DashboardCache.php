<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/** Cache berversi: setiap sinkron sukses menaikkan versi sehingga semua respons API diperbarui. */
class DashboardCache
{
    public static function version(): int
    {
        return (int) Cache::get('dasbor:versi', 1);
    }

    public static function bump(): void
    {
        Cache::forever('dasbor:versi', self::version() + 1);
    }

    public static function remember(string $key, Closure $fn): mixed
    {
        return Cache::remember('dasbor:'.self::version().':'.$key, now()->addHours(12), $fn);
    }
}
