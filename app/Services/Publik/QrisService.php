<?php

namespace App\Services\Publik;

use App\Exceptions\BillingException;
use App\Models\Pengaturan;

/**
 * QRIS dinamis dari QRIS statis milik rental (standar EMVCo MPM).
 *
 * QRIS statis (stiker di kasir) diubah: tag 01 "11" -> "12" (dinamis), sisipkan tag 54 (nominal),
 * lalu hitung ulang CRC16-CCITT (tag 63). Pelanggan scan -> nominal otomatis terisi di aplikasi bank/e-wallet.
 * Dana tetap masuk ke rekening merchant QRIS rental; kasir mengonfirmasi setelah notifikasi masuk.
 */
final class QrisService
{
    /** QRIS statis cabang (null = belum diatur / nonaktif) */
    public function payloadCabang(?string $cabangId): ?string
    {
        if (! $cabangId || ! Pengaturan::ambil('qris.aktif', false, $cabangId)) {
            return null;
        }

        $p = trim((string) Pengaturan::ambil('qris.payload', '', $cabangId));

        return $p !== '' && $this->valid($p) ? $p : null;
    }

    /** QRIS dinamis untuk nominal tertentu, atau null bila QRIS cabang belum diatur */
    public function untukNominal(?string $cabangId, int $nominal): ?string
    {
        $p = $this->payloadCabang($cabangId);

        return $p && $nominal > 0 ? $this->dinamis($p, $nominal) : null;
    }

    public function valid(string $payload): bool
    {
        $payload = trim($payload);

        if (! str_starts_with($payload, '000201') || strlen($payload) < 30 || ! preg_match('/6304[0-9A-Fa-f]{4}$/', $payload)) {
            return false;
        }

        return strtoupper(substr($payload, -4)) === $this->crc(substr($payload, 0, -4));
    }

    /** @return array<string, string> tag => nilai (tingkat atas) */
    public function parse(string $payload): array
    {
        $hasil = [];
        $i = 0;
        $len = strlen($payload);

        while ($i + 4 <= $len) {
            $tag = substr($payload, $i, 2);
            $panjang = (int) substr($payload, $i + 2, 2);

            if (! ctype_digit(substr($payload, $i, 4)) || $i + 4 + $panjang > $len) {
                throw new BillingException('Format QRIS tidak valid.');
            }

            $hasil[$tag] = substr($payload, $i + 4, $panjang);
            $i += 4 + $panjang;
        }

        return $hasil;
    }

    /** Nama & kota merchant (tag 59, 60) */
    public function merchant(string $payload): array
    {
        $t = $this->parse($payload);

        return ['nama' => $t['59'] ?? '-', 'kota' => $t['60'] ?? '-', 'dinamis' => ($t['01'] ?? '') === '12'];
    }

    public function dinamis(string $payload, int $nominal): string
    {
        if (! $this->valid($payload)) {
            throw new BillingException('QRIS rental tidak valid. Periksa di Admin → Pengaturan → Operasional → QRIS.');
        }

        $tag = $this->parse($payload);
        unset($tag['63'], $tag['54']);
        $tag['01'] = '12';
        $tag['54'] = (string) $nominal;
        ksort($tag, SORT_STRING); // urutan tag EMV naik: 54 (nominal) sebelum 58 (negara)

        $isi = '';

        foreach ($tag as $k => $v) {
            $isi .= $k.str_pad((string) strlen($v), 2, '0', STR_PAD_LEFT).$v;
        }

        $isi .= '6304';

        return $isi.$this->crc($isi);
    }

    /** CRC16-CCITT (poly 0x1021, awal 0xFFFF) */
    public function crc(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
