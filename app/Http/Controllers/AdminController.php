<?php

namespace App\Http\Controllers;

use App\Models\SumberData;
use App\Support\SyncRunner;
use Cron\CronExpression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function index()
    {
        return view('admin', [
            'sumber' => SumberData::with(['riwayat' => fn ($q) => $q->limit(5)])->orderBy('id')->get(),
            'drivers' => array_keys(SyncRunner::DRIVERS),
        ]);
    }

    public function simpan(Request $r)
    {
        $v = $r->validate([
            'id' => ['nullable', 'integer', 'exists:sumber_data,id'],
            'kode' => ['required', 'alpha_dash', 'max:40', Rule::unique('sumber_data', 'kode')->ignore($r->input('id'))],
            'nama' => ['required', 'max:160'],
            'driver' => ['required', Rule::in(array_keys(SyncRunner::DRIVERS))],
            'base_url' => ['required', 'url:https,http', 'max:255'],
            'auth_type' => ['required', Rule::in(['none', 'bearer', 'header', 'query'])],
            'auth_name' => ['nullable', 'max:60'],
            'token' => ['nullable', 'max:4000'],
            'hapus_token' => ['nullable', 'boolean'],
            'opsi' => ['nullable', 'json'],
            'jadwal' => ['required', 'max:60', function ($a, $val, $fail) { if (! CronExpression::isValidExpression($val)) { $fail('Ekspresi cron tidak valid.'); } }],
            'aktif' => ['nullable', 'boolean'],
        ]);
        $s = isset($v['id']) ? SumberData::findOrFail($v['id']) : new SumberData;
        $s->fill(collect($v)->only(['kode', 'nama', 'driver', 'base_url', 'auth_type', 'auth_name', 'jadwal'])->all());
        $s->opsi = $v['opsi'] ? json_decode($v['opsi'], true) : [];
        $s->aktif = (bool) ($v['aktif'] ?? false);
        if (! empty($v['token'])) {
            $s->token = $v['token'];          // disimpan terenkripsi; kolom tidak pernah ditampilkan kembali
        } elseif (! empty($v['hapus_token'])) {
            $s->token = null;
        }
        $s->save();

        return redirect('/admin')->with('pesan', "Konektor {$s->kode} disimpan.");
    }

    public function minta(SumberData $sumber)
    {
        $sumber->update(['minta_sinkron' => true]);

        return redirect('/admin')->with('pesan', "Sinkron {$sumber->kode} dijadwalkan. Penjadwal menjalankannya dalam ±1 menit.");
    }

    public function hapus(SumberData $sumber)
    {
        abort_if(in_array($sumber->kode, ['bsps', 'sp2kp']), 422, 'Konektor bawaan tidak bisa dihapus; nonaktifkan saja.');
        $sumber->delete();

        return redirect('/admin')->with('pesan', 'Konektor dihapus.');
    }

    /**
     * Tugas pemeliharaan via URL untuk hosting tanpa SSH: /pemeliharaan/{token}/{tugas}
     * tugas: migrasi | seed | snapshot | sinkron | cache
     */
    public function pemeliharaan(string $token, string $tugas)
    {
        $expected = (string) config('dasbor.maintenance_token');
        abort_unless($expected !== '' && strlen($expected) >= 24 && hash_equals($expected, $token), 404);
        @set_time_limit(0);
        $cmd = match ($tugas) {
            'migrasi' => ['migrate', ['--force' => true]],
            'seed' => ['db:seed', ['--force' => true]],
            'snapshot' => ['dasbor:impor-snapshot', []],
            'sinkron' => ['dasbor:sinkron', []],
            'cache' => ['optimize', []],
            default => abort(404),
        };
        $code = Artisan::call($cmd[0], $cmd[1]);

        return response("[$tugas] kode keluar $code\n\n".Artisan::output(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
