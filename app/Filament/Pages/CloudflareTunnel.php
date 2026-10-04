<?php

namespace App\Filament\Pages;

use App\Support\CloudflareTunnel as Tunnel;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\File;
use UnitEnum;

/**
 * Akses aplikasi PC rental dari internet (domain sendiri, HTTPS) lewat Cloudflare Tunnel —
 * tanpa memasang cloudflared manual / membuka port router. Hanya di pemasangan Windows (installer).
 */
class CloudflareTunnel extends Page
{
    protected string $view = 'filament.pages.cloudflare-tunnel';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Cloudflare Tunnel';

    protected static ?string $title = 'Cloudflare Tunnel (akses dari internet)';

    public string $isian = '';

    /** Membuka PC rental ke internet: hanya owner / super admin, hanya di pemasangan Windows */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->isSuperAdmin() || (bool) $user?->hasRole('Owner')) && app(Tunnel::class)->tersedia();
    }

    public function aktifkan(): void
    {
        abort_unless(static::canAccess(), 403);
        $tunnel = app(Tunnel::class);
        $token = Tunnel::ambilToken($this->isian);

        // Isian kosong & token sudah tersimpan: nyalakan lagi dengan token lama
        if (! $token && blank($this->isian) && $tunnel->adaToken()) {
            $token = trim(File::get($tunnel->fileToken()));
        }

        if (! $token) {
            Notification::make()->title('Token tidak dikenali')
                ->body('Salin perintah dari dashboard Cloudflare (yang berisi "eyJ..."), lalu tempel utuh di sini.')->danger()->send();

            return;
        }

        $error = $tunnel->aktifkan($token);
        $this->isian = '';

        $error
            ? Notification::make()->title('Tunnel gagal dinyalakan')->body($error)->danger()->send()
            : Notification::make()->title('Tunnel dinyalakan')->body('Tersambung dalam beberapa detik. Menyala otomatis setiap PC dinyalakan.')->success()->send();
    }

    public function matikan(): void
    {
        abort_unless(static::canAccess(), 403);
        app(Tunnel::class)->matikan();
        Notification::make()->title('Tunnel dimatikan')->body('Aplikasi tidak bisa dibuka dari internet. Jaringan lokal tetap normal.')->success()->send();
    }

    public function hapusToken(): void
    {
        abort_unless(static::canAccess(), 403);
        app(Tunnel::class)->matikan(hapusToken: true);
        Notification::make()->title('Tunnel dimatikan & token dihapus')->success()->send();
    }

    protected function getViewData(): array
    {
        $tunnel = app(Tunnel::class);
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);

        return [
            'status' => $tunnel->status(),
            'adaToken' => $tunnel->adaToken(),
            'idTunnel' => $tunnel->idTunnel(),
            'log' => $tunnel->logTerakhir(),
            'layananLokal' => 'http://localhost'.($port ? ':'.$port : ''),
            // Kosong = lewat tunnel aset & Livewire dimuat via http di halaman https → diblokir browser → login gagal
            'proxyTepercaya' => (string) config('billing.proxy_tepercaya'),
        ];
    }
}
