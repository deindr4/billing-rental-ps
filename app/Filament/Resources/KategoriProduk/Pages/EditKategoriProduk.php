<?php

namespace App\Filament\Resources\KategoriProduk\Pages;

use App\Filament\Resources\KategoriProduk\KategoriProdukResource;
use Filament\Resources\Pages\EditRecord;

class EditKategoriProduk extends EditRecord
{
    protected static string $resource = KategoriProdukResource::class;

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
