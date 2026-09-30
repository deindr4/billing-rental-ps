<?php

namespace App\Filament\Resources\KategoriUnit\Pages;

use App\Filament\Resources\KategoriUnit\KategoriUnitResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKategoriUnit extends CreateRecord
{
    protected static string $resource = KategoriUnitResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
