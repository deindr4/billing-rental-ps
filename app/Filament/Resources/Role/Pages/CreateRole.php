<?php

namespace App\Filament\Resources\Role\Pages;

use App\Filament\Resources\Role\RoleResource;
use App\Support\Tenancy;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\PermissionRegistrar;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = app(Tenancy::class)->tenantId();
        $data['guard_name'] = 'web';

        return $data;
    }

    protected function afterCreate(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
