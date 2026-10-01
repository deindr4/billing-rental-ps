<?php

namespace App\Filament\Resources\RilisApk\Pages;

use App\Filament\Resources\PerangkatTv\PerangkatTvResource;
use App\Filament\Resources\RilisApk\RilisApkResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRilisApk extends ListRecords
{
    protected static string $resource = RilisApkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pushUpdate')
                ->label('Push update ke semua TV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => PerangkatTvResource::versiTerbaru() !== null)
                ->modalHeading(fn () => 'Push APK '.PerangkatTvResource::versiTerbaru().' ke semua TV')
                ->modalDescription(fn () => PerangkatTvResource::keteranganPush()
                    .(auth()->user()->isSuperAdmin() ? ' Berlaku untuk TV semua rental.' : ''))
                ->schema([PerangkatTvResource::isianPaksa()])
                ->modalSubmitActionLabel('Push')
                ->action(fn (array $data) => PerangkatTvResource::push(RilisApkResource::perangkat()->aktif()->get(), (bool) $data['paksa'])),

            CreateAction::make()->label('Unggah rilis baru'),
        ];
    }
}
