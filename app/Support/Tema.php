<?php

namespace App\Support;

use App\Models\Pengaturan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Tema tampilan per tenant: mode, warna aksen, dan logo.
 */
final class Tema
{
    public const AKSEN_DEFAULT = '#4ade80';

    public const PRESET = [
        'Mint' => '#4ade80',
        'Biru' => '#38bdf8',
        'Oranye' => '#fb923c',
        'Merah' => '#f87171',
        'Kuning' => '#facc15',
    ];

    public const MODE = [
        'dark' => 'Gelap',
        'light' => 'Terang',
        'sistem' => 'Ikuti sistem',
    ];

    public static function mode(): string
    {
        $mode = Pengaturan::ambil('tema.mode', 'dark');

        return array_key_exists($mode, self::MODE) ? $mode : 'dark';
    }

    public static function aksen(): string
    {
        $hex = Pengaturan::ambil('tema.aksen', self::AKSEN_DEFAULT);

        return preg_match('/^#[0-9a-f]{6}$/i', (string) $hex) ? strtolower($hex) : self::AKSEN_DEFAULT;
    }

    public static function aksenKontras(): string
    {
        return self::kontras(self::aksen());
    }

    /** Logo aplikasi bawaan (public/logo-192.png) — dipakai bila rental belum mengunggah logo sendiri */
    public static function logoBawaan(): string
    {
        return '/logo-192.png';
    }

    /** Logo rental, atau logo aplikasi bila belum diunggah */
    public static function logoAtauBawaan(): string
    {
        return self::logoUrl() ?? self::logoBawaan();
    }

    public static function logoUrl(): ?string
    {
        $path = Pengaturan::ambil('tema.logo');

        return $path && Storage::disk('public')->exists($path)
            ? Storage::disk('public')->url($path)
            : null;
    }

    /** Warna teks di atas warna aksen: gelap untuk aksen terang, putih untuk aksen gelap */
    public static function kontras(string $hex): string
    {
        return self::luminansi($hex) > 0.35 ? '#0a1420' : '#ffffff';
    }

    /** Peringatan jika aksen sulit terbaca di mode yang dipilih */
    public static function peringatan(string $hex, string $mode): ?string
    {
        $lum = self::luminansi($hex);

        if ($mode !== 'light' && $lum < 0.08) {
            return 'Warna ini terlalu gelap, sulit terlihat di mode gelap.';
        }

        if ($mode === 'light' && $lum > 0.75) {
            return 'Warna ini terlalu terang, sulit terlihat di mode terang.';
        }

        return null;
    }

    public static function luminansi(string $hex): float
    {
        $hex = ltrim($hex, '#');

        if (! preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return 0.5;
        }

        [$r, $g, $b] = array_map(fn ($c) => hexdec($c) / 255, str_split($hex, 2));
        $lin = fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    /**
     * Simpan logo: diperkecil maksimal 512px, dikonversi ke WebP (transparansi tetap).
     * Return path relatif di disk public.
     */
    public static function simpanLogo(string $fileSumber, string $tenantId): string
    {
        $gambar = @imagecreatefromstring((string) file_get_contents($fileSumber));

        if (! $gambar) {
            throw new RuntimeException('Gambar tidak bisa dibaca.');
        }

        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);
        $skala = min(1, 512 / max($lebar, $tinggi));
        $lebarBaru = max(1, (int) round($lebar * $skala));
        $tinggiBaru = max(1, (int) round($tinggi * $skala));

        $baru = imagecreatetruecolor($lebarBaru, $tinggiBaru);
        imagealphablending($baru, false);
        imagesavealpha($baru, true);
        imagefilledrectangle($baru, 0, 0, $lebarBaru, $tinggiBaru, imagecolorallocatealpha($baru, 0, 0, 0, 127));
        imagecopyresampled($baru, $gambar, 0, 0, 0, 0, $lebarBaru, $tinggiBaru, $lebar, $tinggi);

        ob_start();
        imagewebp($baru, null, 80);
        $webp = (string) ob_get_clean();

        imagedestroy($gambar);
        imagedestroy($baru);

        $path = "tenants/{$tenantId}/logo-".Str::lower(Str::random(8)).'.webp';
        Storage::disk('public')->put($path, $webp);

        return $path;
    }

    public static function hapusFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
