<?php

namespace App\Connectors;

use App\Models\SumberData;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Konektor generik untuk API JSON (umumnya bertoken) milik OPD atau instansi lain.
 * Setiap baris dipetakan menjadi indikator: (metrik, kode wilayah, tanggal, nilai).
 *
 * Contoh opsi:
 * {
 *   "path": "/v1/stunting", "query": {"tahun": 2026},
 *   "records_path": "data",
 *   "paginasi": {"param": "page", "mulai": 1, "maks_halaman": 50},
 *   "peta": {"metrik": "=prevalensi_stunting", "wilayah_kode": "kode_desa", "tanggal": "periode", "nilai": "persen"},
 *   "meta": ["jumlah_balita"]
 * }
 * Nilai yang diawali "=" adalah konstanta; selain itu jalur kolom (notasi titik).
 */
class HttpJsonConnector implements Connector
{
    public function sync(SumberData $s, callable $log): int
    {
        $http = HttpFactory::for($s);
        $path = (string) $s->opsi('path', '');
        $query = (array) $s->opsi('query', []);
        $peta = (array) $s->opsi('peta', []);
        foreach (['metrik', 'wilayah_kode', 'nilai'] as $wajib) {
            if (empty($peta[$wajib])) {
                throw new RuntimeException("Opsi peta.$wajib belum diisi untuk konektor {$s->kode}.");
            }
        }
        $pag = $s->opsi('paginasi');
        $page = (int) data_get($pag, 'mulai', 1);
        $maxPages = $pag ? (int) data_get($pag, 'maks_halaman', 50) : 1;
        $rows = [];
        $now = now();

        for ($n = 0; $n < $maxPages; $n++) {
            $q = $query + ($pag ? [data_get($pag, 'param', 'page') => $page + $n] : []);
            $resp = $http->get($path, $q);
            if ($resp->status() === 401 || $resp->status() === 403) {
                throw new RuntimeException('Token ditolak oleh sumber (HTTP '.$resp->status().'). Perbarui token di panel admin.');
            }
            if (! $resp->successful()) {
                throw new RuntimeException('Sumber mengembalikan HTTP '.$resp->status().'.');
            }
            $records = data_get($resp->json(), $s->opsi('records_path', 'data'), []);
            if (! is_array($records) || ! $records) {
                break;
            }
            foreach ($records as $r) {
                $kode = preg_replace('/\D/', '', (string) $this->ambil($r, $peta['wilayah_kode']));
                $metrik = (string) $this->ambil($r, $peta['metrik']);
                if ($kode === '' || $metrik === '') {
                    continue;
                }
                $tgl = isset($peta['tanggal']) ? $this->ambil($r, $peta['tanggal']) : null;
                $nilai = $this->ambil($r, $peta['nilai']);
                $rows[] = [
                    'sumber_data_id' => $s->id,
                    'metrik' => mb_substr($metrik, 0, 80),
                    'wilayah_kode' => $kode,
                    'tanggal' => $tgl ? date('Y-m-d', strtotime((string) $tgl)) : null,
                    'nilai' => is_numeric($nilai) ? (float) $nilai : null,
                    'meta' => json_encode(array_intersect_key((array) $r, array_flip((array) $s->opsi('meta', [])))),
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            if (! $pag) {
                break;
            }
        }
        $log(count($rows).' baris indikator dibaca');

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 500) as $c) {
                DB::table('indikator')->upsert($c, ['sumber_data_id', 'metrik', 'wilayah_kode', 'tanggal'], ['nilai', 'meta', 'updated_at']);
            }
        });

        return count($rows);
    }

    private function ambil(array $r, string $spec): mixed
    {
        return str_starts_with($spec, '=') ? substr($spec, 1) : data_get($r, $spec);
    }
}
