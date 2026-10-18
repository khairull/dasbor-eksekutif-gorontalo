<?php

namespace App\Connectors;

use App\Models\SumberData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Konektor SP2KP (Kemendag) — harga rata-rata harian kebutuhan pokok.
 *
 * Endpoint publik: GET report/api/average-price-public?kode_provinsi=75&tanggal=YYYY-MM-DD&take=N
 * Level data: 1 provinsi, 2 kab/kota, 3 pasar (tingkat terkecil).
 * Data petugas (creator/updater: nama, NIP, email) dibuang dan tidak disimpan.
 */
class Sp2kpConnector implements Connector
{
    public function sync(SumberData $s, callable $log): int
    {
        $prov = (string) $s->opsi('kode_provinsi', '75');
        $first = $s->terakhir_sinkron === null;
        $days = (int) ($first ? $s->opsi('hari_awal', 31) : $s->opsi('hari_inkremental', 7));
        $take = (int) $s->opsi('take', 10000);
        $tz = $s->opsi('zona_waktu', 'Asia/Makassar');
        $http = HttpFactory::for($s);

        $end = $s->opsi('sampai') ? Carbon::parse($s->opsi('sampai'), $tz) : Carbon::now($tz);
        $komoditas = [];
        $pasar = [];
        $harga = [];
        $hariAda = 0;

        for ($i = 0; $i < $days; $i++) {
            $tgl = $end->copy()->subDays($i)->toDateString();
            $resp = $http->get('report/api/average-price-public', ['kode_provinsi' => $prov, 'tanggal' => $tgl, 'take' => $take]);
            if (! $resp->successful()) {
                throw new RuntimeException("SP2KP menolak permintaan tanggal $tgl ({$resp->status()}).");
            }
            $data = (array) data_get($resp->json(), 'data', []);
            if (! $data) {
                continue; // hari libur / belum ada input
            }
            $hariAda++;
            $total = (int) data_get($resp->json(), 'totalCount', count($data));
            if ($total > count($data)) {
                $log("Peringatan: $tgl hanya terbaca ".count($data)." dari $total baris; naikkan opsi take.");
            }
            foreach ($data as $r) {
                $vid = (int) ($r['variant_id'] ?? 0);
                if (! $vid || ! isset($r['harga'])) {
                    continue;
                }
                $v = $r['variant'] ?? [];
                $komoditas[$vid] = [
                    'variant_id' => $vid,
                    'nama' => (string) ($v['nama'] ?? "Varian $vid"),
                    'kelompok' => data_get($r, 'komoditas.nama'),
                    'satuan' => data_get($v, 'satuan.nama'),
                    'urutan' => (int) ($v['order_public'] ?? 999) ?: 999,
                ];
                $level = (int) ($r['level'] ?? 0);
                if ($level === 3 && ! empty($r['pasar_id'])) {
                    $p = $r['pasar'] ?? [];
                    $pasar[(int) $r['pasar_id']] = [
                        'id' => (int) $r['pasar_id'],
                        'nama' => (string) ($p['nama'] ?? 'Pasar '.$r['pasar_id']),
                        'kab_kode' => (string) ($p['kode_kab_kota'] ?? $r['kode_kab_kota'] ?? ''),
                        'kec_kode' => $p['kode_kecamatan'] ?? null,
                        'desa_kode' => $p['kode_kelurahan'] ?? null,
                        'lat' => isset($p['lat']) ? (float) $p['lat'] : null,
                        'lng' => isset($p['lon']) ? (float) $p['lon'] : null,
                    ];
                    $node = 'P'.$r['pasar_id'];
                } elseif ($level === 2) {
                    $node = (string) $r['kode_kab_kota'];
                } elseif ($level === 1) {
                    $node = $prov;
                } else {
                    continue;
                }
                $harga["$tgl|$node|$vid"] = ['tanggal' => $tgl, 'node' => $node, 'level' => $level, 'variant_id' => $vid, 'harga' => (int) round((float) $r['harga'])];
            }
        }
        $log("Hari dengan data: $hariAda dari $days; ".count($harga).' titik harga, '.count($pasar).' pasar, '.count($komoditas).' komoditas');
        if (! $harga) {
            throw new RuntimeException('Tidak ada data harga dalam rentang tanggal yang diminta.');
        }

        $now = now();
        DB::transaction(function () use ($komoditas, $pasar, $harga, $now) {
            DB::table('komoditas')->upsert(array_map(fn ($k) => $k + ['created_at' => $now, 'updated_at' => $now], array_values($komoditas)),
                ['variant_id'], ['nama', 'kelompok', 'satuan', 'urutan', 'updated_at']);
            if ($pasar) {
                DB::table('pasar')->upsert(array_map(fn ($p) => $p + ['created_at' => $now, 'updated_at' => $now], array_values($pasar)),
                    ['id'], ['nama', 'kab_kode', 'kec_kode', 'desa_kode', 'lat', 'lng', 'updated_at']);
            }
            foreach (array_chunk(array_values($harga), 500) as $c) {
                DB::table('harga_harian')->upsert($c, ['tanggal', 'node', 'variant_id'], ['harga', 'level']);
            }
        });

        return count($harga);
    }
}
