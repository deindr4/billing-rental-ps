<?php

namespace App\Filament\Resources\Pengguna\Pages;

use App\Filament\Resources\Pengguna\UserResource;
use App\Support\Tenancy;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        UserResource::pastikanPinUnik($this->data['pin_baru'] ?? null);

        $data['tenant_id'] = app(Tenancy::class)->tenantId();
        $data['is_super_admin'] = false;

        return $data;
    }

    protected function afterCreate(): void
    {
        UserResource::simpanRoleDanPin($this->record, $this->data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
