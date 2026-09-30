<?php

namespace App\Services\Gateway;

use App\Exceptions\BillingException;
use Illuminate\Http\Request;

/**
 * Winpay (SNAP BI) — menyusul setelah akun Winpay dibuat.
 * SNAP memakai token B2B + tanda tangan RSA (private key merchant); detail endpoint QRIS MPM
 * (generate, query, notify) disesuaikan dengan dokumen yang diberikan Winpay saat akun aktif.
 */
final class WinpayGateway implements Gateway
{
    public function __construct(private PengaturanGateway $aturan) {}

    private function belum(): never
    {
        throw new BillingException('Integrasi Winpay belum aktif. Gunakan Tripay atau Simulasi sementara ini.');
    }

    public function buatQris(string $merchantRef, int $nominal, string $keterangan, int $menitBerlaku): array
    {
        $this->belum();
    }

    public function cekStatus(string $referensi, string $merchantRef): array
    {
        $this->belum();
    }

    public function bacaCallback(Request $request): ?array
    {
        return null;
    }

    public function tes(): array
    {
        $this->belum();
    }

    public function balasanCallback(): array
    {
        return ['responseCode' => '2005200', 'responseMessage' => 'success'];
    }
}
