<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Koordinat lokasi penyewa tanpa API berbayar: diurai dari teks "lat,lng" atau link share Google Maps
 * (…?q=lat,lng · …/@lat,lng,17z · …!3dlat!4dlng · maps.app.goo.gl/… diikuti redirect-nya), lalu dibuka lewat
 * https://www.google.com/maps?q=lat,lng.
 */
final class Koordinat
{
    /** @return array{0: float, 1: float}|null */
    public static function urai(?string $teks): ?array
    {
        $teks = trim((string) $teks);

        if ($teks === '') {
            return null;
        }

        // Link pendek (maps.app.goo.gl / goo.gl/maps): ikuti redirect untuk mendapat URL lengkap
        if (preg_match('#https?://(maps\.app\.goo\.gl|goo\.gl/maps)/\S+#i', $teks, $m)) {
            $teks = self::ikutiRedirect($m[0]) ?? $teks;
        }

        $pola = [
            '/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/',           // data pin
            '/[?&](?:q|query|ll|destination)=(-?\d{1,2}\.\d+),\s*(-?\d{1,3}\.\d+)/',
            '/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/',                // tengah peta
            '/^\s*(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)\s*$/',   // "lat, lng"
        ];

        foreach ($pola as $p) {
            if (preg_match($p, rawurldecode($teks), $m)) {
                [$lat, $lng] = [(float) $m[1], (float) $m[2]];

                if (abs($lat) <= 90 && abs($lng) <= 180) {
                    return [round($lat, 7), round($lng, 7)];
                }
            }
        }

        return null;
    }

    public static function urlMaps(float|string|null $lat, float|string|null $lng): ?string
    {
        return $lat !== null && $lng !== null ? 'https://www.google.com/maps?q='.((float) $lat).','.((float) $lng) : null;
    }

    private static function ikutiRedirect(string $url): ?string
    {
        try {
            $akhir = null;
            Http::timeout(8)->withOptions([
                'allow_redirects' => ['max' => 5, 'track_redirects' => true],
                'on_stats' => function ($stats) use (&$akhir) {
                    $akhir = (string) $stats->getEffectiveUri();
                },
            ])->get($url);

            return $akhir;
        } catch (Throwable) {
            return null;
        }
    }
}
