<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Gateway\PengaturanGateway;
use App\Support\Audit;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;
use UnitEnum;

/**
 * Tombol perintah artisan yang biasa dipakai setelah pasang/update/ubah .env,
 * supaya owner tidak perlu membuka terminal. Hanya perintah dalam daftar TINDAKAN yang bisa dijalankan.
 * Server lokal: owner & super admin. Server cloud (banyak tenant): hanya super admin.
 */
class Pemeliharaan extends Page
{
    protected string $view = 'filament.pages.pemeliharaan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 22;

    protected static ?string $navigationLabel = 'Pemeliharaan sistem';

    protected static ?string $title = 'Pemeliharaan sistem';

    protected static ?string $slug = 'pemeliharaan';

    /**
     * kunci => [judul, keterangan, perintah, argumen, konfirmasi?, warna]
     */
    public const TINDAKAN = [
        'optimasi' => [
            'Optimalkan', 'Simpan cache config, route, view, event & ikon. Jalankan setelah update aplikasi atau ubah .env — halaman jadi lebih cepat.',
            'optimize', [], null, 'primary',
        ],
        'bersihkan' => [
            'Bersihkan cache sistem', 'Hapus cache config, route, view, event & ikon. Pakai jika perubahan .env / tampilan tidak terbaca.',
            'optimize:clear', [], null, 'gray',
        ],
        'cache_data' => [
            'Kosongkan cache data', 'Hapus cache data aplikasi (status, batas percobaan, dll.). Data transaksi tidak tersentuh.',
            'cache:clear', [], 'Kosongkan cache data aplikasi?', 'gray',
        ],
        'migrasi' => [
            'Update database', 'Jalankan migrasi (tabel/kolom baru) setelah memasang versi aplikasi baru. Buat backup dulu.',
            'migrate', ['--force' => true], 'Jalankan migrasi database sekarang? Pastikan sudah backup.', 'warning',
        ],
        'trigger' => [
            'Pasang ulang trigger sinkron', 'Wajib setelah update yang menambah tabel baru, atau jika sinkron tidak mengirim perubahan.',
            'sync', ['aksi' => 'pasang-trigger'], null, 'gray',
        ],
        'storage' => [
            'Tautkan folder unggahan', 'Buat tautan public/storage (sekali setelah instal). Pakai jika gambar/logo tidak tampil.',
            'storage:link', [], null, 'gray',
        ],
        'antrean' => [
            'Restart antrean (queue)', 'Worker antrean berhenti setelah tugas berjalan & dinyalakan ulang oleh layanan Windows. Pakai setelah update aplikasi.',
            'queue:restart', [], 'Restart worker antrean? Pastikan worker berjalan sebagai layanan (menyala sendiri).', 'gray',
        ],
        'reverb' => [
            'Restart realtime (Reverb)', 'Server websocket TV & kasir berhenti & dinyalakan ulang oleh layanan Windows. TV memakai polling sementara.',
            'reverb:restart', [], 'Restart server realtime? Pastikan Reverb berjalan sebagai layanan (menyala sendiri).', 'gray',
        ],
    ];

    public ?string $hasilJudul = null;

    public ?string $hasil = null;

