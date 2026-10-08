<?php

namespace App\Filament\Resources\Aksesori\Pages;

use App\Filament\Resources\Aksesori\AksesoriResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAksesori extends ListRecords
{
    protected static string $resource = AksesoriResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
