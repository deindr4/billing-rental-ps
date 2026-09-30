<?php

namespace App\Filament\Resources\RilisApk\Pages;

use App\Filament\Resources\RilisApk\RilisApkResource;
use App\Services\Tv\RilisApkService;
use Filament\Resources\Pages\EditRecord;

class EditRilisApk extends EditRecord
{
    protected static string $resource = RilisApkResource::class;

    protected function afterSave(): void
    {
        // Rilis diaktifkan kembali / diubah jadi wajib: TV perlu memeriksa ulang
        if ($this->record->wasChanged(['aktif', 'wajib']) && $this->record->aktif) {
            app(RilisApkService::class)->beriTahuSemuaTv();
        }
    }
}
