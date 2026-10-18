<?php

namespace App\Support;

use App\Connectors\BspsConnector;
use App\Connectors\Connector;
use App\Connectors\HttpJsonConnector;
use App\Connectors\Sp2kpConnector;
use App\Models\RiwayatSinkron;
use App\Models\SumberData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncRunner
{
    public const DRIVERS = [
        'bsps' => BspsConnector::class,
        'sp2kp' => Sp2kpConnector::class,
        'http_json' => HttpJsonConnector::class,
    ];

    public static function connector(string $driver): Connector
    {
        $cls = self::DRIVERS[$driver] ?? throw new \InvalidArgumentException("Driver tidak dikenal: $driver");

        return app($cls);
    }

    /** @param  callable(string):void|null  $out */
    public static function run(SumberData $s, ?callable $out = null): RiwayatSinkron
    {
        $lock = Cache::lock('sinkron:'.$s->kode, 3600);
        $log = [];
        $say = function (string $m) use (&$log, $out) {
            $log[] = now()->format('H:i:s').' '.$m;
            $out && $out($m);
        };

        $run = RiwayatSinkron::create(['sumber_data_id' => $s->id, 'status' => 'berjalan', 'mulai' => now()]);
        if (! $lock->get()) {
            $run->update(['status' => 'gagal', 'pesan' => 'Sinkronisasi lain untuk sumber ini masih berjalan.', 'selesai' => now()]);

            return $run;
        }

        try {
            @set_time_limit(0);
            $rows = self::connector($s->driver)->sync($s, $say);
            $s->forceFill(['terakhir_sinkron' => now(), 'minta_sinkron' => false])->save();
            $run->update(['status' => 'sukses', 'baris' => $rows, 'pesan' => implode("\n", $log), 'selesai' => now()]);
            DashboardCache::bump();
        } catch (Throwable $e) {
            Log::warning("Sinkron {$s->kode} gagal: ".$e->getMessage());
            $say('GAGAL: '.$e->getMessage());
            $s->forceFill(['minta_sinkron' => false])->save();
            $run->update(['status' => 'gagal', 'pesan' => implode("\n", $log), 'selesai' => now()]);
        } finally {
            $lock->release();
        }

        return $run;
    }
}
