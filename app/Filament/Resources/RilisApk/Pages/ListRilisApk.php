<?php

namespace App\Filament\Resources\RilisApk\Pages;

use App\Filament\Resources\RilisApk\RilisApkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRilisApk extends ListRecords
{
    protected static string $resource = RilisApkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Unggah rilis baru'),
        ];
    }
}
