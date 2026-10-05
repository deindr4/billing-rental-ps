<?php

use App\Http\Controllers\LaporanAsetController;
use App\Http\Controllers\StrukController;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\PilihCabang;
use App\Livewire\Operator\AnalisaPintar;
use App\Livewire\Operator\AsetModal;
use App\Livewire\Operator\BukaShift;
use App\Livewire\Operator\DaftarMaintenance;
use App\Livewire\Operator\DaftarTransaksi;
use App\Livewire\Operator\DaftarTurnamen;
use App\Livewire\Operator\JadwalBooking;
use App\Livewire\Operator\Laporan;
use App\Livewire\Operator\Lounge;
use App\Livewire\Operator\Member;
use App\Livewire\Operator\PembayaranOnlineKasir;
use App\Livewire\Operator\Pengeluaran;
use App\Livewire\Operator\Pos;
use App\Livewire\Operator\Rental;
use App\Livewire\Operator\RentalPc;
use App\Livewire\Operator\Stok;
use App\Livewire\Operator\TutupKas;
use App\Livewire\Publik\Billboard;
use App\Livewire\Publik\BookingPortal;
use App\Livewire\Publik\MainMandiri;
use App\Livewire\Publik\TurnamenPublik;
use App\Models\Iklan;
use App\Models\RilisApk;
use App\Services\Tv\RilisApkService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// APK TV Agent terbaru untuk dipasang lewat aplikasi Downloader di TV (http://IP-server/apk).
// Tanpa login: APK tidak berguna tanpa pairing dari admin.
Route::get('/apk', function () {
    $rilis = RilisApk::aktif()->orderByDesc('versi_kode')->first();
    $disk = Storage::disk(RilisApkService::DISK);

    abort_unless($rilis && $disk->exists($rilis->file), 404, 'Belum ada rilis APK TV Agent.');

    return response()->download($disk->path($rilis->file), 'tv-agent.apk', [
        'Content-Type' => 'application/vnd.android.package-archive',
    ]);
})->middleware('throttle:20,1')->name('apk');

// Publik (tanpa login): billboard TV lounge
Route::get('/billboard/{kode}', Billboard::class)->middleware('throttle:60,1')->name('billboard');
Route::get('/booking/{kode}', BookingPortal::class)->middleware('throttle:60,1')->name('booking');
Route::get('/iklan/{iklan}/gambar.webp', function (string $iklan) {
    $i = Iklan::withoutGlobalScopes()->whereKey($iklan)->first(['id', 'gambar', 'updated_at']);
    abort_unless($i, 404);

    return response(base64_decode($i->gambar), 200, [
        'Content-Type' => 'image/webp',
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->whereUuid('iklan')->middleware('throttle:240,1')->name('iklan.gambar');
Route::get('/main/{token}', MainMandiri::class)->middleware('throttle:60,1')->name('main');
Route::get('/turnamen/{slug}', TurnamenPublik::class)->middleware('throttle:60,1')->name('turnamen.publik');

// "Rental" di menu admin hanya judul kelompok; alamat ini diarahkan ke daftar unit
Route::redirect('/admin/rental', '/admin/unit');

// Belum login
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

// Sudah login
Route::middleware('auth')->group(function () {
    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    // Tenant & cabang aktif
    Route::middleware('tenancy')->group(function () {
        Route::get('/pilih-cabang', PilihCabang::class)->name('pilih-cabang');

        Route::get('/shift/buka', BukaShift::class)->middleware('can:shift.kelola')->name('shift.buka');
        Route::get('/shift/tutup', TutupKas::class)->middleware('can:shift.kelola')->name('shift.tutup');
        Route::get('/transaksi', DaftarTransaksi::class)->middleware('can:transaksi.lihat')->name('transaksi');
        Route::get('/stok', Stok::class)->middleware('can:stok.lihat')->name('stok');
        Route::get('/laporan', Laporan::class)->middleware('can:laporan.lihat')->name('laporan');
        Route::get('/analisa', AnalisaPintar::class)->middleware('can:laporan.laba')->name('analisa');
        Route::get('/pengeluaran', Pengeluaran::class)->middleware('can:pengeluaran.catat')->name('pengeluaran');
        Route::get('/member', Member::class)->middleware('can:member.kelola')->name('member');
        Route::get('/pembayaran-online', PembayaranOnlineKasir::class)->middleware('can:pembayaran.terima')->name('pembayaran-online');
        Route::get('/jadwal', JadwalBooking::class)->middleware('can:rental.kelola')->name('jadwal');
        Route::get('/lounge', Lounge::class)->middleware('can:rental.kelola')->name('lounge');
        Route::get('/turnamen', DaftarTurnamen::class)->middleware('can:turnamen.kelola')->name('turnamen');
        Route::get('/maintenance', DaftarMaintenance::class)->middleware('can:maintenance.kelola')->name('maintenance');
        Route::get('/aset', AsetModal::class)->middleware('can:aset.lihat')->name('aset');
        Route::get('/aset/laporan.{format}', LaporanAsetController::class)->whereIn('format', ['pdf', 'csv'])
            ->middleware('can:aset.lihat')->name('aset.laporan');

        // Struk & nota
        Route::get('/transaksi/{id}/struk', [StrukController::class, 'thermal'])->whereUuid('id')->name('struk.thermal');
        Route::get('/transaksi/{id}/nota', [StrukController::class, 'nota'])->whereUuid('id')->name('struk.nota');

        // Wajib shift terbuka
        Route::middleware('shift')->group(function () {
            Route::get('/', Rental::class)->middleware('can:rental.kelola')->name('rental');
            Route::get('/pc', RentalPc::class)->middleware('can:rental.kelola')->name('rental-pc');
            Route::get('/pos', Pos::class)->middleware('can:pos.jual')->name('pos');
        });
    });
});
