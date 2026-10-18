<?php

use App\Models\SumberData;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

/*
 * Penjadwal. Di cPanel cukup satu cron: * * * * * php /home/USER/dasbor/artisan schedule:run
 * Setiap sumber data punya ekspresi cron sendiri (kolom `jadwal`), diatur dari panel admin.
 */
try {
    if (Schema::hasTable('sumber_data')) {
        foreach (SumberData::where('aktif', true)->get() as $s) {
            Schedule::command('dasbor:sinkron', [$s->kode])
                ->cron($s->jadwal)->timezone('Asia/Makassar')
                ->withoutOverlapping(120)->name('sinkron-'.$s->kode);
        }
    }
} catch (\Throwable) {
    // database belum siap (instalasi awal): jadwal konektor dilewati
}

// permintaan sinkron dari panel admin diproses tiap menit
Schedule::command('dasbor:sinkron --diminta')->everyMinute()->withoutOverlapping(120);
