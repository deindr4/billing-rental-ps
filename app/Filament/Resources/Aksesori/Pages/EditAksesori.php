<?php

namespace App\Filament\Resources\Aksesori\Pages;

use App\Filament\Resources\Aksesori\AksesoriResource;
use Filament\Resources\Pages\EditRecord;

class EditAksesori extends EditRecord
{
    protected static string $resource = AksesoriResource::class;

    /** Data tidak dihapus (riwayat sewa), cukup dinonaktifkan */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
