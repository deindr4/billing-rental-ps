<?php

use App\Http\Controllers\Api\GatewayCallbackController;
use App\Http\Controllers\Api\SinkronController;
use App\Http\Controllers\Api\Tv\PairingController;
use App\Http\Controllers\Api\Tv\TvController;
use App\Http\Middleware\AutentikasiServerSinkron;
use Illuminate\Support\Facades\Route;

// Cek hidup antar server (lokal <-> cloud) & monitoring. Tanpa data sensitif.
Route::get('/ping', fn () => response()->json([
    'ok' => true,
    'aplikasi' => config('app.name'),
    'mode' => config('app.mode') === 'cloud' ? 'cloud' : 'lokal',
    'waktu' => now()->toIso8601String(),
]))->middleware('throttle:60,1');

// Notifikasi pembayaran dari payment gateway (Tripay, Midtrans, Duitku, iPaymu, DOKU, Winpay)
Route::post('/gateway/{provider}/callback', GatewayCallbackController::class)
    ->whereIn('provider', ['tripay', 'midtrans', 'duitku', 'ipaymu', 'doku', 'winpay'])->middleware('throttle:120,1')->name('gateway.callback');

// Sinkron server lokal -> cloud (hanya dipakai di server cloud). Bearer token Server Sinkron.
Route::prefix('sync')->middleware([AutentikasiServerSinkron::class, 'throttle:120,1'])->group(function () {
    Route::get('/info', [SinkronController::class, 'info']);
    Route::post('/dorong', [SinkronController::class, 'dorong']);
    Route::get('/tarik', [SinkronController::class, 'tarik']);
});

/*
 | API aplikasi TV Agent (Android TV / Google TV). Semua di bawah /api/tv.
 */
Route::prefix('tv')->group(function () {
    // Belum terdaftar
    // Limiter bernama (AppServiceProvider): hitungan tiap endpoint terpisah
    Route::post('/pairing', [PairingController::class, 'mulai'])->middleware('throttle:tv-pairing');
    Route::post('/pairing/cek', [PairingController::class, 'cek'])->middleware('throttle:tv-pairing-cek');

    // Sudah terdaftar (Authorization: Bearer <token>)
    Route::middleware(['tv', 'throttle:tv'])->group(function () {
        Route::get('/status', [TvController::class, 'status']);
        Route::post('/heartbeat', [TvController::class, 'heartbeat']);
        Route::post('/bypass', [TvController::class, 'bypass']);
        Route::post('/bypass/akhiri', [TvController::class, 'akhiriBypass']);
        Route::post('/broadcasting/auth', [TvController::class, 'authBroadcast']);
        Route::get('/update', [TvController::class, 'cekUpdate']);
        Route::post('/panggil-kasir', [TvController::class, 'panggilKasir']);
        Route::post('/input-hdmi', [TvController::class, 'simpanInputHdmi']);
        Route::post('/verifikasi-pin', [TvController::class, 'verifikasiPin']);
    });

    // Unduh APK: batas request terpisah (file besar, tidak ikut throttle 120/menit)
    Route::get('/update/{rilis}/unduh', [TvController::class, 'unduhUpdate'])
        ->middleware(['tv', 'throttle:tv-unduh'])
        ->whereUuid('rilis')
        ->name('tv.update.unduh');
});
