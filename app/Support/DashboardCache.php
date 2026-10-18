<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache berversi: setiap sinkron sukses menaikkan versi sehingga semua respons API diperbarui.
 * Nilai disimpan sebagai JSON (bukan objek PHP terserialisasi) agar aman dari pembatasan
 * serializable_classes di Laravel dan tidak bergantung pada kelas Collection.
 */
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
        $json = Cache::remember('dasbor:j:'.self::version().':'.$key, now()->addHours(12),
            fn () => json_encode($fn(), JSON_UNESCAPED_UNICODE));

        return json_decode($json, true);
    }
}
