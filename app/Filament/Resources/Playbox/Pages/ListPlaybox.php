<?php

namespace App\Filament\Resources\Playbox\Pages;

use App\Filament\Resources\Playbox\PlayboxResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlaybox extends ListRecords
{
    protected static string $resource = PlayboxResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
