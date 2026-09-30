<?php

namespace App\Services\Gateway;

use App\Exceptions\BillingException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Midtrans Core API, payment_type "qris" (QRIS dinamis).
 * Dok: https://docs.midtrans.com (Core API → QRIS, Get Status, HTTP Notification).
 *
 * Auth                : Basic base64(server_key + ":")
 * Signature notifikasi: SHA512(order_id . status_code . gross_amount . server_key)
 */
final class MidtransGateway implements Gateway
{
    public function __construct(private PengaturanGateway $aturan) {}

    private function url(): string
    {
        return $this->aturan->sandbox() ? 'https://api.sandbox.midtrans.com' : 'https://api.midtrans.com';
    }

    private function kunci(): string
    {
        $k = (string) $this->aturan->nilai('midtrans_server_key', '');

        if ($k === '') {
            throw new BillingException('Server key Midtrans belum diisi (Admin → Pengaturan → Pembayaran online).');
        }

        return $k;
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->kunci(), '')->acceptJson()->asJson()->connectTimeout(5)->timeout(15);
    }

    public function buatQris(string $merchantRef, int $nominal, string $keterangan, int $menitBerlaku): array
    {
        $res = $this->http()->post($this->url().'/v2/charge', [
            'payment_type' => 'qris',
            'transaction_details' => ['order_id' => $merchantRef, 'gross_amount' => $nominal],
            'item_details' => [['id' => 'waktu', 'name' => mb_substr($keterangan, 0, 50), 'price' => $nominal, 'quantity' => 1]],
            'qris' => ['acquirer' => (string) $this->aturan->nilai('midtrans_acquirer', 'gopay')],
            'custom_expiry' => ['expiry_duration' => $menitBerlaku, 'unit' => 'minute'],
        ]);

        $d = $res->json() ?? [];

        if (! $res->successful() || ! in_array((string) ($d['status_code'] ?? ''), ['200', '201'], true) || empty($d['qr_string'])) {
            throw new BillingException('Midtrans: '.($d['status_message'] ?? 'gagal membuat QRIS (HTTP '.$res->status().')'));
        }

        return [
            'referensi' => (string) $d['transaction_id'],
            'qr_string' => (string) $d['qr_string'],
            'kedaluwarsa' => $this->waktu($d['expiry_time'] ?? null) ?? now()->addMinutes($menitBerlaku),
            'biaya' => 0,
            'data' => array_intersect_key($d, array_flip(['transaction_id', 'order_id', 'gross_amount', 'transaction_status', 'acquirer', 'expiry_time'])),
        ];
    }

    /** Tes kredensial: status order fiktif → 404 = kunci diterima, 401 = kunci salah */
    public function tes(): array
    {
        $res = $this->http()->get($this->url().'/v2/tes-koneksi-'.now()->timestamp.'/status');
        $kode = (string) ($res->json('status_code') ?? $res->status());

        if (in_array($kode, ['401', '403'], true)) {
            throw new BillingException('Midtrans menolak server key: '.($res->json('status_message') ?? 'HTTP '.$res->status()));
        }

        return ['QRIS ('.$this->aturan->nilai('midtrans_acquirer', 'gopay').')'];
    }

    public function cekStatus(string $referensi, string $merchantRef): array
    {
        $d = $this->http()->get($this->url().'/v2/'.rawurlencode($merchantRef).'/status')->json() ?? [];

        return [
            'status' => $this->status((string) ($d['transaction_status'] ?? '')),
            'biaya' => null,
            'dibayar_pada' => $this->waktu($d['settlement_time'] ?? null),
            'data' => array_intersect_key($d, array_flip(['transaction_id', 'order_id', 'gross_amount', 'transaction_status', 'settlement_time'])),
        ];
    }

    public function bacaCallback(Request $request): ?array
    {
        $d = $request->json()->all();
        $tanda = hash('sha512', ($d['order_id'] ?? '').($d['status_code'] ?? '').($d['gross_amount'] ?? '').$this->kunci());

        if (! hash_equals($tanda, (string) ($d['signature_key'] ?? ''))) {
            return null;
        }

        return [
            'merchant_ref' => (string) $d['order_id'],
            'referensi' => (string) ($d['transaction_id'] ?? ''),
            'status' => $this->status((string) ($d['transaction_status'] ?? '')),
            'biaya' => null,
        ];
    }

    public function balasanCallback(): array
    {
        return ['success' => true];
    }

    private function status(string $s): string
    {
        return match ($s) {
            'settlement', 'capture' => 'dibayar',
            'expire' => 'kedaluwarsa',
            'cancel', 'deny', 'failure', 'refund', 'partial_refund' => 'gagal',
            default => 'menunggu',
        };
    }

    /** Waktu Midtrans "Y-m-d H:i:s" dalam WIB */
    private function waktu(?string $s): ?Carbon
    {
        return $s ? Carbon::parse($s, 'Asia/Jakarta')->setTimezone(config('app.timezone')) : null;
    }
}
