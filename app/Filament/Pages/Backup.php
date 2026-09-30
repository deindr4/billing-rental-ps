<?php

namespace App\Filament\Pages;

use App\Services\BackupService;
use App\Support\Audit;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use UnitEnum;

/**
 * Backup & restore database + unggahan.
 * Server lokal (satu rental): owner & super admin. Server cloud (banyak tenant): hanya super admin.
 */
class Backup extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.backup';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 21;

    protected static ?string $navigationLabel = 'Backup & Restore';

    protected static ?string $title = 'Backup & restore database';

    public int $retensiHari = 14;

    public bool $sertakanUpload = true;

    /** @var TemporaryUploadedFile|null */
    public $fileBackup = null;

    // Pemulihan
    public ?string $pulihkanNama = null;

    public string $konfirmasi = '';

    public string $password = '';

    public bool $pulihkanUpload = true;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user?->isSuperAdmin()) {
            return true;
        }

        // Backup berisi seluruh database: owner hanya di server lokal (satu rental)
        return config('app.mode') !== 'cloud' && (bool) $user?->hasRole('Owner');
    }

    public function mount(BackupService $backup): void
    {
        $this->retensiHari = $backup->retensiHari();
        $this->sertakanUpload = $backup->sertakanUpload();
    }

    public function getDaftarProperty(): Collection
    {
        return app(BackupService::class)->daftar();
    }

    public function getTerakhirProperty(): ?Carbon
    {
        return app(BackupService::class)->terakhir();
    }

    public function buatSekarang(BackupService $backup): void
    {
        try {
            $nama = $backup->buat('manual');
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Backup gagal')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Backup dibuat')->body($nama)->success()->send();
    }

    public function unduh(string $nama, BackupService $backup): ?BinaryFileResponse
    {
        try {
            return response()->download($backup->path($nama));
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        }
    }

    public function hapus(string $nama, BackupService $backup): void
    {
        try {
            $backup->hapus($nama);
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Backup dihapus')->success()->send();
    }

    public function unggah(BackupService $backup): void
    {
        $this->validate(['fileBackup' => 'required|file|max:204800'], ['fileBackup.required' => 'Pilih file backup (.zip).']);

        try {
            $nama = $backup->terimaUnggahan($this->fileBackup->getRealPath());
        } catch (Throwable $e) {
            $this->addError('fileBackup', $e->getMessage());

            return;
        }

        $this->fileBackup = null;
        Notification::make()->title('File backup diunggah')->body($nama.' — pilih "Pulihkan" jika ingin memakainya.')->success()->send();
    }

    public function mulaiPulihkan(string $nama): void
    {
        $this->resetValidation();
        $this->pulihkanNama = $nama;
        $this->konfirmasi = '';
        $this->password = '';
        $this->pulihkanUpload = true;
    }

    public function batalPulihkan(): void
    {
        $this->pulihkanNama = null;
    }

    public function pulihkan(BackupService $backup)
    {
        $this->validate([
            'konfirmasi' => 'required|in:PULIHKAN',
            'password' => 'required|string',
        ], [
            'konfirmasi.in' => 'Ketik PULIHKAN (huruf besar) untuk melanjutkan.',
            'password.required' => 'Masukkan password Anda.',
        ]);

        $user = auth()->user();

        if (! Hash::check($this->password, $user->password)) {
            $this->addError('password', 'Password salah.');

            return null;
        }

        $nama = $this->pulihkanNama;
        $oleh = $user->name;

        try {
            $pengaman = $backup->pulihkanAman($nama, $this->pulihkanUpload);
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Pemulihan gagal')->body($e->getMessage().' — data sebelumnya tetap ada di backup pengaman.')->danger()->persistent()->send();

            return null;
        }

        Audit::catat('pulihkan_backup', "Database dipulihkan dari {$nama} oleh {$oleh} (pengaman: {$pengaman})", null, [], anomali: true);

        // Data pengguna & sesi ikut dipulihkan: login ulang
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect('/admin/login');
    }

    public function simpanPengaturan(BackupService $backup): void
    {
        $this->validate([
            'retensiHari' => 'required|integer|min:1|max:365',
            'sertakanUpload' => 'boolean',
        ]);

        $backup->simpanPengaturan($this->retensiHari, $this->sertakanUpload);
        Notification::make()->title('Pengaturan backup disimpan')->success()->send();
    }
}
