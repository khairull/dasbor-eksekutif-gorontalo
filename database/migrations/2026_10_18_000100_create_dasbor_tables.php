<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skema inti dasbor eksekutif.
 * Seluruh data dikunci dengan kode wilayah Kemendagri (75, 7501, 750101, 7501011001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wilayah', function (Blueprint $t) {
            $t->string('kode', 13)->primary();
            $t->string('nama', 120);
            $t->enum('level', ['prov', 'kab', 'kec', 'desa']);
            $t->string('induk', 13)->nullable()->index();
            $t->decimal('lat', 10, 6)->nullable();
            $t->decimal('lng', 10, 6)->nullable();
            $t->timestamps();
            $t->index(['level', 'induk']);
        });

        // Agregat BSPS per desa. Tidak menyimpan data perorangan (ID, NIK, nama, titik rumah).
        Schema::create('bsps_desa', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('tahun');
            $t->string('desa_kode', 13);
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('p0')->default(0);
            $t->unsignedInteger('p30')->default(0);
            $t->unsignedInteger('p100')->default(0);
            $t->unsignedInteger('p_lain')->default(0);
            $t->decimal('lat', 10, 6)->nullable(); // rata-rata titik penerima (centroid), bukan lokasi rumah
            $t->decimal('lng', 10, 6)->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->unique(['tahun', 'desa_kode']);
        });

        Schema::create('komoditas', function (Blueprint $t) {
            $t->unsignedInteger('variant_id')->primary();
            $t->string('nama', 120);
            $t->string('kelompok', 80)->nullable();
            $t->string('satuan', 30)->nullable();
            $t->unsignedSmallInteger('urutan')->default(999);
            $t->timestamps();
        });

        Schema::create('pasar', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('nama', 160);
            $t->string('kab_kode', 13)->index();
            $t->string('kec_kode', 13)->nullable();
            $t->string('desa_kode', 13)->nullable();
            $t->decimal('lat', 10, 6)->nullable();
            $t->decimal('lng', 10, 6)->nullable();
            $t->timestamps();
        });

        // node: '75' (provinsi), '7501' (kab/kota), 'P662' (pasar)
        Schema::create('harga_harian', function (Blueprint $t) {
            $t->id();
            $t->date('tanggal');
            $t->string('node', 16);
            $t->unsignedTinyInteger('level'); // 1 prov, 2 kab/kota, 3 pasar
            $t->unsignedInteger('variant_id');
            $t->unsignedInteger('harga');
            $t->unique(['tanggal', 'node', 'variant_id']);
            $t->index(['node', 'variant_id', 'tanggal']);
        });

        // Konektor sumber data: data terbuka maupun API bertoken.
        Schema::create('sumber_data', function (Blueprint $t) {
            $t->id();
            $t->string('kode', 40)->unique();
            $t->string('nama', 160);
            $t->string('driver', 30);            // bsps | sp2kp | http_json
            $t->string('base_url', 255);
            $t->string('auth_type', 20)->default('none'); // none | bearer | header | query
            $t->string('auth_name', 60)->nullable();      // nama header / parameter query
            $t->text('token')->nullable();                // terenkripsi (APP_KEY)
            $t->json('opsi')->nullable();
            $t->string('jadwal', 60)->default('0 2 * * *'); // ekspresi cron
            $t->boolean('aktif')->default(true);
            $t->boolean('minta_sinkron')->default(false); // ditandai dari panel admin, dijalankan penjadwal
            $t->timestamp('terakhir_sinkron')->nullable();
            $t->timestamps();
        });

        Schema::create('riwayat_sinkron', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sumber_data_id')->constrained('sumber_data')->cascadeOnDelete();
            $t->string('status', 20); // berjalan | sukses | gagal
            $t->unsignedInteger('baris')->default(0);
            $t->text('pesan')->nullable();
            $t->timestamp('mulai');
            $t->timestamp('selesai')->nullable();
            $t->index(['sumber_data_id', 'mulai']);
        });

        // Indikator generik dari konektor API bertoken (http_json).
        Schema::create('indikator', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sumber_data_id')->constrained('sumber_data')->cascadeOnDelete();
            $t->string('metrik', 80);
            $t->string('wilayah_kode', 13)->index();
            $t->date('tanggal')->nullable();
            $t->decimal('nilai', 20, 4)->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->unique(['sumber_data_id', 'metrik', 'wilayah_kode', 'tanggal'], 'indikator_unik');
        });
    }

    public function down(): void
    {
        foreach (['indikator', 'riwayat_sinkron', 'sumber_data', 'harga_harian', 'pasar', 'komoditas', 'bsps_desa', 'wilayah'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
