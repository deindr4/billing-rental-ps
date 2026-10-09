<?php

namespace App\Filament\Resources\Penyewa\Pages;

use App\Filament\Resources\Penyewa\PenyewaResource;
use App\Models\Penyewa;
use App\Support\Koordinat;
use Filament\Resources\Pages\EditRecord;

class EditPenyewa extends EditRecord
{
    protected static string $resource = PenyewaResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        [$lat, $lng] = Koordinat::urai($this->data['koordinat'] ?? null) ?? [null, null];
        $data['lat'] = $lat;
        $data['lng'] = $lng;
        $data['telepon'] = Penyewa::rapikanTelepon($data['telepon'] ?? '');

        if (empty($data['daftar_hitam'])) {
            $data['alasan_daftar_hitam'] = null;
        }

        return $data;
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
