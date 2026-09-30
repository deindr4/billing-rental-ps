<?php

namespace App\Filament\Resources\KategoriProduk\Pages;

use App\Filament\Resources\KategoriProduk\KategoriProdukResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKategoriProduk extends ListRecords
{
    protected static string $resource = KategoriProdukResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
