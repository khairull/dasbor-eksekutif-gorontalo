<?php

namespace App\Console\Commands;

use App\Models\SumberData;
use App\Support\SyncRunner;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'dasbor:sinkron {kode? : kode sumber data (kosongkan untuk semua yang aktif)} {--diminta : hanya sumber yang ditandai dari panel admin}';
    protected $description = 'Tarik data dari konektor sumber data ke database';

    public function handle(): int
    {
        $q = SumberData::query()->where('aktif', true);
        if ($kode = $this->argument('kode')) {
            $q->where('kode', $kode);
        }
        if ($this->option('diminta')) {
            $q->where('minta_sinkron', true);
        }
        $list = $q->get();
        if ($list->isEmpty()) {
            $this->option('diminta') || $this->warn('Tidak ada sumber data yang cocok.');
            return self::SUCCESS;
        }
        $gagal = 0;
        foreach ($list as $s) {
            $this->info("▶ {$s->nama} ({$s->kode})");
            $run = SyncRunner::run($s, fn ($m) => $this->line('  '.$m));
            $run->status === 'sukses' ? $this->info("  ✔ {$run->baris} baris") : $this->error('  ✖ gagal') ;
            $gagal += $run->status === 'sukses' ? 0 : 1;
        }

        return $gagal ? self::FAILURE : self::SUCCESS;
    }
}
