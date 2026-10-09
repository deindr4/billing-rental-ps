<?php

namespace App\Filament\Resources\Playbox\Pages;

use App\Filament\Resources\Playbox\PlayboxResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlaybox extends CreateRecord
{
    protected static string $resource = PlayboxResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
