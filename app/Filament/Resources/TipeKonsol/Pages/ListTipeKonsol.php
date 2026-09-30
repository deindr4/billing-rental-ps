<?php

namespace App\Filament\Resources\TipeKonsol\Pages;

use App\Filament\Resources\TipeKonsol\TipeKonsolResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTipeKonsol extends ListRecords
{
    protected static string $resource = TipeKonsolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
