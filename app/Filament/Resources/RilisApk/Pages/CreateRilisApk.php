<?php

namespace App\Filament\Resources\RilisApk\Pages;

use App\Filament\Resources\RilisApk\RilisApkResource;
use App\Services\Tv\RilisApkService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateRilisApk extends CreateRecord
{
    protected static string $resource = RilisApkResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + app(RilisApkService::class)->metaFile($data['file']) + ['dibuat_oleh' => auth()->id()];
    }

    protected function afterCreate(): void
    {
        if (! $this->record->aktif) {
            return;
        }

        $jumlah = app(RilisApkService::class)->beriTahuSemuaTv();

        Notification::make()
            ->title("Rilis {$this->record->versi_nama} diumumkan ke {$jumlah} TV")
            ->body('TV yang online akan memeriksa & mengunduh update.')
            ->success()
            ->send();
    }
}
