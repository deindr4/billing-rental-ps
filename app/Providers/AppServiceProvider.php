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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Konteks tenant & cabang aktif selama satu request / job (scoped: direset antar job di queue:work)
        $this->app->scoped(Tenancy::class);
    }

    public function boot(): void
    {
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

        // Unggahan sementara Livewire: default 12 MB, APK TV Agent bisa lebih besar
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:204800']]);

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
