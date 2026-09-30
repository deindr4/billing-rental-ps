<?php

namespace App\Services\Notifikasi;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Klien ke service WhatsApp lokal (whatsapp-service, login via QR).
 * Pengiriman diantre & diberi jeda oleh service untuk mengurangi risiko diblokir.
 */
final class WhatsappService
{
    private function http(int $timeout = 15): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('billing.whatsapp.url'), '/'))
            ->withHeaders(['x-token' => (string) config('billing.whatsapp.token')])
            ->timeout($timeout)
            ->acceptJson();
    }

    /** @return array{status:string, nomor:?string, qr:?string} */
    public function status(): array
    {
        try {
            $respon = $this->http(5)->get('/status');

            if (! $respon->successful()) {
                return ['status' => 'error', 'nomor' => null, 'qr' => null];
            }

            return [
                'status' => (string) $respon->json('status', 'error'),
                'nomor' => $respon->json('nomor'),
                'qr' => $respon->json('qr'),
            ];
        } catch (Throwable) {
            return ['status' => 'mati', 'nomor' => null, 'qr' => null];
        }
    }

    /** Daftar grup yang diikuti nomor ini: [['id' => ..., 'nama' => ...]] */
    public function grup(): array
    {
        $respon = $this->http(20)->get('/grup');

        if (! $respon->successful()) {
            throw new RuntimeException('WhatsApp: '.($respon->json('error') ?? 'gagal memuat grup'));
        }

        return $respon->json('grup', []);
    }

    public function logout(): void
    {
        $this->http()->post('/logout');
    }

    public function kirimTeks(string $tujuan, string $teks): void
    {
        $respon = $this->http(120)->post('/kirim', ['tujuan' => $tujuan, 'teks' => $teks]);

        if (! $respon->successful()) {
            throw new RuntimeException('WhatsApp: '.($respon->json('error') ?? 'HTTP '.$respon->status()));
        }
    }

    public function kirimDokumen(string $tujuan, string $path, string $namaFile, ?string $caption = null): void
    {
        $respon = $this->http(180)->post('/kirim-dokumen', [
            'tujuan' => $tujuan,
            'namaFile' => $namaFile,
            'caption' => $caption,
            'base64' => base64_encode((string) file_get_contents($path)),
        ]);

        if (! $respon->successful()) {
            throw new RuntimeException('WhatsApp: '.($respon->json('error') ?? 'HTTP '.$respon->status()));
        }
    }
}
