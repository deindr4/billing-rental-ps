<?php

use App\Models\Iklan;
use App\Services\Gateway\BayarMandiriService;
use App\Services\Playbox\PlayboxService;
use App\Services\Publik\BookingService;
use App\Services\UpdateAplikasi;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cek versi baru di GitHub Releases (hasil di-cache; dasbor & Pemeliharaan sistem membacanya)
Artisan::command('update:cek', function () {
    $r = app(UpdateAplikasi::class)->cek(paksa: true);

    match (true) {
        $r === null => $this->line('Cek update dimatikan (UPDATE_REPO kosong).'),
        isset($r['error']) => $this->warn($r['error']),
        $r['baru'] => $this->info("Versi baru {$r['versi']} tersedia (terpasang {$r['sekarang']}): {$r['halaman']}"),
        default => $this->line("Sudah terbaru ({$r['sekarang']})."),
    };
})->purpose('Cek versi baru aplikasi di GitHub');
Schedule::command('update:cek')->everySixHours()->withoutOverlapping();

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

// Sewa Playbox: pengingat WA ke penyewa sebelum jatuh tempo (hanya server lokal agar tidak dobel dengan cloud)
Schedule::call(fn () => app(PlayboxService::class)->kirimPengingat())
    ->everyTenMinutes()->name('playbox-pengingat')->withoutOverlapping(10)->when(fn () => config('app.mode') !== 'cloud');

// Database tetap ringan: pangkas log lama, cache kedaluwarsa, antrean sinkron ganda; statistik index tiap Minggu
Schedule::command('db:rapikan')->dailyAt('03:40')->withoutOverlapping(30);
Schedule::command('db:rapikan --analisa')->weeklyOn(0, '04:10')->withoutOverlapping(30);

// Sinkron lokal -> cloud tiap menit (diam jika belum diatur); cloud membersihkan antrean lama
Schedule::command('sync jalankan --diam')->everyMinute()->withoutOverlapping(10)->when(fn () => config('app.mode') !== 'cloud');
Schedule::command('sync bersihkan')->dailyAt('03:30')->when(fn () => config('app.mode') === 'cloud');

// Shared hosting (ANTREAN_LEWAT_CRON=true): tidak ada queue:work permanen -> kerjakan antrean tiap menit lalu berhenti
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping(2)
    ->when(fn () => config('billing.antrean_lewat_cron'));
