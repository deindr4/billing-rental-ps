<?php

namespace App\Filament\Resources\TemplateShift\Pages;

use App\Filament\Resources\TemplateShift\TemplateShiftResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTemplateShift extends CreateRecord
{
    protected static string $resource = TemplateShiftResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
