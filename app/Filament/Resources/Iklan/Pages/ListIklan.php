<?php

namespace App\Filament\Resources\Iklan\Pages;

use App\Filament\Resources\Iklan\IklanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIklan extends ListRecords
{
    protected static string $resource = IklanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Pasang iklan')];
    }
}
