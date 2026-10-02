<?php

use App\Models\Iklan;
use App\Services\Gateway\BayarMandiriService;
use App\Services\Publik\BookingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backup harian jam 02:00 (butuh scheduler berjalan: `php artisan schedule:work` atau Task Scheduler/cron)
Schedule::command('backup:buat')->dailyAt('02:00')->withoutOverlapping();

// Booking yang lewat toleransi tanpa datang -> "tidak datang" (slot dilepas)
Schedule::call(fn () => app(BookingService::class)->tandaiKedaluwarsa())->everyFiveMinutes()->name('booking-kedaluwarsa');

// Iklan billboard yang masa tayangnya habis terhapus otomatis (ikut tersinkron ke cloud)
Schedule::call(fn () => Iklan::hapusKedaluwarsa())->hourly()->name('iklan-kedaluwarsa');

// Bayar mandiri TV: cek status QRIS yang menunggu (cadangan callback) & selesaikan sesi lunas yang habis
Schedule::call(function () {
    $layanan = app(BayarMandiriService::class);
    $layanan->periksaSemua();
    $layanan->selesaikanYangHabis();
})->everyMinute()->name('bayar-mandiri')->withoutOverlapping(5);

// Sinkron lokal -> cloud tiap menit (diam jika belum diatur); cloud membersihkan antrean lama
Schedule::command('sync jalankan --diam')->everyMinute()->withoutOverlapping(10)->when(fn () => config('app.mode') !== 'cloud');
Schedule::command('sync bersihkan')->dailyAt('03:30')->when(fn () => config('app.mode') === 'cloud');

// Shared hosting (ANTREAN_LEWAT_CRON=true): tidak ada queue:work permanen -> kerjakan antrean tiap menit lalu berhenti
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping(2)
    ->when(fn () => config('billing.antrean_lewat_cron'));
