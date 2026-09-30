<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Backup;
use App\Services\BackupService;
use App\Services\Sinkron\SinkronService;
use App\Services\StatusSistemService;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Throwable;

/**
 * Kesehatan sistem: server, database, cloud/VPS, sinkronisasi, antrean, realtime, backup, TV.
 */
class StatusSistem extends Widget
{
    protected string $view = 'filament.widgets.status-sistem';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->hasRole('Owner') || $user?->can('admin.pengaturan'));
    }

    public function getStatus(): array
    {
        return app(StatusSistemService::class)->semua();
    }

    public function bisaBackup(): bool
    {
        return Backup::canAccess();
    }

    public function periksaUlang(): void
    {
        app(StatusSistemService::class)->lupakan();
        Notification::make()->title('Status diperbarui')->success()->send();
    }

    public function tesCloud(): void
    {
        $hasil = app(StatusSistemService::class)->cloud(paksa: true);

        Notification::make()
            ->title($hasil['judul'].': '.$hasil['nilai'])
            ->body($hasil['detail'])
            ->{$hasil['status'] === 'ok' ? 'success' : ($hasil['status'] === 'info' ? 'info' : 'danger')}()
            ->send();
    }

    public function sinkron(): void
    {
        $sinkron = app(SinkronService::class);

        if (! $sinkron->siap()) {
            Notification::make()
                ->title($sinkron->diCloud() ? 'Ini server cloud' : 'Sinkronisasi belum diaktifkan')
                ->body($sinkron->diCloud()
                    ? 'Sinkron dijalankan dari server lokal rental.'
                    : 'Isi alamat cloud & token di Pengaturan → Sinkronisasi.')
                ->warning()
                ->send();

            return;
        }

        try {
            $h = $sinkron->jalankan();
        } catch (Throwable $e) {
            Notification::make()->title('Sinkron gagal')->body($e->getMessage())->danger()->send();

            return;
        }

        app(StatusSistemService::class)->lupakan();
        Notification::make()->title('Sinkron selesai')
            ->body("Terkirim {$h['dorong']} · diterima {$h['tarik']}".($h['ditolak'] ? " · ditolak {$h['ditolak']}" : ''))
            ->success()->send();
    }

    public function backupSekarang(): void
    {
        abort_unless($this->bisaBackup(), 403);

        try {
            $nama = app(BackupService::class)->buat('manual');
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Backup gagal')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Backup dibuat')->body($nama)->success()->send();
    }
}
