<?php

namespace App\Filament\Resources\Karyawan\Pages;

use App\Filament\Resources\Karyawan\KaryawanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKaryawan extends CreateRecord
{
    protected static string $resource = KaryawanResource::class;

    protected function afterCreate(): void
    {
        $this->record->aturPin($this->data['pin_baru'] ?? null);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
