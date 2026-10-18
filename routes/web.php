<?php

use App\Http\Controllers\AdminController;
use App\Http\Middleware\AdminAuth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->file(public_path('dasbor.html'), ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']));

Route::middleware(AdminAuth::class)->prefix('admin')->group(function () {
    Route::get('/', [AdminController::class, 'index']);
    Route::post('/sumber', [AdminController::class, 'simpan']);
    Route::post('/sumber/{sumber}/sinkron', [AdminController::class, 'minta']);
    Route::post('/sumber/{sumber}/hapus', [AdminController::class, 'hapus']);
});

Route::get('/pemeliharaan/{token}/{tugas}', [AdminController::class, 'pemeliharaan'])->middleware('throttle:10,1');
