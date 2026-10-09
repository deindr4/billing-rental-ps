<?php

namespace App\Filament\Resources\Karyawan\Pages;

use App\Filament\Resources\Karyawan\KaryawanResource;
use Filament\Resources\Pages\EditRecord;

class EditKaryawan extends EditRecord
{
    protected static string $resource = KaryawanResource::class;

    /** Data karyawan tidak dihapus (riwayat absensi & gaji), cukup dinonaktifkan / diisi tanggal keluar */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function afterSave(): void
    {
        $this->record->aturPin($this->data['pin_baru'] ?? null);
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
