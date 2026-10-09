<?php

namespace App\Filament\Resources\TemplateShift\Pages;

use App\Filament\Resources\TemplateShift\TemplateShiftResource;
use Filament\Resources\Pages\EditRecord;

class EditTemplateShift extends EditRecord
{
    protected static string $resource = TemplateShiftResource::class;

    /** Tidak dihapus (dipakai riwayat absensi), cukup dinonaktifkan */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
