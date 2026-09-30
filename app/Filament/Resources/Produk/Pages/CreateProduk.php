<?php

namespace App\Filament\Resources\Produk\Pages;

use App\Filament\Resources\Produk\ProdukResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduk extends CreateRecord
{
    protected static string $resource = ProdukResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
