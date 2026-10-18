<?php

namespace Database\Seeders;

use App\Models\SumberData;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        SumberData::updateOrCreate(['kode' => 'bsps'], [
            'nama' => 'Kementerian PKP — Penerima BSPS',
            'driver' => 'bsps',
            'base_url' => env('BSPS_BASE_URL', 'https://pkp.go.id/cek-penerima-bsps'),
            'opsi' => ['provinsi_id' => '75', 'konkurensi' => 6, 'bbox' => [-1.5, 1.5, 121.0, 124.5]],
            'jadwal' => '15 1 * * 1',   // Senin 01:15 WITA
        ]);
        SumberData::updateOrCreate(['kode' => 'sp2kp'], [
            'nama' => 'Kemendag — SP2KP harga pangan',
            'driver' => 'sp2kp',
            'base_url' => env('SP2KP_BASE_URL', 'https://api-sp2kp.kemendag.go.id'),
            'opsi' => ['kode_provinsi' => '75', 'hari_awal' => 31, 'hari_inkremental' => 7, 'take' => 10000, 'zona_waktu' => 'Asia/Makassar'],
            'jadwal' => '45 15 * * 1-5', // hari kerja 15:45 WITA, setelah input harian petugas
        ]);
    }
}
