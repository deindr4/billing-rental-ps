<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PembayaranOnline;
use App\Services\Gateway\BayarMandiriService;
use App\Services\Gateway\PengaturanGateway;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifikasi pembayaran dari gateway: POST /api/gateway/{provider}/callback
 * (pasang alamat ini di dashboard gateway; butuh server cloud dengan alamat publik).
 */
class GatewayCallbackController extends Controller
{
    /** Kolom nomor tagihan kita (merchant_ref) di notifikasi tiap gateway */
    private const KOLOM_REF = [
        'tripay' => ['merchant_ref'],
        'midtrans' => ['order_id'],
        'duitku' => ['merchantOrderId'],
        'ipaymu' => ['reference_id'],
        'doku' => ['order.invoice_number'],
        'winpay' => ['originalPartnerReferenceNo', 'partnerReferenceNo'],
    ];

    public function __invoke(Request $request, string $provider, BayarMandiriService $layanan): JsonResponse
    {
        // Cari tagihan dulu (tenant menentukan kunci rahasia untuk memeriksa tanda tangan).
        // input() membaca body JSON maupun form (Duitku & iPaymu mengirim form-urlencoded).
        $ref = (string) collect(self::KOLOM_REF[$provider] ?? [])->map(fn ($k) => $request->input($k))->first(fn ($v) => is_scalar($v) && $v !== '');

        $p = $ref !== '' ? PembayaranOnline::withoutGlobalScopes()->where('merchant_ref', $ref)->where('provider', $provider)->first() : null;

        if (! $p) {
            return response()->json(['success' => false, 'message' => 'Tagihan tidak dikenal'], 404);
        }

        app(Tenancy::class)->set($p->tenant_id, $p->cabang_id);
        $driver = app(PengaturanGateway::class)->driver($provider);
        $data = $driver->bacaCallback($request);

        if (! $data || $data['merchant_ref'] !== $p->merchant_ref) {
            return response()->json(['success' => false, 'message' => 'Tanda tangan tidak valid'], 401);
        }

        $layanan->perbarui($p, $data['status'], $data['biaya']);

        return response()->json($driver->balasanCallback());
    }
}
