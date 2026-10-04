<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Batas login gagal per IP (kasir & panel admin): 3x gagal -> IP diblokir 15 menit.
 * Localhost & jaringan lokal (LAN rental) dikecualikan — di sana tetap ada batas ringan per akun di halaman login.
 */
final class BatasLogin
{
    public const MAKS_GAGAL = 3;

    public const BLOKIR_DETIK = 900;

    private const JARINGAN_LOKAL = [
        '127.0.0.0/8', '::1',                                  // localhost
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',        // LAN IPv4
        '169.254.0.0/16', 'fc00::/7', 'fe80::/10',              // link-local & LAN IPv6
    ];

    /**
     * Pengunjung dari localhost / LAN. Permintaan yang membawa header proxy (Cloudflare, Nginx) tapi proxy-nya
     * tidak dipercaya (TRUSTED_PROXIES kosong) TIDAK dianggap lokal: tanpa ini semua pengunjung internet
     * tampak 127.0.0.1 di belakang tunnel dan lolos dari batas.
     */
    public static function ipLokal(Request $r): bool
    {
        $lewatProxy = $r->headers->has('X-Forwarded-For') || $r->headers->has('CF-Connecting-IP') || $r->headers->has('X-Real-IP');

        if ($lewatProxy && ! $r->isFromTrustedProxy()) {
            return false;
        }

        return IpUtils::checkIp(self::ipAsli($r), self::JARINGAN_LOKAL);
    }

    /**
     * IP pengunjung. Lewat Cloudflare Tunnel / proxy Cloudflare yang tepercaya, IP asli ada di CF-Connecting-IP
     * (cloudflared sendiri 127.0.0.1) — dipakai juga untuk kunci blokir supaya pengunjung tunnel tidak berbagi satu kunci.
     */
    public static function ipAsli(Request $r): string
    {
        $cf = $r->headers->get('CF-Connecting-IP');

        return $cf && $r->isFromTrustedProxy() && filter_var($cf, FILTER_VALIDATE_IP) ? $cf : (string) $r->ip();
    }

    /** IP ini bebas dari batas 3x (LOGIN_BEBAS_LOKAL=true & dari localhost / LAN) */
    public static function bebas(Request $r): bool
    {
        return config('billing.login_bebas_lokal', true) && self::ipLokal($r);
    }

    /** Detik tersisa sampai IP ini boleh mencoba lagi; 0 = boleh */
    public static function sisaBlokir(Request $r): int
    {
        if (self::bebas($r)) {
            return 0;
        }

        $kunci = self::kunci($r);

        return RateLimiter::tooManyAttempts($kunci, self::MAKS_GAGAL) ? max(1, RateLimiter::availableIn($kunci)) : 0;
    }

    public static function gagal(Request $r): void
    {
        if (! self::bebas($r)) {
            RateLimiter::hit(self::kunci($r), self::BLOKIR_DETIK);
        }
    }

    public static function berhasil(Request $r): void
    {
        RateLimiter::clear(self::kunci($r));
    }

    public static function pesan(int $detik): string
    {
        return 'Terlalu banyak percobaan login gagal. Coba lagi dalam '.max(1, (int) ceil($detik / 60)).' menit.';
    }

    private static function kunci(Request $r): string
    {
        return 'login-gagal:'.self::ipAsli($r);
    }
}
