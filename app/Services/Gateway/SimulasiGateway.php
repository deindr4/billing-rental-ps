<?php

namespace App\Services\Gateway;

use App\Exceptions\BillingException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Gateway pura-pura untuk uji coba tanpa akun: QR berisi teks biasa, pembayaran ditandai
 * "dibayar" lewat tombol di halaman kasir (Pembayaran online → Simulasi bayar).
 */
final class SimulasiGateway implements Gateway
{
    public function buatQris(string $merchantRef, int $nominal, string $keterangan, int $menitBerlaku): array
    {
        $ref = 'SIM-'.Str::upper(Str::random(10));

        return [
            'referensi' => $ref,
            'qr_string' => "SIMULASI|{$ref}|{$nominal}",
            'kedaluwarsa' => now()->addMinutes($menitBerlaku),
            'biaya' => 0,
            'data' => ['keterangan' => $keterangan],
        ];
    }

    public function cekStatus(string $referensi, string $merchantRef): array
    {
        $dibayar = Cache::get('simulasi-bayar:'.$referensi);

        return [
            'status' => $dibayar ? 'dibayar' : 'menunggu',
            'biaya' => 0,
            'dibayar_pada' => $dibayar ? now() : null,
            'data' => [],
        ];
    }

    /** Tandai tagihan simulasi sudah dibayar */
    public static function bayar(string $referensi): void
    {
        if (! str_starts_with($referensi, 'SIM-')) {
            throw new BillingException('Bukan tagihan simulasi.');
        }

        Cache::put('simulasi-bayar:'.$referensi, true, now()->addDay());
    }

    public function bacaCallback(Request $request): ?array
    {
        return null;
    }

    public function tes(): array
    {
        return ['Simulasi aktif'];
    }

    public function balasanCallback(): array
    {
        return ['success' => true];
    }
}
