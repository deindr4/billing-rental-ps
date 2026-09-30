<?php

namespace App\Filament\Resources\PaketHarga\Pages;

use App\Filament\Resources\PaketHarga\PaketHargaResource;
use Filament\Resources\Pages\EditRecord;

class EditPaketHarga extends EditRecord
{
    protected static string $resource = PaketHargaResource::class;

    /** Data tidak dihapus, cukup dinonaktifkan */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
