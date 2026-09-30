<?php

namespace App\Services\Tv;

/**
 * Kode darurat untuk membuka TV saat jaringan putus (TV tidak bisa menghubungi server).
 *
 * Algoritma TOTP (RFC 6238) dengan periode 5 menit, 6 digit:
 *   counter = floor(unix_detik / 300), 8 byte big-endian
 *   hmac    = HMAC-SHA1(kunci = rahasia_offline (teks apa adanya), pesan = counter)
 *   offset  = hmac[19] & 0x0F
 *   kode    = ((hmac[offset..offset+3] sebagai int31)) mod 1_000_000, dipad 6 digit
 * APK menerima kode periode sekarang & satu periode sebelumnya.
 */
final class KodeDarurat
{
    public const PERIODE_DETIK = 300;

    public static function buat(string $rahasia, ?int $waktu = null): string
    {
        $counter = intdiv($waktu ?? time(), self::PERIODE_DETIK);
        $hmac = hash_hmac('sha1', pack('J', $counter), $rahasia, true);
        $offset = ord($hmac[19]) & 0x0F;

        $angka = ((ord($hmac[$offset]) & 0x7F) << 24)
            | (ord($hmac[$offset + 1]) << 16)
            | (ord($hmac[$offset + 2]) << 8)
            | ord($hmac[$offset + 3]);

        return str_pad((string) ($angka % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public static function cocok(string $rahasia, string $kode, ?int $waktu = null): bool
    {
        $waktu ??= time();

        foreach ([0, -self::PERIODE_DETIK] as $geser) {
            if (hash_equals(self::buat($rahasia, $waktu + $geser), $kode)) {
                return true;
            }
        }

        return false;
    }

    /** Sisa detik sampai kode berganti */
    public static function sisaDetik(?int $waktu = null): int
    {
        return self::PERIODE_DETIK - (($waktu ?? time()) % self::PERIODE_DETIK);
    }
}
