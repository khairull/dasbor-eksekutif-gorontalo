<?php

use App\Http\Controllers\Api\DasborController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:120,1')->group(function () {
    Route::get('dasbor', [DasborController::class, 'bundle']);
    Route::get('status', [DasborController::class, 'status']);
    Route::get('wilayah', [DasborController::class, 'wilayah']);
    Route::get('bsps/ringkasan', [DasborController::class, 'bspsRingkasan']);
    Route::get('harga', [DasborController::class, 'harga']);
    Route::get('cari', [DasborController::class, 'cari']);
    Route::get('indikator', [DasborController::class, 'indikator']);
    Route::get('geo/{level}', [DasborController::class, 'geoLevel']);
});
