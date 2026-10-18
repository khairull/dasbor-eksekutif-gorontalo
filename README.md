# Dasbor Eksekutif Provinsi Gorontalo — backend

Laravel 13 (PHP ≥ 8.3). Menarik data terbuka dan API bertoken ke database, lalu menyajikannya ke dasbor
(`/`) melalui API JSON. Seluruh data dikunci dengan **kode wilayah Kemendagri**
(`75` → `7501` → `750101` → `7501011001`).

## Isi

| Bagian | Berkas |
|---|---|
| Konektor BSPS (Kementerian PKP) | `app/Connectors/BspsConnector.php` |
| Konektor SP2KP (Kemendag) | `app/Connectors/Sp2kpConnector.php` |
| Konektor API bertoken generik | `app/Connectors/HttpJsonConnector.php` |
| Penjalan sinkron + riwayat + kunci | `app/Support/SyncRunner.php` |
| API v1 | `app/Http/Controllers/Api/DasborController.php`, `routes/api.php` |
| Panel admin konektor | `/admin` — `app/Http/Controllers/AdminController.php` |
| Dasbor | `public/dasbor.html` (dimuat di `/`) |
| Batas wilayah BIG (kab, kecamatan, desa), disederhanakan | `database/geo/*.geojson`, dibangun dengan `database/geo/build_batas.py` |
| Snapshot awal (18 Okt 2026) | `database/snapshot/gorontalo-2026-10-18.json` |
| Server tiruan untuk uji | `tests/mock/mock_sources.py` |

### Privasi
- BSPS: yang disimpan **hanya agregat per desa** (jumlah unit per progres fisik dan titik tengah rata-rata).
  ID penerima dan koordinat rumah dipakai sementara di memori lalu dibuang. Fitur cek per-NIK (ber-captcha) tidak disentuh.
- SP2KP: data petugas (nama, NIP, email) pada respons sumber dibuang.
- Token API disimpan terenkripsi (`APP_KEY`) dan tidak pernah dikirim ke peramban; URL konektor bertoken tidak dipublikasikan di API.

## API v1

| Endpoint | Isi |
|---|---|
| `GET /api/v1/dasbor` | Paket lengkap dasbor (wilayah, BSPS per desa, deret harga, batas kab/kota). Ter-cache + ETag. `?hari=35` |
| `GET /api/v1/status` | Status konektor dan sinkron terakhir |
| `GET /api/v1/wilayah?induk=7501` | Anak wilayah |
| `GET /api/v1/bsps/ringkasan?kode=7505` | Ringkasan BSPS + rincian per anak wilayah |
| `GET /api/v1/harga?node=7571&variant=52` | Deret harga (node: `75`, kode kab/kota, `P{id pasar}`) |
| `GET /api/v1/cari?q=limboto` | Pencarian wilayah, pasar, komoditas, kode wilayah |
| `GET /api/v1/indikator?metrik=...&kode=75` | Data dari konektor API bertoken |
| `GET /api/v1/geo/{kab\|kec\|desa}` | Batas wilayah GeoJSON (BIG, Juni 2023; 729 desa cocok dengan kode Kemendagri) |

## Penjadwalan
Satu cron per menit menjalankan `schedule:run`. Jadwal tiap konektor diatur di panel admin (ekspresi cron, WITA):
- `sp2kp` hari kerja 15:45 (7 hari terakhir; sinkron pertama 31 hari)
- `bsps` Senin 01:15
- Tombol **Sinkronkan sekarang** di panel admin diproses penjadwal dalam ±1 menit.

Sinkron BSPS dibatalkan (data lama dipertahankan) bila total hasil agregasi berbeda >2% dari total sumber.

## Menambah sumber API bertoken
Panel admin → *Tambah konektor API bertoken* → driver `http_json`. Contoh opsi:
```json
{
  "path": "/v1/stunting",
  "query": {"tahun": 2026},
  "records_path": "data",
  "paginasi": {"param": "page", "mulai": 1, "maks_halaman": 50},
  "peta": {"metrik": "=prevalensi_stunting", "wilayah_kode": "kode_desa", "tanggal": "periode", "nilai": "persen"},
  "meta": ["jumlah_balita"]
}
```
Nilai berawalan `=` adalah konstanta; selain itu jalur kolom (notasi titik).

## Pasang di cPanel tanpa SSH (DomaiNesia)
Folder `vendor/` sudah disertakan, jadi composer tidak perlu dijalankan di server.

1. **PHP 8.3+** untuk subdomain (MultiPHP Manager).
2. **Database MySQL** baru, misal `elkhair1_dasbor` + user dengan semua hak.
3. **Unggah & ekstrak** `dasbor-api.zip` ke `/home/elkhair1/dasbor` (di luar `public_html`).
   Salin `.env.production.example` menjadi `.env`, isi:
   - `APP_KEY` = `base64:` + 32 byte acak dalam base64
   - `DB_*`, `APP_URL`
   - `ADMIN_PASSWORD_HASH` = hash bcrypt kata sandi admin
   - `MAINTENANCE_TOKEN` = string acak ≥ 24 karakter
4. **Subdomain** `dasbor.elkhair.my.id` dengan document root `/home/elkhair1/dasbor/public`.
5. **Inisialisasi** lewat peramban (sekali saja, berurutan):
   `https://dasbor.elkhair.my.id/pemeliharaan/<TOKEN>/migrasi` → `/seed` → `/snapshot` → `/sinkron` → `/cache`
6. **Cron** (cPanel → Cron Jobs, tiap menit):
   `/usr/local/bin/php /home/elkhair1/dasbor/artisan schedule:run >> /dev/null 2>&1`
7. Edge cache DomaiNesia dapat menyimpan halaman `/` lama selama beberapa menit setelah pemasangan.
8. Setelah berjalan, kosongkan `MAINTENANCE_TOKEN` di `.env` agar rute pemeliharaan nonaktif.

## Uji lokal
```bash
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate && php artisan db:seed
php artisan dasbor:impor-snapshot          # data awal
python3 tests/mock/mock_sources.py database/snapshot/gorontalo-2026-10-18.json 9099 &
BSPS_BASE_URL=http://127.0.0.1:9099/bsps SP2KP_BASE_URL=http://127.0.0.1:9099/sp2kp php artisan db:seed
php artisan dasbor:sinkron                 # ETL terhadap server tiruan
php artisan serve
```
