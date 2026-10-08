<?php

namespace App\Filament\Resources\Aksesori\Pages;

use App\Filament\Resources\Aksesori\AksesoriResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAksesori extends CreateRecord
{
    protected static string $resource = AksesoriResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
