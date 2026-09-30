<?php

namespace App\Filament\Resources\Role\Pages;

use App\Filament\Resources\Role\RoleResource;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\PermissionRegistrar;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** Role tidak dihapus karena bisa masih dipakai pengguna */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function afterSave(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
