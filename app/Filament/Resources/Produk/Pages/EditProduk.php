<?php

namespace App\Filament\Resources\Produk\Pages;

use App\Filament\Resources\Produk\ProdukResource;
use Filament\Resources\Pages\EditRecord;

class EditProduk extends EditRecord
{
    protected static string $resource = ProdukResource::class;

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
