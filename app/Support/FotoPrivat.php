<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Foto data pribadi (KTP, wajah penyewa, kondisi barang sewa, tanda tangan): dikompres WebP dan disimpan di disk
 * PRIVAT (storage/app/private) — tidak bisa dibuka lewat URL publik. Ditampilkan lewat route berkas.privat (berizin).
 */
final class FotoPrivat
{
    public static function simpan(string $sumber, string $tenantId, string $folder, int $sisiMaks = 1280, int $kualitas = 65): string
    {
        $path = "tenants/{$tenantId}/{$folder}/".now()->format('Ym').'/'.Str::lower(Str::random(20)).'.webp';
        Storage::disk('local')->put($path, Gambar::keWebp($sumber, $sisiMaks, $kualitas));

        return $path;
    }

    /** Tanda tangan dari canvas (data:image/png;base64,...) */
    public static function simpanTandaTangan(string $dataUrl, string $tenantId): ?string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            return null;
        }

        $png = base64_decode($m[1], true);

        if ($png === false || strlen($png) > 512 * 1024 || ! @imagecreatefromstring($png)) {
            return null;
        }

        $path = "tenants/{$tenantId}/tanda-tangan/".now()->format('Ym').'/'.Str::lower(Str::random(20)).'.png';
        Storage::disk('local')->put($path, $png);

        return $path;
    }

    /** URL berizin untuk ditampilkan di aplikasi */
    public static function url(?string $path): ?string
    {
        return $path ? route('berkas.privat', ['path' => $path]) : null;
    }
}
