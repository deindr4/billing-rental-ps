<?php

namespace App\Filament\Resources\TipeKonsol\Pages;

use App\Filament\Resources\TipeKonsol\TipeKonsolResource;
use Filament\Resources\Pages\EditRecord;

class EditTipeKonsol extends EditRecord
{
    protected static string $resource = TipeKonsolResource::class;

    /** Data tidak dihapus, cukup dinonaktifkan */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
