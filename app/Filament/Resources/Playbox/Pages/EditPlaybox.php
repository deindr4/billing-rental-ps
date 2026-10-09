<?php

namespace App\Filament\Resources\Playbox\Pages;

use App\Filament\Resources\Playbox\PlayboxResource;
use Filament\Resources\Pages\EditRecord;

class EditPlaybox extends EditRecord
{
    protected static string $resource = PlayboxResource::class;

    /** Tidak dihapus (riwayat sewa), cukup dinonaktifkan */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
