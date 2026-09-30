<?php

namespace App\Filament\Resources\KategoriUnit\Pages;

use App\Filament\Resources\KategoriUnit\KategoriUnitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKategoriUnit extends ListRecords
{
    protected static string $resource = KategoriUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
