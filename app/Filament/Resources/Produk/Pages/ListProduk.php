<?php

namespace App\Filament\Resources\Produk\Pages;

use App\Filament\Resources\Produk\ProdukResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProduk extends ListRecords
{
    protected static string $resource = ProdukResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
