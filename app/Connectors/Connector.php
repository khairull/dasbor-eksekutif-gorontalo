<?php

namespace App\Connectors;

use App\Models\SumberData;

interface Connector
{
    /**
     * Tarik data dari sumber dan simpan ke database.
     * Mengembalikan jumlah baris yang disimpan.
     *
     * @param  callable(string):void  $log
     */
    public function sync(SumberData $sumber, callable $log): int;
}
