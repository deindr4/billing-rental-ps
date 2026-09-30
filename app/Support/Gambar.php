<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Simpan gambar upload sebagai WebP terkompresi (tanpa package tambahan, pakai GD).
 */
final class Gambar
{
    /** Preset kompresi tinggi: [sisi maksimal px, kualitas WebP] */
    public const WALLPAPER = [1920, 68];   // layar TV 16:9, ±80–200 KB

    public const FOTO = [1280, 62];        // foto nota / aset, tetap terbaca

    public const IKLAN = [1200, 70];

    /** Return path relatif di disk public */
    public static function simpanWebp(string $fileSumber, string $folder, int $sisiMaks = self::FOTO[0], int $kualitas = self::FOTO[1]): string
    {
        $webp = self::keWebp($fileSumber, $sisiMaks, $kualitas);

        $path = trim($folder, '/').'/'.now()->format('Ymd').'-'.Str::lower(Str::random(10)).'.webp';
        Storage::disk('public')->put($path, $webp);

        return $path;
    }

    /** Isi file WebP terkompresi (tanpa disimpan), misal untuk disimpan di database */
    public static function keWebp(string $fileSumber, int $sisiMaks = self::FOTO[0], int $kualitas = self::FOTO[1]): string
    {
        $gambar = @imagecreatefromstring((string) file_get_contents($fileSumber));

        if (! $gambar) {
            throw new RuntimeException('Gambar tidak bisa dibaca.');
        }

        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);
        $skala = min(1, $sisiMaks / max($lebar, $tinggi));
        $lebarBaru = max(1, (int) round($lebar * $skala));
        $tinggiBaru = max(1, (int) round($tinggi * $skala));

        $baru = imagecreatetruecolor($lebarBaru, $tinggiBaru);
        imagealphablending($baru, false);
        imagesavealpha($baru, true);
        imagefilledrectangle($baru, 0, 0, $lebarBaru, $tinggiBaru, imagecolorallocatealpha($baru, 0, 0, 0, 127));
        imagecopyresampled($baru, $gambar, 0, 0, 0, 0, $lebarBaru, $tinggiBaru, $lebar, $tinggi);

        ob_start();
        imagewebp($baru, null, $kualitas);
        $webp = (string) ob_get_clean();

        imagedestroy($gambar);
        imagedestroy($baru);

        return $webp;
    }
}
