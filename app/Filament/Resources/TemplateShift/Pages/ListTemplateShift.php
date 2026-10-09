<?php

namespace App\Filament\Resources\TemplateShift\Pages;

use App\Filament\Resources\TemplateShift\TemplateShiftResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTemplateShift extends ListRecords
{
    protected static string $resource = TemplateShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
