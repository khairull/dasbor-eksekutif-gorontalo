<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SumberData;
use App\Support\DashboardCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DasborController extends Controller
{
    private const PROV = '75';

    /** Paket lengkap untuk dasbor (satu panggilan, ter-cache, mendukung ETag). */
    public function bundle(Request $r): JsonResponse
    {
        $hari = min(120, max(7, (int) $r->query('hari', 35)));
        $data = DashboardCache::remember("bundle:$hari", fn () => [
            'meta' => $this->meta(),
            ...$this->wilayahLists(),
            'bsps' => $this->bspsMap(),
            'sp2kp' => $this->hargaBundle($hari),
            'geo' => $this->geo(),
        ]);

        return $this->etag($r, $data);
    }

    public function status(): JsonResponse
    {
        return response()->json(['versi' => DashboardCache::version(), 'sumber' => $this->sumber()]);
    }

    /** Anak dari suatu wilayah. ?induk=75 (default) */
    public function wilayah(Request $r): JsonResponse
    {
        $induk = (string) $r->query('induk', self::PROV);
        $rows = DashboardCache::remember("wil:$induk", fn () => DB::table('wilayah')->where('induk', $induk)->orderBy('kode')->get(['kode', 'nama', 'level']));

        return response()->json(['induk' => $induk, 'data' => $rows]);
    }

    /** Ringkasan BSPS untuk satu wilayah beserta rincian anak. ?kode=7501 */
    public function bspsRingkasan(Request $r): JsonResponse
    {
        $kode = preg_replace('/\D/', '', (string) $r->query('kode', self::PROV)) ?: self::PROV;
        $tahun = (int) ($r->query('tahun') ?: DB::table('bsps_desa')->max('tahun'));
        $out = DashboardCache::remember("bspsr:$kode:$tahun", function () use ($kode, $tahun) {
            $agg = fn ($q) => $q->selectRaw('COALESCE(SUM(total),0) total, COALESCE(SUM(p0),0) p0, COALESCE(SUM(p30),0) p30, COALESCE(SUM(p100),0) p100, COALESCE(SUM(p_lain),0) p_lain, COUNT(*) desa_penerima');
            $self = $agg(DB::table('bsps_desa')->where('tahun', $tahun)->where('desa_kode', 'like', $kode.'%'))->first();
            $len = match (strlen($kode)) { 2 => 4, 4 => 6, 6 => 10, default => null };
            $anak = [];
            if ($len) {
                $sums = $agg(DB::table('bsps_desa')->where('tahun', $tahun)->where('desa_kode', 'like', $kode.'%')
                    ->selectRaw("SUBSTR(desa_kode,1,{$len}) AS kode")->groupByRaw("SUBSTR(desa_kode,1,{$len})"))->get()->keyBy('kode'); // $len bilangan bulat tetap (4/6/10)
                foreach (DB::table('wilayah')->where('induk', $kode)->orderBy('kode')->get(['kode', 'nama']) as $w) {
                    $s = $sums[$w->kode] ?? null;
                    $anak[] = ['kode' => $w->kode, 'nama' => $w->nama] + ($s ? (array) collect($s)->except('kode')->map(fn ($v) => (int) $v)->all() : ['total' => 0, 'p0' => 0, 'p30' => 0, 'p100' => 0, 'p_lain' => 0, 'desa_penerima' => 0]);
                }
            }

            return ['kode' => $kode, 'nama' => DB::table('wilayah')->where('kode', $kode)->value('nama'), 'tahun' => $tahun,
                'ringkasan' => collect($self)->map(fn ($v) => (int) $v), 'anak' => $anak];
        });

        return response()->json($out);
    }

    /** Deret harga. ?node=7571&variant=52 (node: 75, kode kab/kota, atau P{id pasar}) */
    public function harga(Request $r): JsonResponse
    {
        $node = (string) $r->query('node', self::PROV);
        $variant = (int) $r->query('variant', 0);
        $q = DB::table('harga_harian')->where('node', $node)->orderBy('tanggal');
        if ($variant) {
            $q->where('variant_id', $variant);
        }
        if ($dari = $r->query('dari')) { $q->where('tanggal', '>=', $dari); }
        if ($sampai = $r->query('sampai')) { $q->where('tanggal', '<=', $sampai); }

        return response()->json(['node' => $node, 'data' => $q->limit(20000)->get(['tanggal', 'variant_id', 'harga'])]);
    }

    /** Pencarian lintas wilayah, pasar, komoditas. ?q=limboto */
    public function cari(Request $r): JsonResponse
    {
        $q = trim((string) $r->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['q' => $q, 'data' => []]);
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)).'%';
        $isKode = ctype_digit($q);
        $wil = DB::table('wilayah')->when($isKode, fn ($x) => $x->where('kode', 'like', $q.'%'), fn ($x) => $x->whereRaw('LOWER(nama) LIKE ?', [$like]))
            ->orderByRaw("CASE level WHEN 'kab' THEN 1 WHEN 'kec' THEN 2 WHEN 'desa' THEN 3 ELSE 0 END")->limit(20)->get(['kode', 'nama', 'level', 'induk'])
            ->map(fn ($w) => ['jenis' => $w->level, 'id' => $w->kode, 'nama' => $w->nama, 'induk' => $w->induk]);
        $pasar = $isKode ? collect() : DB::table('pasar')->whereRaw('LOWER(nama) LIKE ?', [$like])->limit(10)->get(['id', 'nama', 'kab_kode'])
            ->map(fn ($p) => ['jenis' => 'pasar', 'id' => 'P'.$p->id, 'nama' => $p->nama, 'induk' => $p->kab_kode]);
        $kom = $isKode ? collect() : DB::table('komoditas')->whereRaw('LOWER(nama) LIKE ?', [$like])->orWhereRaw('LOWER(kelompok) LIKE ?', [$like])->limit(10)->get(['variant_id', 'nama', 'kelompok'])
            ->map(fn ($k) => ['jenis' => 'komoditas', 'id' => 'V'.$k->variant_id, 'nama' => $k->nama, 'induk' => $k->kelompok]);

        return response()->json(['q' => $q, 'data' => $wil->concat($pasar)->concat($kom)->values()]);
    }

    /** Indikator dari konektor API bertoken. ?metrik=...&kode=75 */
    public function indikator(Request $r): JsonResponse
    {
        $q = DB::table('indikator')->join('sumber_data', 'sumber_data.id', '=', 'indikator.sumber_data_id')
            ->select('sumber_data.kode as sumber', 'metrik', 'wilayah_kode', 'tanggal', 'nilai');
        if ($m = $r->query('metrik')) { $q->where('metrik', $m); }
        if ($k = $r->query('kode')) { $q->where('wilayah_kode', 'like', preg_replace('/\D/', '', $k).'%'); }

        return response()->json(['data' => $q->orderBy('wilayah_kode')->limit(20000)->get()]);
    }

    /* ---------- pembentuk paket ---------- */

    private function sumber(): array
    {
        return SumberData::with(['riwayat' => fn ($q) => $q->limit(1)])->orderBy('id')->get()->map(fn ($s) => [
            'kode' => $s->kode, 'nama' => $s->nama, 'driver' => $s->driver, 'aktif' => $s->aktif,
            'url' => $s->driver === 'http_json' ? null : $s->base_url, // URL API bertoken tidak dipublikasikan
            'terakhir_sinkron' => $s->terakhir_sinkron?->toIso8601String(),
            'status' => $s->riwayat->first()?->status,
        ])->all();
    }

    private function meta(): array
    {
        $sumber = collect($this->sumber())->keyBy('kode');
        $tahun = (int) DB::table('bsps_desa')->max('tahun');

        return [
            'generated' => now('Asia/Makassar')->toDateString(),
            'provinsi' => ['id' => self::PROV, 'name' => DB::table('wilayah')->where('kode', self::PROV)->value('nama') ?? 'Provinsi Gorontalo'],
            'sources' => [
                'bsps' => ['name' => $sumber['bsps']['nama'] ?? 'Kementerian PKP — Penerima BSPS', 'url' => 'https://pkp.go.id/cek-penerima-bsps', 'tahun' => $tahun,
                    'synced' => DB::table('bsps_desa')->max('synced_at')],
                'sp2kp' => ['name' => $sumber['sp2kp']['nama'] ?? 'Kemendag — SP2KP', 'url' => 'https://sp2kp.kemendag.go.id/'],
                'geo' => ['name' => 'Batas kab/kota (BIG, disederhanakan)'],
            ],
            'connectors' => array_values($sumber->all()),
        ];
    }

    private function wilayahLists(): array
    {
        $w = DB::table('wilayah')->orderBy('kode')->get(['kode', 'nama', 'level', 'induk'])->groupBy('level');

        return [
            'kab' => ($w['kab'] ?? collect())->map(fn ($x) => ['id' => $x->kode, 'name' => $x->nama])->values(),
            'kec' => ($w['kec'] ?? collect())->map(fn ($x) => ['id' => $x->kode, 'name' => $x->nama, 'kab' => $x->induk])->values(),
            'desa' => ($w['desa'] ?? collect())->map(fn ($x) => ['id' => $x->kode, 'name' => $x->nama, 'kec' => $x->induk])->values(),
        ];
    }

    private function bspsMap(): array
    {
        $tahun = DB::table('bsps_desa')->max('tahun');
        $out = [];
        foreach (DB::table('bsps_desa')->where('tahun', $tahun)->get() as $b) {
            $out[$b->desa_kode] = [(int) $b->total, (int) $b->p0, (int) $b->p30, (int) $b->p100, (int) $b->p_lain,
                $b->lat !== null ? (float) $b->lat : null, $b->lng !== null ? (float) $b->lng : null];
        }

        return $out;
    }

    private function hargaBundle(int $hari): array
    {
        $last = DB::table('harga_harian')->max('tanggal');
        if (! $last) {
            return ['dates' => [], 'variants' => [], 'pasar' => [], 'series' => (object) []];
        }
        $from = date('Y-m-d', strtotime($last.' -'.($hari - 1).' days'));
        $dates = DB::table('harga_harian')->where('tanggal', '>=', $from)->distinct()->orderBy('tanggal')->pluck('tanggal')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $idx = array_flip($dates);
        $series = [];
        foreach (DB::table('harga_harian')->where('tanggal', '>=', $from)->get(['tanggal', 'node', 'variant_id', 'harga']) as $h) {
            $series[$h->node][(string) $h->variant_id] ??= array_fill(0, count($dates), null);
            $series[$h->node][(string) $h->variant_id][$idx[substr((string) $h->tanggal, 0, 10)]] = (int) $h->harga;
        }

        return [
            'dates' => $dates,
            'variants' => DB::table('komoditas')->orderBy('urutan')->orderBy('nama')->get()
                ->map(fn ($k) => ['id' => (int) $k->variant_id, 'name' => $k->nama, 'group' => $k->kelompok, 'order' => (int) $k->urutan, 'unit' => $k->satuan])->values(),
            'pasar' => DB::table('pasar')->orderBy('id')->get()->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->nama, 'kab' => $p->kab_kode, 'lat' => (float) $p->lat, 'lng' => (float) $p->lng])->values(),
            'series' => $series,
        ];
    }

    private function geo(): array
    {
        $f = self::geoFile('kab');

        return $f ? json_decode(file_get_contents($f), true) : ['type' => 'FeatureCollection', 'features' => []];
    }

    /** Batas wilayah BIG (prov/kab/kec/desa) yang disederhanakan: database/geo/{level}-75.geojson */
    private static function geoFile(string $level): ?string
    {
        foreach ([database_path("geo/$level-".self::PROV.'.geojson'), storage_path("app/geo/$level-".self::PROV.'.geojson')] as $f) {
            if (is_file($f)) {
                return $f;
            }
        }

        return null;
    }

    /** GET /api/v1/geo/{level} — level: kab | kec | desa */
    public function geoLevel(string $level)
    {
        abort_unless(in_array($level, ['kab', 'kec', 'desa'], true), 404);
        $f = self::geoFile($level);
        abort_unless($f, 404);

        return response()->file($f, ['Content-Type' => 'application/geo+json', 'Cache-Control' => 'public, max-age=86400']);
    }

    private function etag(Request $r, array $data): JsonResponse
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $tag = '"'.md5($json).'"';
        if ($r->header('If-None-Match') === $tag) {
            return response()->json(null, 304)->setEtag(trim($tag, '"'));
        }

        return response()->json($data)->setEtag(trim($tag, '"'))->header('Cache-Control', 'public, max-age=300');
    }
}
