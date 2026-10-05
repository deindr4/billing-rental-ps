<?php

namespace App\Support;

/**
 * Paket Wake-on-LAN (magic packet): 6 × 0xFF lalu MAC 16 kali, UDP broadcast port 9.
 * Butuh ekstensi PHP sockets (broadcast); tanpa itu return false dan pemanggil menitipkannya ke PC lain.
 */
final class WakeOnLan
{
    /** "AA-BB-CC-DD-EE-FF" / "aa:bb:cc:dd:ee:ff" → "AA:BB:CC:DD:EE:FF", null bila tidak valid */
    public static function rapikanMac(?string $mac): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));

        if (strlen($hex) !== 12 || $hex === '000000000000' || $hex === 'FFFFFFFFFFFF') {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    public static function paket(string $mac): string
    {
        $biner = hex2bin(str_replace(':', '', $mac));

        return str_repeat("\xFF", 6).str_repeat($biner, 16);
    }

    public static function kirim(string $mac, string $alamat = '255.255.255.255', int $port = 9): bool
    {
        $mac = self::rapikanMac($mac);

        if (! $mac || ! extension_loaded('sockets')) {
            return false;
        }

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if (! $socket) {
            return false;
        }

        try {
            socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
            $paket = self::paket($mac);

            return @socket_sendto($socket, $paket, strlen($paket), 0, $alamat, $port) === strlen($paket);
        } finally {
            socket_close($socket);
        }
    }
}
