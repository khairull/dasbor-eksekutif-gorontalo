<?php

namespace App\Connectors;

use App\Models\SumberData;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Konektor Peta Sebaran Penerima BSPS (Kementerian PKP).
 *
 * Endpoint publik (dipakai halaman pkp.go.id/cek-penerima-bsps):
 *   filter/tahun | filter/kabkota?provinsi_id | filter/kecamatan?kabkota_id | filter/desa?kecamatan_id
 *   markers?tahun&provinsi_id&kabkota_id&kecamatan_id[&desa_id][&progres_fisik]
 *
 * Yang disimpan hanya agregat per desa: jumlah unit per progres fisik dan titik tengah rata-rata.
 * ID penerima dan koordinat rumah tidak pernah disimpan.
 */
class BspsConnector implements Connector
{
    private const PROGRES = ['0' => 'p0', '30' => 'p30', '100' => 'p100'];

    public function sync(SumberData $s, callable $log): int
    {
        $prov = (string) $s->opsi('provinsi_id', '75');
        $conc = max(1, (int) $s->opsi('konkurensi', 6));
        [$latMin, $latMax, $lngMin, $lngMax] = $s->opsi('bbox', [-1.5, 1.5, 121.0, 124.5]);
        $http = HttpFactory::for($s);

        $tahun = $s->opsi('tahun') ?: data_get($this->json($http->get('filter/tahun')), 'default');
        if (! $tahun) {
            throw new RuntimeException('Tahun anggaran BSPS tidak tersedia dari sumber.');
        }
        $log("Tahun anggaran $tahun, provinsi $prov");

        // 1) Hierarki wilayah
        $kabs = data_get($this->json($http->get('filter/kabkota', ['provinsi_id' => $prov])), 'data', []);
        if (! $kabs) {
            throw new RuntimeException('Daftar kab/kota kosong. Periksa provinsi_id atau perubahan endpoint.');
        }
        $kecs = $this->poolGet($s, $conc, array_map(fn ($k) => ['filter/kecamatan', ['kabkota_id' => $k['id']]], $kabs));
        $kecs = array_merge(...array_map(fn ($r) => data_get($r, 'data', []), $kecs));
        $desas = $this->poolGet($s, $conc, array_map(fn ($k) => ['filter/desa', ['kecamatan_id' => $k['id']]], $kecs));
        $desas = array_merge(...array_map(fn ($r) => data_get($r, 'data', []), $desas));
        $log(sprintf('Wilayah: %d kab/kota, %d kecamatan, %d desa/kelurahan', count($kabs), count($kecs), count($desas)));

        $now = now();
        $wil = [['kode' => $prov, 'nama' => 'Provinsi '.Nama::rapikan(data_get($kabs, '0.province_name', 'Gorontalo')), 'level' => 'prov', 'induk' => null, 'lat' => null, 'lng' => null, 'created_at' => $now, 'updated_at' => $now]];
        foreach ($kabs as $k) {
            $wil[] = ['kode' => (string) $k['id'], 'nama' => Nama::kabKota($k['name']), 'level' => 'kab', 'induk' => $prov, 'lat' => null, 'lng' => null, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach ($kecs as $k) {
            $wil[] = ['kode' => (string) $k['id'], 'nama' => Nama::rapikan($k['name']), 'level' => 'kec', 'induk' => (string) $k['regency_id'], 'lat' => null, 'lng' => null, 'created_at' => $now, 'updated_at' => $now];
        }
        $seen = [];
        foreach ($desas as $d) {
            $kode = (string) $d['id'];
            if (isset($seen[$kode]) || strlen($kode) < 10) {
                continue; // sumber kadang memuat entri ganda atau kode kosong
            }
            $seen[$kode] = true;
            $wil[] = ['kode' => $kode, 'nama' => Nama::rapikan($d['name']), 'level' => 'desa', 'induk' => (string) $d['district_id'], 'lat' => null, 'lng' => null, 'created_at' => $now, 'updated_at' => $now];
        }

        // 2) Status progres: per kecamatan x progres (hanya ID marker sementara di memori)
        $base = ['tahun' => $tahun, 'provinsi_id' => $prov];
        $reqs = [];
        foreach ($kecs as $k) {
            foreach (array_keys(self::PROGRES) as $p) {
                $reqs[] = ['markers', $base + ['kabkota_id' => $k['regency_id'], 'kecamatan_id' => $k['id'], 'progres_fisik' => $p], $p];
            }
        }
        $prog = [];
        foreach (array_chunk($reqs, 60) as $chunk) {
            $res = $this->poolGet($s, $conc, $chunk);
            foreach ($res as $i => $r) {
                foreach (data_get($r, 'data', []) as $m) {
                    $prog[$m['id']] = self::PROGRES[$chunk[$i][2]];
                }
            }
        }
        $log('Status progres terbaca untuk '.count($prog).' unit');

        // 3) Agregat per desa
        $kecById = [];
        foreach ($kecs as $k) {
            $kecById[(string) $k['id']] = (string) $k['regency_id'];
        }
        $rows = [];
        $desaReqs = [];
        foreach (array_keys($seen) as $kode) {
            $kec = substr($kode, 0, 6);
            $desaReqs[] = ['markers', $base + ['kabkota_id' => $kecById[$kec] ?? substr($kode, 0, 4), 'kecamatan_id' => $kec, 'desa_id' => $kode], $kode];
        }
        foreach (array_chunk($desaReqs, 60) as $chunk) {
            $res = $this->poolGet($s, $conc, $chunk);
            foreach ($res as $i => $r) {
                $markers = data_get($r, 'data', []);
                if (! $markers) {
                    continue;
                }
                $agg = ['p0' => 0, 'p30' => 0, 'p100' => 0, 'p_lain' => 0];
                $la = $lo = 0.0;
                $n = 0;
                foreach ($markers as $m) {
                    $agg[$prog[$m['id']] ?? 'p_lain']++;
                    $lat = (float) ($m['lat'] ?? 0);
                    $lng = (float) ($m['long'] ?? $m['lng'] ?? 0);
                    if ($lat >= $latMin && $lat <= $latMax && $lng >= $lngMin && $lng <= $lngMax) {
                        $la += $lat; $lo += $lng; $n++;
                    }
                }
                $rows[] = ['tahun' => (int) $tahun, 'desa_kode' => $chunk[$i][2], 'total' => count($markers)] + $agg + [
                    'lat' => $n ? round($la / $n, 6) : null, 'lng' => $n ? round($lo / $n, 6) : null, 'synced_at' => $now,
                ];
            }
        }
        unset($prog);

        // 4) Validasi total terhadap total provinsi dari sumber
        $total = array_sum(array_column($rows, 'total'));
        $provAll = $this->json($http->get('markers', $base));
        $srcTotal = count(data_get($provAll, 'data', []));
        $log("Total unit teragregasi $total; total sumber $srcTotal");
        if ($srcTotal > 0 && abs($srcTotal - $total) > max(5, $srcTotal * 0.02)) {
            throw new RuntimeException("Selisih total terlalu besar ($total vs $srcTotal). Data lama dipertahankan.");
        }

        DB::transaction(function () use ($wil, $rows, $tahun, $prov) {
            foreach (array_chunk($wil, 500) as $c) {
                DB::table('wilayah')->upsert($c, ['kode'], ['nama', 'level', 'induk', 'updated_at']);
            }
            DB::table('bsps_desa')->where('tahun', $tahun)->where('desa_kode', 'like', $prov.'%')->delete();
            foreach (array_chunk($rows, 500) as $c) {
                DB::table('bsps_desa')->insert($c);
            }
        });

        return count($rows);
    }

    /** Jalankan banyak GET paralel. $reqs = [[path, query, ...], ...] */
    private function poolGet(SumberData $s, int $conc, array $reqs): array
    {
        [$headers, $authQuery] = HttpFactory::authParts($s);
        $base = rtrim($s->base_url, '/').'/';
        $out = [];
        foreach (array_chunk($reqs, $conc, true) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk, $base, $headers, $authQuery) {
                $r = [];
                foreach ($chunk as $i => $q) {
                    $r[] = $pool->as((string) $i)->withHeaders($headers)->acceptJson()->timeout(60)
                        ->withUserAgent('DasborEksekutifGorontalo/1.0 (+data terbuka)')
                        ->get($base.$q[0], $q[1] + $authQuery);
                }
                return $r;
            });
            foreach ($chunk as $i => $q) {
                $resp = $responses[(string) $i] ?? null;
                if (! $resp instanceof \Illuminate\Http\Client\Response || ! $resp->successful()) {
                    // satu kali coba ulang serial
                    $resp = HttpFactory::for($s)->get($q[0], $q[1]);
                }
                $out[$i] = $this->json($resp);
            }
        }

        return $out;
    }

    private function json($resp): array
    {
        if (! $resp instanceof \Illuminate\Http\Client\Response || ! $resp->successful()) {
            $code = $resp instanceof \Illuminate\Http\Client\Response ? $resp->status() : 'koneksi';
            throw new RuntimeException("Permintaan ke sumber BSPS gagal ($code).");
        }

        return (array) $resp->json();
    }
}
