<?php

namespace App\Services\Gateway;

use App\Exceptions\BillingException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Duitku API v2, metode QRIS (SP/NQ/DQ/GQ...).
 * Dok: https://docs.duitku.com/api/id (Request Transaksi, Cek Transaksi, Callback).
 *
 * Signature inquiry : MD5(merchantCode . merchantOrderId . paymentAmount . apiKey)
 * Signature status  : MD5(merchantCode . merchantOrderId . apiKey)
 * Signature callback: MD5(merchantCode . amount . merchantOrderId . apiKey)  (form-urlencoded)
 */
final class DuitkuGateway implements Gateway
{
    public const METODE = [
        'SP' => 'QRIS ShopeePay',
        'NQ' => 'QRIS Nobu',
        'DQ' => 'QRIS DANA',
        'GQ' => 'QRIS Gudang Voucher',
        'SQ' => 'QRIS Nusapay',
    ];

    public function __construct(private PengaturanGateway $aturan) {}

    private function url(): string
    {
        return $this->aturan->sandbox() ? 'https://sandbox.duitku.com/webapi/api/merchant' : 'https://passport.duitku.com/webapi/api/merchant';
    }

    private function kredensial(): array
    {
        $k = [
            'merchant' => (string) $this->aturan->nilai('duitku_merchant_code', ''),
            'api_key' => (string) $this->aturan->nilai('duitku_api_key', ''),
        ];

        if (in_array('', $k, true)) {
            throw new BillingException('Kredensial Duitku belum lengkap (Admin → Pengaturan → Pembayaran online).');
        }

        return $k;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->asJson()->connectTimeout(5)->timeout(15);
    }

    public function buatQris(string $merchantRef, int $nominal, string $keterangan, int $menitBerlaku): array
    {
        $k = $this->kredensial();

        $res = $this->http()->post($this->url().'/v2/inquiry', [
            'merchantCode' => $k['merchant'],
            'paymentAmount' => $nominal,
            'paymentMethod' => (string) $this->aturan->nilai('duitku_metode', 'SP'),
            'merchantOrderId' => $merchantRef,
            'productDetails' => mb_substr($keterangan, 0, 100),
            'customerVaName' => 'Pelanggan',
            'email' => (string) $this->aturan->nilai('email_pelanggan', 'pelanggan@rental.local'),
            'callbackUrl' => route('gateway.callback', 'duitku'),
            'returnUrl' => url('/'),
            'expiryPeriod' => $menitBerlaku,
            'signature' => md5($k['merchant'].$merchantRef.$nominal.$k['api_key']),
        ]);

        $d = $res->json() ?? [];

        if (! $res->successful() || ($d['statusCode'] ?? '') !== '00' || empty($d['qrString'])) {
            throw new BillingException('Duitku: '.($d['statusMessage'] ?? $d['Message'] ?? 'gagal membuat QRIS (HTTP '.$res->status().')'));
        }

        return [
            'referensi' => (string) $d['reference'],
            'qr_string' => (string) $d['qrString'],
            'kedaluwarsa' => now()->addMinutes($menitBerlaku),
            'biaya' => 0,
            'data' => array_intersect_key($d, array_flip(['reference', 'amount', 'statusCode', 'statusMessage'])),
        ];
    }

    /** Tes kredensial: daftar metode pembayaran aktif. Return metode QRIS. */
    public function tes(): array
    {
        $k = $this->kredensial();
        $waktu = now()->format('Y-m-d H:i:s');

        $res = $this->http()->post($this->url().'/paymentmethod/getpaymentmethod', [
            'merchantcode' => $k['merchant'],
            'amount' => 10000,
            'datetime' => $waktu,
            'signature' => hash('sha256', $k['merchant'].'10000'.$waktu.$k['api_key']),
        ]);

        if (! $res->successful() || $res->json('responseCode') !== '00') {
            throw new BillingException('Duitku menolak: '.($res->json('responseMessage') ?? $res->json('Message') ?? 'HTTP '.$res->status()));
        }

        return collect($res->json('paymentFee') ?? [])->filter(fn ($m) => isset(self::METODE[$m['paymentMethod'] ?? '']))
            ->map(fn ($m) => $m['paymentMethod'].' ('.$m['paymentName'].')')->values()->all();
    }

    public function cekStatus(string $referensi, string $merchantRef): array
    {
        $k = $this->kredensial();

        $d = $this->http()->post($this->url().'/transactionStatus', [
            'merchantCode' => $k['merchant'],
            'merchantOrderId' => $merchantRef,
            'signature' => md5($k['merchant'].$merchantRef.$k['api_key']),
        ])->json() ?? [];

        return [
            'status' => $this->status((string) ($d['statusCode'] ?? '')),
            'biaya' => isset($d['fee']) ? (int) $d['fee'] : null,
            'dibayar_pada' => null,
            'data' => array_intersect_key($d, array_flip(['reference', 'amount', 'fee', 'statusCode', 'statusMessage'])),
        ];
    }

    public function bacaCallback(Request $request): ?array
    {
        $k = $this->kredensial();
        $tanda = md5($k['merchant'].$request->input('amount').$request->input('merchantOrderId').$k['api_key']);

        if ($request->input('merchantCode') !== $k['merchant'] || ! hash_equals($tanda, (string) $request->input('signature'))) {
            return null;
        }

        return [
            'merchant_ref' => (string) $request->input('merchantOrderId'),
            'referensi' => (string) $request->input('reference'),
            'status' => $request->input('resultCode') === '00' ? 'dibayar' : 'gagal',
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
            '00' => 'dibayar',
            '02' => 'gagal',
            default => 'menunggu',
        };
    }
}
