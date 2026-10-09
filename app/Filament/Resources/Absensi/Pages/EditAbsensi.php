<?php

namespace App\Filament\Resources\Absensi\Pages;

use App\Filament\Resources\Absensi\AbsensiResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;

class EditAbsensi extends EditRecord
{
    protected static string $resource = AbsensiResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** Hitung ulang terlambat, pulang cepat & lama kerja dari jam yang dikoreksi */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $r = $this->record;
        $masuk = Carbon::parse($data['masuk_pada']);
        $pulang = filled($data['pulang_pada'] ?? null) ? Carbon::parse($data['pulang_pada']) : null;
        $toleransi = (int) ($r->template?->toleransi_menit ?? 0);

        $data['tanggal'] = $masuk->toDateString();
        $data['terlambat_menit'] = $r->jadwal_mulai && $masuk->gt($r->jadwal_mulai->copy()->addMinutes($toleransi))
            ? (int) $r->jadwal_mulai->diffInMinutes($masuk) : 0;
        $data['menit_kerja'] = $pulang ? (int) $masuk->diffInMinutes($pulang) : null;
        $data['pulang_cepat_menit'] = $pulang && $r->jadwal_selesai && $pulang->lt($r->jadwal_selesai)
            ? (int) $pulang->diffInMinutes($r->jadwal_selesai) : 0;

        return $data;
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
