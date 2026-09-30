<?php

namespace App\Services\Gateway;

use App\Exceptions\BillingException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * iPaymu API v2, direct payment QRIS.
 * Dok: https://documenter.getpostman.com/view/7508947/SWLfanD1 (Direct Payment, Check Transaction).
 *
 * Header signature: HMAC-SHA256("POST:" . va . ":" . lower(sha256(body json)) . ":" . api_key, api_key)
 * Notifikasi iPaymu tidak bertanda tangan → status selalu dicek ulang ke API sebelum dipercaya.
 */
final class IpaymuGateway implements Gateway
{
    public function __construct(private PengaturanGateway $aturan) {}

    private function url(): string
    {
        return $this->aturan->sandbox() ? 'https://sandbox.ipaymu.com/api/v2' : 'https://my.ipaymu.com/api/v2';
    }

    private function kredensial(): array
    {
        $k = [
            'va' => (string) $this->aturan->nilai('ipaymu_va', ''),
            'api_key' => (string) $this->aturan->nilai('ipaymu_api_key', ''),
        ];

        if (in_array('', $k, true)) {
            throw new BillingException('Kredensial iPaymu belum lengkap (Admin → Pengaturan → Pembayaran online).');
        }

        return $k;
    }

    /** POST bertanda tangan (body harus persis sama dengan yang di-hash) */
    private function kirim(string $jalur, array $body): Response
    {
        $k = $this->kredensial();
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        $tanda = hash_hmac('sha256', 'POST:'.$k['va'].':'.strtolower(hash('sha256', $json)).':'.$k['api_key'], $k['api_key']);

        return Http::acceptJson()->connectTimeout(5)->timeout(15)
            ->withHeaders(['va' => $k['va'], 'signature' => $tanda, 'timestamp' => now()->format('YmdHis')])
            ->withBody($json, 'application/json')
            ->post($this->url().$jalur);
    }

    public function buatQris(string $merchantRef, int $nominal, string $keterangan, int $menitBerlaku): array
    {
        $res = $this->kirim('/payment/direct', [
            'name' => 'Pelanggan',
            'phone' => '080000000000',
            'email' => (string) $this->aturan->nilai('email_pelanggan', 'pelanggan@rental.local'),
            'amount' => $nominal,
            'notifyUrl' => route('gateway.callback', 'ipaymu'),
            'expired' => $menitBerlaku,
            'expiredType' => 'minutes',
            'comments' => mb_substr($keterangan, 0, 100),
            'referenceId' => $merchantRef,
            'paymentMethod' => 'qris',
            'paymentChannel' => 'qris',
        ]);

        $d = $res->json('Data') ?? [];
        $qr = $d['QrString'] ?? $d['PaymentNo'] ?? null;

        if (! $res->successful() || (int) $res->json('Status') !== 200 || empty($qr)) {
            throw new BillingException('iPaymu: '.($res->json('Message') ?? 'gagal membuat QRIS (HTTP '.$res->status().')'));
        }

        return [
            'referensi' => (string) $d['TransactionId'],
            'qr_string' => (string) $qr,
            'kedaluwarsa' => ! empty($d['Expired']) ? Carbon::parse($d['Expired'], 'Asia/Jakarta')->setTimezone(config('app.timezone')) : now()->addMinutes($menitBerlaku),
            'biaya' => (int) ($d['Fee'] ?? 0),
            'data' => array_intersect_key($d, array_flip(['SessionId', 'TransactionId', 'ReferenceId', 'Via', 'Channel', 'Total', 'Fee', 'Expired'])),
        ];
    }

    /** Tes kredensial: cek saldo akun */
    public function tes(): array
    {
        $res = $this->kirim('/balance', ['account' => $this->kredensial()['va']]);

        if (! $res->successful() || (int) $res->json('Status') !== 200) {
            throw new BillingException('iPaymu menolak: '.($res->json('Message') ?? 'HTTP '.$res->status()));
        }

        return ['QRIS'];
    }

    public function cekStatus(string $referensi, string $merchantRef): array
    {
        $d = $this->kirim('/transaction', ['transactionId' => $referensi])->json('Data') ?? [];

        return [
            'status' => $this->status(isset($d['Status']) ? (int) $d['Status'] : null),
            'biaya' => isset($d['Fee']) ? (int) $d['Fee'] : null,
            'dibayar_pada' => ! empty($d['SuccessDate']) ? Carbon::parse($d['SuccessDate'], 'Asia/Jakarta')->setTimezone(config('app.timezone')) : null,
            'data' => array_intersect_key($d, array_flip(['TransactionId', 'ReferenceId', 'Amount', 'Fee', 'Status', 'StatusDesc', 'SuccessDate'])),
        ];
    }

    public function bacaCallback(Request $request): ?array
    {
        $trx = (string) $request->input('trx_id');

        if ($trx === '') {
            return null;
        }

        // Jangan percaya isi notifikasi: tanyakan langsung ke iPaymu
        $s = $this->cekStatus($trx, (string) $request->input('reference_id'));

        if (($s['data']['ReferenceId'] ?? null) !== $request->input('reference_id')) {
            return null;
        }

        return [
            'merchant_ref' => (string) $s['data']['ReferenceId'],
            'referensi' => $trx,
            'status' => $s['status'],
            'biaya' => $s['biaya'],
        ];
    }

    public function balasanCallback(): array
    {
        return ['success' => true];
    }

    private function status(?int $s): string
    {
        return match ($s) {
            1, 6, 7 => 'dibayar',      // berhasil, berhasil (belum settle), escrow
            -2 => 'kedaluwarsa',
            2, 3, 4, 5 => 'gagal',     // batal, refund, error, gagal
            default => 'menunggu',
        };
    }
}
