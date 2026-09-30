<?php

namespace App\Filament\Resources\PaketHarga\Pages;

use App\Filament\Resources\PaketHarga\PaketHargaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaketHarga extends ListRecords
{
    protected static string $resource = PaketHargaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
