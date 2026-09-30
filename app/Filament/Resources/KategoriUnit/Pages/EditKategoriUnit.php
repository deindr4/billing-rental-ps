<?php

namespace App\Filament\Resources\KategoriUnit\Pages;

use App\Filament\Resources\KategoriUnit\KategoriUnitResource;
use Filament\Resources\Pages\EditRecord;

class EditKategoriUnit extends EditRecord
{
    protected static string $resource = KategoriUnitResource::class;

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
