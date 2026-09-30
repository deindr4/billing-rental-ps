<?php

namespace App\Filament\Pages;

use App\Models\ServerSinkron;
use App\Models\Tenant;
use App\Services\Sinkron\SinkronService;
use App\Services\StatusSistemService;
use App\Support\Audit;
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

    /** Token yang dibuat di server lokal lalu ditempel di cloud (opsional) */
    public ?string $tokenServer = '';

    /** Token acak yang baru dibuat di server lokal: tampil terbuka supaya bisa disalin */
    public bool $tokenTerlihat = false;

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
        $this->tokenTerlihat = false;
        app(StatusSistemService::class)->lupakan();

        Notification::make()->title('Pengaturan sinkron disimpan')->success()->send();
    }

    /** Isi kolom token dengan token acak baru (belum disimpan; daftarkan juga di cloud) */
    public function buatTokenAcak(): void
    {
        abort_if($this->diCloud(), 403);
        $this->token = ServerSinkron::tokenAcak();
        $this->tokenTerlihat = true;
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
        $this->tokenServer = trim((string) $this->tokenServer);
        $this->validate([
            'namaServer' => 'required|string|min:3|max:100',
            'tenantServer' => 'nullable|uuid',
            'tokenServer' => ['nullable', 'regex:'.ServerSinkron::POLA_TOKEN],
        ], ['tokenServer.regex' => 'Token tidak valid. Salin utuh dari server lokal (diawali sk_, 51 karakter).']);

        if ($this->tokenServer !== '' && ServerSinkron::where('token_hash', hash('sha256', $this->tokenServer))->exists()) {
            $this->addError('tokenServer', 'Token ini sudah terdaftar.');

            return;
        }

        [$server, $token] = ServerSinkron::buat(trim($this->namaServer), $this->tenantServer ?: null, $this->tokenServer ?: null);
        // Token dari server lokal tidak perlu ditampilkan lagi (sudah ada di sana)
        $this->tokenBaru = $this->tokenServer === '' ? $token : null;
        Audit::catat('token_sinkron', ($this->tokenServer === '' ? 'Buat' : 'Daftarkan').' token sinkron: '.$server->nama, $server);
        Notification::make()->title('Server '.$server->nama.' terdaftar')
            ->body($this->tokenServer === '' ? 'Salin token di bawah ke server lokal.' : 'Token dari server lokal sudah berlaku. Klik Tes koneksi di server lokal.')
            ->success()->send();
        $this->namaServer = '';
        $this->tenantServer = null;
        $this->tokenServer = '';
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