    public bool $hasilGagal = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user?->isSuperAdmin()) {
            return true;
        }

        return config('app.mode') !== 'cloud' && (bool) $user?->hasRole('Owner');
    }

    public function jalankan(string $kunci): void
    {
        abort_unless(static::canAccess(), 403);
        $t = self::TINDAKAN[$kunci] ?? abort(404);
        [$judul, , $perintah, $argumen] = $t;

        @set_time_limit(300);

        try {
            $kode = Artisan::call($perintah, $argumen);
            $keluaran = trim(preg_replace('/\e\[[\d;]*m/', '', Artisan::output()));
            $this->hasilGagal = $kode !== 0;
        } catch (Throwable $e) {
            $keluaran = $e->getMessage();
            $this->hasilGagal = true;
        }

        $this->hasilJudul = $judul;
        $this->hasil = $keluaran !== '' ? $keluaran : 'Selesai.';

        Audit::catat('pemeliharaan', $judul.($this->hasilGagal ? ' (gagal)' : ''), data: ['perintah' => $perintah]);

        Notification::make()->title($judul.($this->hasilGagal ? ' gagal' : ' selesai'))
            ->{$this->hasilGagal ? 'danger' : 'success'}()->send();
    }

    public function kosongkanLog(): void
    {
        abort_unless(static::canAccess(), 403);
        $f = storage_path('logs/laravel.log');

        if (is_file($f)) {
            file_put_contents($f, '');
        }

        Audit::catat('pemeliharaan', 'Kosongkan log error');
        Notification::make()->title('Log error dikosongkan')->success()->send();
    }

    /** Ringkasan kondisi sistem: [label, nilai, baik?] (baik null = netral) */
    public function getStatusProperty(): array
    {
        $prod = app()->environment('production');

        try {
            $db = DB::selectOne('select version() as v')->v;
        } catch (Throwable) {
            $db = 'tidak terhubung';
        }

        $bebas = @disk_free_space(base_path());
        $log = storage_path('logs/laravel.log');

        // Akun demo dari seeder yang password-nya masih "password"
        $demo = User::withoutGlobalScopes()->whereIn('email', ['admin@billing.test', 'owner@billing.test'])->get(['email', 'password'])
            ->filter(fn ($u) => Hash::check('password', $u->password))->pluck('email');
        $gateway = app(PengaturanGateway::class)->provider();
        $cloud = config('app.mode') === 'cloud';

        return [
            ['Password bawaan', $demo->isEmpty() ? 'Aman' : 'Masih dipakai: '.$demo->implode(', '), $demo->isEmpty()],
            ['Payment gateway', PengaturanGateway::PROVIDER[$gateway].($gateway !== 'nonaktif' && app(PengaturanGateway::class)->sandbox() ? ' (sandbox)' : ''),
                $gateway === 'simulasi' && $prod ? false : null],
            ['Alamat aplikasi', config('app.url'), $cloud && $prod ? str_starts_with((string) config('app.url'), 'https://') : null],
            ['Proxy tepercaya', config('billing.proxy_tepercaya') ?: 'Tidak ada', null],
            // Di balik Cloudflare: "Tidak" padahal dibuka lewat https = TRUSTED_PROXIES belum benar (IP & skema salah terbaca)
            ['HTTPS terbaca', request()->isSecure() ? 'Ya' : 'Tidak', $cloud && $prod ? request()->isSecure() : null],
            ['Mode server', config('app.mode') === 'cloud' ? 'Cloud' : 'Lokal', null],
            ['Lingkungan', app()->environment(), $prod ? true : null],
            ['Mode debug', config('app.debug') ? 'Nyala' : 'Mati', ! config('app.debug') || ! $prod],
            ['Cache config', app()->configurationIsCached() ? 'Ada' : 'Belum', app()->configurationIsCached() || ! $prod],
            ['Cache route', app()->routesAreCached() ? 'Ada' : 'Belum', app()->routesAreCached() || ! $prod],
            ['Tautan unggahan', is_link(public_path('storage')) || is_dir(public_path('storage')) ? 'Ada' : 'Belum', is_link(public_path('storage')) || is_dir(public_path('storage'))],
            ['Mode perawatan', app()->isDownForMaintenance() ? 'Aktif' : 'Tidak', ! app()->isDownForMaintenance()],
            ['PHP · Laravel', PHP_VERSION.' · '.app()->version(), null],
            ['Database', $db, $db !== 'tidak terhubung'],
            ['Ruang disk kosong', $bebas !== false ? number_format($bebas / 1073741824, 1, ',', '.').' GB' : '-', $bebas === false ? null : $bebas > 2 * 1073741824],
            ['Ukuran log error', is_file($log) ? number_format(filesize($log) / 1024, 0, ',', '.').' KB' : '0 KB', ! is_file($log) || filesize($log) < 20 * 1048576],
        ];
    }

    /** 40 baris terakhir log error */
    public function getLogProperty(): string
    {
        $f = storage_path('logs/laravel.log');

        if (! is_file($f) || filesize($f) === 0) {
            return '';
        }

        $h = fopen($f, 'r');
        fseek($h, max(0, filesize($f) - 16384));
        $isi = stream_get_contents($h);
        fclose($h);

        return implode("\n", array_slice(explode("\n", trim($isi)), -40));
    }
}
