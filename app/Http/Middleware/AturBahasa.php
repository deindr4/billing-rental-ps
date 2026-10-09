<?php

namespace App\Http\Middleware;

use App\Support\Bahasa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pasang bahasa per request: pengguna → pilihan tamu di sesi → bawaan cabang → APP_LOCALE */
class AturBahasa
{
    public function handle(Request $request, Closure $next): Response
    {
        $sesi = $request->hasSession() ? $request->session() : null;

        Bahasa::terapkan(Bahasa::untukPengguna(
            $request->user(),
            $sesi?->get('cabang_id') ?? $sesi?->get('bahasa_cabang'),
            $sesi?->get('bahasa'),
        ));

        return $next($request);
    }
}
