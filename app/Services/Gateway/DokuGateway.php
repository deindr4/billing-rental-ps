<?php

namespace App\Services\Gateway;

use App\Exceptions\BillingException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * DOKU Checkout (halaman pembayaran DOKU: QRIS, e-wallet, VA, dll).
 * Dok: https://developers.doku.com (Checkout → Accept Payment, Check Status, Notification).
 *
 * DOKU Checkout memberi tautan pembayaran, bukan string QRIS: TV menampilkan QR tautan itu dan
 * halaman HP pelanggan memunculkan tombol "Bayar sekarang" (bayar langsung dari HP yang sama).
 *
 * Signature: "HMACSHA256=" . base64(HMAC-SHA256(komponen, secret_key)), komponen =
 *   Client-Id:..\nRequest-Id:..\nRequest-Timestamp:..\nRequest-Target:/jalur[\nDigest:base64(sha256(body))]
 */
final class DokuGateway implements Gateway
{
    public function __construct(private PengaturanGateway $aturan) {}

    private function url(): string
    {
        return $this->aturan->sandbox() ? 'https://api-sandbox.doku.com' : 'https://api.doku.com';
    }

    private function kredensial(): array
    {
        $k = [
            'client' => (string) $this->aturan->nilai('doku_client_id', ''),
            'secret' => (string) $this->aturan->nilai('doku_secret_key', ''),
        ];

        if (in_array('', $k, true)) {
            throw new BillingException('Kredensial DOKU belum lengkap (Admin → Pengaturan → Pembayaran online).');
        }

        return $k;
    }

    public static function tandaTangan(string $client, string $requestId, string $waktu, string $jalur, ?string $body, string $secret): string
    {
        $komponen = "Client-Id:{$client}\nRequest-Id:{$requestId}\nRequest-Timestamp:{$waktu}\nRequest-Target:{$jalur}";

        if ($body !== null) {
            $komponen .= "\nDigest:".base64_encode(hash('sha256', $body, true));
        }

        return 'HMACSHA256='.base64_encode(hash_hmac('sha256', $komponen, $secret, true));
    }

    private function kirim(string $metode, string $jalur, ?array $body = null): Response
    {
        $k = $this->kredensial();
        $id = (string) Str::uuid();
        $waktu = gmdate('Y-m-d\TH:i:s\Z');
        $json = $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES) : null;

        $req = Http::acceptJson()->connectTimeout(5)->timeout(15)->withHeaders([
            'Client-Id' => $k['client'],
            'Request-Id' => $id,
            'Request-Timestamp' => $waktu,
            'Signature' => self::tandaTangan($k['client'], $id, $waktu, $jalur, $json, $k['secret']),
        ]);

        return $json !== null
            ? $req->withBody($json, 'application/json')->send($metode, $this->url().$jalur)
            : $req->send($metode, $this->url().$jalur);
    }

    public function buatQris(string $merchantRef, int $nominal, string $keterangan, int $menitBerlaku): array
    {
        $bayar = ['payment_due_date' => $menitBerlaku];
        $metode = (string) $this->aturan->nilai('doku_metode', '');

        if ($metode !== '') {
            $bayar['payment_method_types'] = [$metode];
        }

        $res = $this->kirim('POST', '/checkout/v1/payment', [
            'order' => [
                'amount' => $nominal,
                'invoice_number' => $merchantRef,
                'currency' => 'IDR',
                'line_items' => [['name' => mb_substr($keterangan, 0, 60), 'price' => $nominal, 'quantity' => 1]],
            ],
            'payment' => $bayar,
            'customer' => ['name' => 'Pelanggan', 'email' => (string) $this->aturan->nilai('email_pelanggan', 'pelanggan@rental.local')],
        ]);

        $url = $res->json('response.payment.url');

        if (! $res->successful() || empty($url)) {
            $pesan = $res->json('message') ?? $res->json('error.message');
            throw new BillingException('DOKU: '.(is_array($pesan) ? implode(', ', $pesan) : ($pesan ?? 'gagal membuat tagihan (HTTP '.$res->status().')')));
        }

        return [
            'referensi' => (string) ($res->json('response.payment.token_id') ?? $merchantRef),
            'qr_string' => (string) $url,
            'kedaluwarsa' => now()->addMinutes($menitBerlaku),
            'biaya' => 0,
            'data' => ['url_bayar' => (string) $url, 'expired_date' => $res->json('response.payment.expired_date')],
        ];
    }

    /** Tes kredensial: status invoice fiktif → 401 = kunci/tanda tangan ditolak */
    public function tes(): array
    {
        $res = $this->kirim('GET', '/orders/v1/status/TES-'.now()->timestamp);

        if (in_array($res->status(), [401, 403], true)) {
            $pesan = $res->json('error.message') ?? $res->json('message');
            throw new BillingException('DOKU menolak: '.(is_array($pesan) ? implode(', ', $pesan) : ($pesan ?? 'HTTP '.$res->status())));
        }

        return [$this->aturan->nilai('doku_metode') ?: 'Semua metode di halaman DOKU'];
    }

    public function cekStatus(string $referensi, string $merchantRef): array
    {
        $d = $this->kirim('GET', '/orders/v1/status/'.rawurlencode($merchantRef))->json() ?? [];
        $tanggal = $d['transaction']['date'] ?? null;

        return [
            'status' => $this->status((string) ($d['transaction']['status'] ?? '')),
            'biaya' => null,
            'dibayar_pada' => $tanggal ? Carbon::parse($tanggal)->setTimezone(config('app.timezone')) : null,
            'data' => ['transaction' => $d['transaction'] ?? null, 'service' => $d['service']['id'] ?? null],
        ];
    }

    public function bacaCallback(Request $request): ?array
    {
        $k = $this->kredensial();
        $tanda = self::tandaTangan(
            $k['client'],
            (string) $request->header('Request-Id'),
            (string) $request->header('Request-Timestamp'),
            '/'.ltrim($request->path(), '/'),
            $request->getContent(),
            $k['secret'],
        );

        if ($request->header('Client-Id') !== $k['client'] || ! hash_equals($tanda, (string) $request->header('Signature'))) {
            return null;
        }

        $d = json_decode($request->getContent(), true) ?: [];

        return [
            'merchant_ref' => (string) ($d['order']['invoice_number'] ?? ''),
            'referensi' => (string) ($d['transaction']['original_request_id'] ?? ''),
            'status' => $this->status((string) ($d['transaction']['status'] ?? '')),
            'biaya' => null,
        ];
    }

    public function balasanCallback(): array
    {
        return ['success' => true];
    }

    private function status(string $s): string
    {
        return match (strtoupper($s)) {
            'SUCCESS' => 'dibayar',
            'EXPIRED' => 'kedaluwarsa',
            'FAILED' => 'gagal',
            default => 'menunggu',
        };
    }
}
