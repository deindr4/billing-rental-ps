<?php

namespace App\Filament\Resources\PaketHarga\Pages;

use App\Filament\Resources\PaketHarga\PaketHargaResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaketHarga extends CreateRecord
{
    protected static string $resource = PaketHargaResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
