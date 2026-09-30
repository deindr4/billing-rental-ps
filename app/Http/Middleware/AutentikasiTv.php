<?php

namespace App\Http\Middleware;

use App\Models\PerangkatTv;
use App\Models\Tenant;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi aplikasi TV Agent dengan token perangkat (Authorization: Bearer ...).
 * Mengaktifkan tenant & cabang milik TV, lalu mencatat TV online.
 */
class AutentikasiTv
{
    /** Jeda minimal update "terakhir online" (detik), supaya tidak menulis DB setiap request */
    private const JEDA_ONLINE_DETIK = 20;

    public function __construct(private Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $perangkat = $token
            ? PerangkatTv::withoutGlobalScopes()->aktif()->where('token_hash', hash('sha256', $token))->first()
            : null;

        if (! $perangkat) {
            return response()->json([
                'kode' => 'perlu_pairing',
                'pesan' => 'TV belum terdaftar atau sudah dicabut. Pasangkan ulang.',
            ], 401);
        }

        $tenant = Tenant::find($perangkat->tenant_id);

        if (! $tenant || ! $tenant->isAktif()) {
            return response()->json(['kode' => 'tenant_nonaktif', 'pesan' => 'Layanan rental tidak aktif.'], 403);
        }

        $this->tenancy->set($perangkat->tenant_id, $perangkat->cabang_id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($perangkat->tenant_id);

        if (! $perangkat->terakhir_online || $perangkat->terakhir_online->lt(now()->subSeconds(self::JEDA_ONLINE_DETIK))) {
            // Query langsung: tidak menaikkan versi sync & tidak memicu event model
            PerangkatTv::withoutGlobalScopes()->whereKey($perangkat->id)->update([
                'terakhir_online' => now(),
                'ip' => $request->ip(),
            ]);
        }

        $request->attributes->set('perangkat', $perangkat);

        return $next($request);
    }
}
