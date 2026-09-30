<?php

namespace App\Services\Gateway;

use App\Exceptions\BillingException;
use App\Models\Pengaturan;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Pengaturan payment gateway (tingkat tenant) & bayar mandiri di TV (per cabang).
 * Kunci rahasia disimpan terenkripsi (APP_KEY sama di server lokal & cloud supaya bisa dibaca keduanya).
 */
final class PengaturanGateway
{
    public const PROVIDER = [
        'nonaktif' => 'Nonaktif',
        'simulasi' => 'Simulasi (uji tanpa akun)',
        'tripay' => 'Tripay',
        'midtrans' => 'Midtrans',
        'duitku' => 'Duitku',
        'ipaymu' => 'iPaymu',
        'doku' => 'DOKU',
        'winpay' => 'Winpay',
    ];

    public const RAHASIA = [
        'tripay_api_key', 'tripay_private_key',
        'midtrans_server_key',
        'duitku_api_key',
        'ipaymu_api_key',
        'doku_secret_key',
        'winpay_client_secret', 'winpay_private_key',
    ];

    public function provider(): string
    {
        $p = (string) $this->ambil('provider', 'nonaktif');

        return array_key_exists($p, self::PROVIDER) ? $p : 'nonaktif';
    }

    public function sandbox(): bool
    {
        return (bool) $this->ambil('sandbox', true);
    }

    /** Nilai pengaturan gateway (rahasia otomatis didekripsi) */
    public function nilai(string $kunci, mixed $default = null): mixed
    {
        $v = $this->ambil($kunci, $default);

        if (in_array($kunci, self::RAHASIA, true) && is_string($v) && $v !== '') {
            try {
                return Crypt::decryptString($v);
            } catch (Throwable) {
                return $default;
            }
        }

        return $v;
    }

    public function simpan(string $kunci, mixed $nilai): void
    {
        if (in_array($kunci, self::RAHASIA, true) && is_string($nilai) && $nilai !== '') {
            $nilai = Crypt::encryptString($nilai);
        }

        Pengaturan::simpan('gateway.'.$kunci, $nilai);
    }

    /** Driver gateway sesuai pilihan admin */
    public function driver(?string $provider = null): Gateway
    {
        return match ($provider ?? $this->provider()) {
            'simulasi' => new SimulasiGateway,
            'tripay' => new TripayGateway($this),
            'midtrans' => new MidtransGateway($this),
            'duitku' => new DuitkuGateway($this),
            'ipaymu' => new IpaymuGateway($this),
            'doku' => new DokuGateway($this),
            'winpay' => new WinpayGateway($this),
            default => throw new BillingException('Pembayaran online belum diaktifkan.'),
        };
    }

    /* ---------------- Bayar mandiri di TV (per cabang) ---------------- */

    public function bayarMandiriAktif(string $cabangId): bool
    {
        return $this->provider() !== 'nonaktif' && (bool) Pengaturan::ambil('bayar_mandiri.aktif', false, $cabangId);
    }

    /** Minimal waktu yang dibeli sekali bayar (menit) */
    public function minimalMenit(string $cabangId): int
    {
        return max(15, (int) Pengaturan::ambil('bayar_mandiri.minimal_menit', 30, $cabangId));
    }

    /** Masa berlaku QRIS (menit) */
    public function masaQris(string $cabangId): int
    {
        return max(3, min(60, (int) Pengaturan::ambil('bayar_mandiri.masa_qris_menit', 10, $cabangId)));
    }

    /** Sesi bayar mandiri yang habis & lunas diselesaikan otomatis setelah sekian menit (unit kosong lagi) */
    public function selesaiOtomatisMenit(string $cabangId): int
    {
        return max(1, (int) Pengaturan::ambil('bayar_mandiri.selesai_otomatis_menit', 10, $cabangId));
    }

    private function ambil(string $kunci, mixed $default): mixed
    {
        // Gateway tingkat tenant (tanpa cabang)
        return Pengaturan::query()->where('kunci', 'gateway.'.$kunci)->whereNull('cabang_id')->first()?->nilai['v'] ?? $default;
    }
}
