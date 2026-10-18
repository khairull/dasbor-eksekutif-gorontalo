<?php

namespace App\Console\Commands;

use App\Support\DashboardCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mengisi database dari snapshot JSON (hasil tarikan awal), agar dasbor langsung berisi
 * walau sinkron pertama belum berjalan. Aman diulang: memakai upsert.
 */
class ImportSnapshotCommand extends Command
{
    protected $signature = 'dasbor:impor-snapshot {path? : file JSON snapshot}';
    protected $description = 'Impor snapshot data awal (wilayah, BSPS, harga SP2KP)';

    public function handle(): int
    {
        $path = $this->argument('path') ?: database_path('snapshot/gorontalo-2026-10-18.json');
        $d = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $now = now();
        $prov = $d['meta']['provinsi']['id'];

        DB::transaction(function () use ($d, $now, $prov) {
            $wil = [['kode' => $prov, 'nama' => $d['meta']['provinsi']['name'], 'level' => 'prov', 'induk' => null]];
            foreach ($d['kab'] as $k) { $wil[] = ['kode' => $k['id'], 'nama' => $k['name'], 'level' => 'kab', 'induk' => $prov]; }
            foreach ($d['kec'] as $k) { $wil[] = ['kode' => $k['id'], 'nama' => $k['name'], 'level' => 'kec', 'induk' => $k['kab']]; }
            foreach ($d['desa'] as $k) { $wil[] = ['kode' => $k['id'], 'nama' => $k['name'], 'level' => 'desa', 'induk' => $k['kec']]; }
            foreach (array_chunk($wil, 500) as $c) {
                DB::table('wilayah')->upsert(array_map(fn ($w) => $w + ['created_at' => $now, 'updated_at' => $now], $c), ['kode'], ['nama', 'level', 'induk', 'updated_at']);
            }

            $tahun = (int) $d['meta']['sources']['bsps']['tahun'];
            $rows = [];
            foreach ($d['bsps'] as $kode => $v) {
                $rows[] = ['tahun' => $tahun, 'desa_kode' => (string) $kode, 'total' => $v[0], 'p0' => $v[1], 'p30' => $v[2], 'p100' => $v[3], 'p_lain' => $v[4], 'lat' => $v[5], 'lng' => $v[6], 'synced_at' => $now];
            }
            DB::table('bsps_desa')->upsert($rows, ['tahun', 'desa_kode'], ['total', 'p0', 'p30', 'p100', 'p_lain', 'lat', 'lng', 'synced_at']);

            $sp = $d['sp2kp'];
            DB::table('komoditas')->upsert(array_map(fn ($v) => ['variant_id' => $v['id'], 'nama' => $v['name'], 'kelompok' => $v['group'], 'urutan' => $v['order'], 'created_at' => $now, 'updated_at' => $now], $sp['variants']),
                ['variant_id'], ['nama', 'kelompok', 'urutan', 'updated_at']);
            DB::table('pasar')->upsert(array_map(fn ($p) => ['id' => $p['id'], 'nama' => $p['name'], 'kab_kode' => $p['kab'], 'lat' => $p['lat'], 'lng' => $p['lng'], 'created_at' => $now, 'updated_at' => $now], $sp['pasar']),
                ['id'], ['nama', 'kab_kode', 'lat', 'lng', 'updated_at']);
            $h = [];
            foreach ($sp['series'] as $node => $vars) {
                $level = $node === $prov ? 1 : (str_starts_with($node, 'P') ? 3 : 2);
                foreach ($vars as $vid => $arr) {
                    foreach ($arr as $i => $harga) {
                        if ($harga === null) continue;
                        $h[] = ['tanggal' => $sp['dates'][$i], 'node' => $node, 'level' => $level, 'variant_id' => (int) $vid, 'harga' => (int) $harga];
                    }
                }
            }
            foreach (array_chunk($h, 500) as $c) {
                DB::table('harga_harian')->upsert($c, ['tanggal', 'node', 'variant_id'], ['harga', 'level']);
            }
            $this->info(sprintf('Wilayah %d, BSPS %d desa, harga %d titik', count($wil), count($rows), count($h)));
        });

        // batas kab/kota disimpan sebagai berkas statis
        @mkdir(storage_path('app/geo'), 0755, true);
        file_put_contents(storage_path('app/geo/kab-'.$prov.'.geojson'), json_encode($d['geo']));
        DashboardCache::bump();

        return self::SUCCESS;
    }
}
