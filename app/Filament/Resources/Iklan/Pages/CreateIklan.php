<?php

namespace App\Filament\Resources\Iklan\Pages;

use App\Filament\Resources\Iklan\IklanResource;
use App\Models\Iklan;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateIklan extends CreateRecord
{
    protected static string $resource = IklanResource::class;

    /** File unggahan dikompres ke WebP & disimpan di database, file sementara dihapus */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $berkas = $data['berkas'] ?? null;
        unset($data['berkas']);

        $disk = Storage::disk('local');
        $data += Iklan::dataGambar($disk->path($berkas));
        $disk->delete($berkas);

        return $data;
    }
}
