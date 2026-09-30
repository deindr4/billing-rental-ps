<?php

namespace App\Filament\Resources\KategoriProduk\Pages;

use App\Filament\Resources\KategoriProduk\KategoriProdukResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKategoriProduk extends CreateRecord
{
    protected static string $resource = KategoriProdukResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
