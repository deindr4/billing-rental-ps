<?php

namespace App\Providers;

use App\Http\Middleware\SetTenancy;
use App\Models\Sesi;
use App\Models\Unit;
use App\Models\User;
use App\Observers\TvObserver;
use App\Support\Audit;
use App\Support\Tenancy;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /** Rentang IP Cloudflare (cloudflare.com/ips) untuk TRUSTED_PROXIES=cloudflare */
    public const IP_CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public function register(): void
    {
        // Konteks tenant & cabang aktif selama satu request / job (scoped: direset antar job di queue:work)
        $this->app->scoped(Tenancy::class);
    }

    public function boot(): void
    {
        // Terjemahan aplikasi per area (kunci = teks Indonesia): lang/app/{area}/{kode}.json — lihat App\Support\Bahasa
        foreach (glob(lang_path('app/*'), GLOB_ONLYDIR) ?: [] as $folder) {
            $this->app['translator']->addJsonPath($folder);
        }
        \App\Support\Bahasa::terapkan(\App\Support\Bahasa::bawaan());

        // Di belakang Cloudflare / reverse proxy: baca IP asli & skema https dari header X-Forwarded-*
        // TRUSTED_PROXIES boleh berisi kata "cloudflare" (= semua rentang IP Cloudflare), mis. "cloudflare,127.0.0.1"
        if ($proxy = config('billing.proxy_tepercaya')) {
            TrustProxies::at($proxy === '*' ? '*' : collect(explode(',', $proxy))->map(fn ($p) => trim($p))
                ->flatMap(fn ($p) => strtolower($p) === 'cloudflare' ? self::IP_CLOUDFLARE : [$p])->filter()->values()->all());
        }

        // APP_URL https (server cloud): semua tautan, aset Livewire/Filament & redirect selalu https,
        // walau header proxy tidak sampai (mencegah mixed content / aset gagal dimuat di balik Cloudflare)
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Kolom standar tabel yang disinkronkan lokal <-> VPS: $table->syncColumns();
        Blueprint::macro('syncColumns', function () {
            /** @var Blueprint $this */
            $this->string('origin', 10)->default('local'); // local | cloud
            $this->unsignedInteger('version')->default(1);
            $this->timestamp('synced_at')->nullable();
            $this->index('synced_at');
        });

        // Batas request API TV. Kunci diberi awalan per limiter supaya hitungan tiap endpoint terpisah
        // (tanpa ini /pairing ikut terhitung oleh /pairing/cek yang dipanggil tiap 3 detik).
        $perTv = fn (Request $r, string $nama) => $nama.'|'.($r->bearerToken() ? hash('sha256', $r->bearerToken()) : $r->ip());
        RateLimiter::for('tv-pairing', fn (Request $r) => Limit::perMinute(10)->by('tv-pairing|'.$r->ip()));
        RateLimiter::for('tv-pairing-cek', fn (Request $r) => Limit::perMinute(60)->by('tv-pairing-cek|'.$r->ip()));
        RateLimiter::for('tv', fn (Request $r) => Limit::perMinute(120)->by($perTv($r, 'tv')));
        RateLimiter::for('tv-unduh', fn (Request $r) => Limit::perMinute(10)->by($perTv($r, 'tv-unduh')));

        // Audit login / logout / login gagal (operator & admin)
        Event::listen(Login::class, function (Login $e) {
            Audit::catat('login', 'Login '.$e->user->name, null, ['guard' => $e->guard], anomali: false,
                tenantId: $e->user->tenant_id, cabangId: null, userId: $e->user->getAuthIdentifier());
        });
        Event::listen(Logout::class, function (Logout $e) {
            if ($e->user) {
                Audit::catat('logout', 'Logout '.$e->user->name, null, [], anomali: false,
                    tenantId: $e->user->tenant_id, userId: $e->user->getAuthIdentifier());
            }
        });
        Event::listen(Failed::class, function (Failed $e) {
            $login = (string) ($e->credentials['username'] ?? $e->credentials['email'] ?? '-');
            Audit::catat('login_gagal', "Login gagal: {$login}", null, ['login' => $login],
                tenantId: $e->user?->tenant_id, userId: null);
        });

        // Perubahan sesi/unit -> sinyal realtime ke TV Agent
        Sesi::observe(TvObserver::class);
        Unit::observe(TvObserver::class);

        // Unggahan sementara Livewire: bawaan 12 MB & 5 menit; APK TV Agent & file backup bisa sampai 200 MB.
        // Batas per kolom tetap diatur di form masing-masing (wallpaper 8 MB, APK 200 MB, backup 200 MB).
        config([
            'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:204800'],
            'livewire.temporary_file_upload.max_upload_time' => 30,
        ]);

        // Tenancy tetap aktif saat aksi Livewire (klik tombol, submit form)
        Livewire::addPersistentMiddleware([
            SetTenancy::class,
        ]);

        // Super admin & Owner selalu boleh semua aksi
        Gate::before(function (User $user) {
            if ($user->isSuperAdmin() || $user->hasRole('Owner')) {
                return true;
            }

            return null;
        });
    }
}
