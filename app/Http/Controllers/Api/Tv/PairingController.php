<?php

namespace App\Http\Controllers\Api\Tv;

use App\Http\Controllers\Controller;
use App\Services\Tv\PairingTvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint tanpa token: TV yang belum terdaftar meminta kode pairing & menanyakan hasilnya.
 */
class PairingController extends Controller
{
    public function __construct(private PairingTvService $pairing) {}

    /** POST /api/tv/pairing */
    public function mulai(Request $request): JsonResponse
    {
        $data = $request->validate([
            'android_id' => 'required|string|max:64',
            'merek' => 'nullable|string|max:50',
            'model' => 'nullable|string|max:100',
            'versi_android' => 'nullable|string|max:20',
            'versi_app' => 'nullable|string|max:20',
            // Agen kiosk PC Windows: jenis=pc (android_id = ID mesin, versi_android = versi Windows) + MAC untuk Wake-on-LAN
            'jenis' => 'nullable|in:tv,pc',
            'mac' => 'nullable|string|max:20',
        ]);

        ['pairing' => $pairing, 'kunci' => $kunci] = $this->pairing->mulai($data);

        return response()->json([
            'kode' => $pairing->kode,
            'kunci' => $kunci,
            'kedaluwarsa_detik' => PairingTvService::MASA_BERLAKU_MENIT * 60,
            'interval_cek_detik' => 3,
        ], 201);
    }

    /** POST /api/tv/pairing/cek  body: { kunci } */
    public function cek(Request $request): JsonResponse
    {
        $data = $request->validate(['kunci' => 'required|string|max:100']);
        $hasil = $this->pairing->hasil($data['kunci']);

        if (! $hasil) {
            return response()->json(['status' => 'tidak_dikenal', 'pesan' => 'Minta kode baru.'], 404);
        }

        return response()->json($hasil, $hasil['status'] === 'kedaluwarsa' ? 410 : 200);
    }
}
