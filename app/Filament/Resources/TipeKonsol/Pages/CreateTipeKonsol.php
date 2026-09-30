<?php

namespace App\Filament\Resources\TipeKonsol\Pages;

use App\Filament\Resources\TipeKonsol\TipeKonsolResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTipeKonsol extends CreateRecord
{
    protected static string $resource = TipeKonsolResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
