<?php

namespace App\Filament\Resources\Iklan\Pages;

use App\Filament\Resources\Iklan\IklanResource;
use App\Models\Iklan;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

class EditIklan extends EditRecord
{
    protected static string $resource = IklanResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /** Gambar baru (jika diunggah) menggantikan gambar lama */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $berkas = $data['berkas'] ?? null;
        unset($data['berkas']);

        if ($berkas) {
            $disk = Storage::disk('local');
            $data += Iklan::dataGambar($disk->path($berkas));
            $disk->delete($berkas);
        }

        return $data;
    }
}
