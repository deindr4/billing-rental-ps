<?php

namespace App\Filament\Resources\Pengguna\Pages;

use App\Filament\Resources\Pengguna\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** Pengguna tidak dihapus, cukup dinonaktifkan */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        UserResource::pastikanPinUnik($this->data['pin_baru'] ?? null, $this->record->id);

        return $data;
    }

    protected function afterSave(): void
    {
        UserResource::simpanRoleDanPin($this->record, $this->data);
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
