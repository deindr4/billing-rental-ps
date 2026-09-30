<?php

namespace App\Services\Gateway;

use App\Exceptions\BillingException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Tripay closed payment, kanal QRIS.
 * Dok: https://tripay.co.id/developer (Transaksi Closed Payment, Detail Transaksi, Callback).
 *
 * Signature buat transaksi : HMAC-SHA256(merchant_code . merchant_ref . amount, private_key)
 * Signature callback       : header X-Callback-Signature = HMAC-SHA256(raw body, private_key)
 */
final class TripayGateway implements Gateway
{
    public function __construct(private PengaturanGateway $aturan) {}

    private function url(): string
    {
        return $this->aturan->sandbox() ? 'https://tripay.co.id/api-sandbox' : 'https://tripay.co.id/api';
    }

    private function kredensial(): array
    {
        $k = [
            'merchant' => (string) $this->aturan->nilai('tripay_merchant_code', ''),
            'api_key' => (string) $this->aturan->nilai('tripay_api_key', ''),
            'private' => (string) $this->aturan->nilai('tripay_private_key', ''),
        ];

        if (in_array('', $k, true)) {
            throw new BillingException('Kredensial Tripay belum lengkap (Admin → Pengaturan → Pembayaran online).');
        }

        return $k;
    }

    private function http(string $apiKey): PendingRequest
    {
        return Http::withToken($apiKey)->acceptJson()->connectTimeout(5)->timeout(15);
    }

    public function buatQris(string $merchantRef, int $nominal, string $keterangan, int $menitBerlaku): array
    {
        $k = $this->kredensial();

        $res = $this->http($k['api_key'])->post($this->url().'/transaction/create', [
            'method' => (string) $this->aturan->nilai('tripay_kanal', 'QRIS'),
            'merchant_ref' => $merchantRef,
            'amount' => $nominal,
            'customer_name' => 'Pelanggan',
            'customer_email' => (string) $this->aturan->nilai('email_pelanggan', 'pelanggan@rental.local'),
            'order_items' => [['name' => mb_substr($keterangan, 0, 60), 'price' => $nominal, 'quantity' => 1]],
            'expired_time' => now()->addMinutes($menitBerlaku)->timestamp,
            'signature' => hash_hmac('sha256', $k['merchant'].$merchantRef.$nominal, $k['private']),
        ]);

        $d = $res->json('data');

        if (! $res->ok() || ! $res->json('success') || empty($d['qr_string'])) {
            throw new BillingException('Tripay: '.($res->json('message') ?? 'gagal membuat QRIS (HTTP '.$res->status().')'));
        }

        return [
            'referensi' => (string) $d['reference'],
            'qr_string' => (string) $d['qr_string'],
            'kedaluwarsa' => isset($d['expired_time']) ? Carbon::createFromTimestamp((int) $d['expired_time'], config('app.timezone')) : now()->addMinutes($menitBerlaku),
            'biaya' => (int) ($d['total_fee'] ?? $d['fee_merchant'] ?? 0),
            'data' => array_intersect_key($d, array_flip(['reference', 'merchant_ref', 'amount', 'fee_merchant', 'total_fee', 'amount_received', 'status', 'expired_time'])),
        ];
    }

    /** Tes kredensial: daftar kanal pembayaran merchant. Return nama kanal QRIS yang aktif. */
    public function tes(): array
    {
        $k = $this->kredensial();
        $res = $this->http($k['api_key'])->get($this->url().'/merchant/payment-channel');

        if (! $res->ok() || ! $res->json('success')) {
            throw new BillingException('Tripay menolak: '.($res->json('message') ?? 'HTTP '.$res->status()));
        }

        return collect($res->json('data') ?? [])->filter(fn ($c) => str_contains((string) ($c['code'] ?? ''), 'QRIS') && ($c['active'] ?? false))
            ->map(fn ($c) => $c['code'].' ('.$c['name'].')')->values()->all();
    }

    public function cekStatus(string $referensi, string $merchantRef): array
    {
        $k = $this->kredensial();
        $res = $this->http($k['api_key'])->get($this->url().'/transaction/detail', ['reference' => $referensi]);
        $d = $res->json('data') ?? [];

        return [
            'status' => $this->status((string) ($d['status'] ?? '')),
            'biaya' => isset($d['fee_merchant']) ? (int) $d['fee_merchant'] : null,
            'dibayar_pada' => ! empty($d['paid_at']) ? Carbon::createFromTimestamp((int) $d['paid_at'], config('app.timezone')) : null,
            'data' => array_intersect_key($d, array_flip(['reference', 'status', 'amount', 'fee_merchant', 'amount_received', 'paid_at'])),
        ];
    }

    public function bacaCallback(Request $request): ?array
    {
        $k = $this->kredensial();
        $mentah = $request->getContent();

        if (! hash_equals(hash_hmac('sha256', $mentah, $k['private']), (string) $request->header('X-Callback-Signature'))
            || $request->header('X-Callback-Event') !== 'payment_status') {
            return null;
        }

        $d = json_decode($mentah, true) ?: [];

        return [
            'merchant_ref' => (string) ($d['merchant_ref'] ?? ''),
            'referensi' => (string) ($d['reference'] ?? ''),
            'status' => $this->status((string) ($d['status'] ?? '')),
            'biaya' => isset($d['fee_merchant']) ? (int) $d['fee_merchant'] : null,
        ];
    }

    public function balasanCallback(): array
    {
        return ['success' => true];
    }

    private function status(string $s): string
    {
        return match (strtoupper($s)) {
            'PAID' => 'dibayar',
            'EXPIRED' => 'kedaluwarsa',
            'FAILED', 'REFUND' => 'gagal',
            default => 'menunggu',
        };
    }
}
