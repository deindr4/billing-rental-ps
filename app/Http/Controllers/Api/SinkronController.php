<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServerSinkron;
use App\Models\Tenant;
use App\Services\Sinkron\PaketSinkron;
use App\Services\Sinkron\TerapkanSinkron;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * (Server cloud) endpoint sinkron untuk server lokal rental. Autentikasi: Bearer token ServerSinkron.
 *
 *   GET  /api/sync/info
 *   POST /api/sync/dorong   {tenant_id, perubahan: [...]}
 *   GET  /api/sync/tarik?setelah=<kursor>&semua=0
 */
class SinkronController extends Controller
{
    public function info(Request $request): JsonResponse
    {
        $server = $this->server($request);

        return response()->json([
            'ok' => true,
            'server' => $server->nama,
            'tenant_id' => $server->tenant_id,
            'tenant' => $server->tenant?->nama,
            'waktu' => now()->toIso8601String(),
        ]);
    }

    public function dorong(Request $request, TerapkanSinkron $terapkan): JsonResponse
    {
        $server = $this->server($request);
        $data = $request->validate([
            'tenant_id' => 'required|uuid',
            'perubahan' => 'present|array|max:2000',
        ]);

        // Server baru terikat ke tenant pada sinkron pertama, hanya jika tenant itu belum ada di cloud.
        // Tenant yang sudah ada harus dipilih super admin saat membuat token (supaya tidak bisa diambil alih).
        if (! $server->tenant_id) {
            if (Tenant::whereKey($data['tenant_id'])->exists()) {
                return response()->json(['ok' => false, 'pesan' => 'Tenant ini sudah ada di cloud. Minta super admin memilih tenant pada Server Sinkron.'], 403);
            }

            $server->tenant_id = $data['tenant_id'];
        }

        if ($server->tenant_id !== $data['tenant_id']) {
            return response()->json(['ok' => false, 'pesan' => 'Token ini untuk tenant lain.'], 403);
        }

        $mulai = microtime(true);

        try {
            // Tenant dikirim lebih dulu supaya FK & pengikatan server aman
            $perubahan = collect($data['perubahan'])->sortBy(fn ($p) => ($p['tabel'] ?? '') === 'tenants' ? 0 : 1)->values()->all();
            $hasil = $terapkan->terapkan($perubahan, $server->tenant_id, $server->id);
        } catch (Throwable $e) {
            report($e);
            $this->log('terima', 'gagal', 0, $mulai, $server, $e->getMessage());

            return response()->json(['ok' => false, 'pesan' => 'Gagal menerapkan: '.$e->getMessage()], 500);
        }

        $server->forceFill(['terakhir_kontak' => now(), 'terakhir_ip' => $request->ip()])->save();
        $this->log('terima', 'ok', $hasil['diterapkan'], $mulai, $server, $hasil['ditolak'] ? count($hasil['ditolak']).' ditolak' : null);

        return response()->json(['ok' => true] + $hasil);
    }

    public function tarik(Request $request, PaketSinkron $paket): JsonResponse
    {
        $server = $this->server($request);

        if (! $server->tenant_id) {
            return response()->json(['ok' => true, 'perubahan' => [], 'kursor' => 0, 'lagi' => false]);
        }

        $setelah = max(0, (int) $request->query('setelah', 0));
        $semua = $request->boolean('semua'); // termasuk perubahan yang berasal dari server ini sendiri
        $batas = 500;

        $antrean = DB::table('sync_antrean')
            ->where('tenant_id', $server->tenant_id)
            ->where('id', '>', $setelah)
            ->when(! $semua, fn ($q) => $q->where(fn ($w) => $w->whereNull('sumber')->orWhere('sumber', '!=', $server->id)))
            ->orderBy('id')
            ->limit($batas)
            ->get();

        // Kursor maju sampai id terakhir yang diperiksa, termasuk yang disaring
        $kursor = $antrean->max('id') ?? DB::table('sync_antrean')->where('tenant_id', $server->tenant_id)
            ->where('id', '>', $setelah)->max('id') ?? $setelah;

        $server->forceFill(['terakhir_kontak' => now(), 'terakhir_ip' => $request->ip()])->save();

        return response()->json([
            'ok' => true,
            'perubahan' => $paket->bangun($antrean),
            'kursor' => (int) $kursor,
            'lagi' => $antrean->count() >= $batas,
        ]);
    }

    private function server(Request $request): ServerSinkron
    {
        return $request->attributes->get('server_sinkron');
    }

    private function log(string $arah, string $status, int $jumlah, float $mulai, ServerSinkron $server, ?string $pesan = null): void
    {
        DB::table('sync_log')->insert([
            'arah' => $arah, 'status' => $status, 'jumlah' => $jumlah,
            'durasi_ms' => (int) ((microtime(true) - $mulai) * 1000),
            'server_id' => $server->id, 'pesan' => $pesan ? mb_substr($pesan, 0, 500) : null, 'created_at' => now(),
        ]);
    }
}
