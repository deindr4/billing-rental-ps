<?php

namespace App\Filament\Pages;

use App\Models\ServerSinkron;
use App\Models\Tenant;
use App\Services\Sinkron\SinkronService;
use App\Services\StatusSistemService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnitEnum;

/**
 * Server lokal: alamat cloud + token, jalankan sinkron, riwayat.
 * Server cloud: kelola token server lokal rental (khusus super admin).
 */
class Sinkronisasi extends Page
{
    protected string $view = 'filament.pages.sinkronisasi';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Sinkronisasi';

    protected static ?string $title = 'Sinkronisasi cloud';

    // Lokal
    public ?string $url = null;

    public ?string $token = null;

    public bool $aktif = false;

    // Cloud
    public string $namaServer = '';

    public ?string $tenantServer = null;

    public ?string $tokenBaru = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (config('app.mode') === 'cloud') {
            return (bool) $user?->isSuperAdmin();
        }

        return (bool) ($user?->isSuperAdmin() || $user?->hasRole('Owner'));
    }

    public function mount(SinkronService $sinkron): void
    {
        $p = $sinkron->pengaturan();
        $this->url = $p['url'];
        $this->aktif = (bool) $p['aktif'];
    }

    public function diCloud(): bool
    {
        return config('app.mode') === 'cloud';
    }

    public function getStatusProperty(): array
    {
        $s = app(SinkronService::class);

        return $s->status() + ['antrean' => $s->jumlahAntrean(), 'punya_token' => (bool) $s->pengaturan()['token']];
    }

    public function getLogProperty(): Collection
    {
        return DB::table('sync_log')->latest('id')->limit(15)->get();
    }

    public function getServerProperty(): Collection
    {
        return ServerSinkron::with('tenant:id,nama')->latest()->get();
    }

    public function getTenantPilihanProperty(): Collection
    {
        return Tenant::query()->orderBy('nama')->pluck('nama', 'id');
    }

    /* ---------------- Lokal ---------------- */

    public function simpan(SinkronService $sinkron): void
    {
        $this->validate([
            'url' => 'nullable|url|max:200',
            'token' => 'nullable|string|max:100',
        ], ['url.url' => 'Alamat harus lengkap, contoh https://rental.domainku.id']);

        $sinkron->simpanPengaturan($this->url, $this->token, $this->aktif);
        $this->token = null;
        app(StatusSistemService::class)->lupakan();

        Notification::make()->title('Pengaturan sinkron disimpan')->success()->send();
    }

    public function tes(SinkronService $sinkron): void
    {
        try {
            $info = $sinkron->tes($this->url ?: null, $this->token ?: null);
        } catch (Throwable $e) {
            Notification::make()->title('Tidak bisa terhubung')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Terhubung ke cloud')
            ->body('Server: '.($info['server'] ?? '-').' · tenant: '.($info['tenant'] ?? 'akan terikat saat sinkron pertama'))
            ->success()->send();
    }

    public function jalankan(SinkronService $sinkron): void
    {
        try {
            $h = $sinkron->jalankan();
        } catch (Throwable $e) {
            Notification::make()->title('Sinkron gagal')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Sinkron selesai')
            ->body("Terkirim {$h['dorong']} · diterima {$h['tarik']}".($h['ditolak'] ? " · ditolak {$h['ditolak']}" : ''))
            ->success()->send();
    }

    public function kirimUlang(SinkronService $sinkron): void
    {
        $n = $sinkron->kirimUlangSemua();
        Notification::make()->title(number_format($n, 0, ',', '.').' data masuk antrean')
            ->body('Semua data akan dikirim ulang pada sinkron berikutnya.')->success()->send();
    }

    public function tarikUlang(SinkronService $sinkron): void
    {
        $sinkron->tarikUlangSemua();
        Notification::make()->title('Semua data cloud akan diambil ulang')
            ->body('Data lokal yang lebih baru tidak ditimpa.')->success()->send();
    }

    /* ---------------- Cloud ---------------- */

    public function buatServer(): void
    {
        abort_unless($this->diCloud() && auth()->user()->isSuperAdmin(), 403);
        $this->validate(['namaServer' => 'required|string|min:3|max:100', 'tenantServer' => 'nullable|uuid']);

        [, $token] = ServerSinkron::buat(trim($this->namaServer), $this->tenantServer ?: null);
        $this->tokenBaru = $token;
        $this->namaServer = '';
        $this->tenantServer = null;
    }

    public function aktifkanServer(string $id, bool $aktif): void
    {
        abort_unless($this->diCloud() && auth()->user()->isSuperAdmin(), 403);
        ServerSinkron::whereKey($id)->update(['is_active' => $aktif]);
    }

    public function hapusServer(string $id): void
    {
        abort_unless($this->diCloud() && auth()->user()->isSuperAdmin(), 403);
        ServerSinkron::whereKey($id)->delete();
        Notification::make()->title('Server dihapus, tokennya tidak berlaku lagi')->success()->send();
    }
}
