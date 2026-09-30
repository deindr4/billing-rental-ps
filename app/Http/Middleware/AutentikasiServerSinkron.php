<?php

namespace App\Http\Middleware;

use App\Models\ServerSinkron;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Endpoint /api/sync/*: hanya server lokal dengan token aktif */
class AutentikasiServerSinkron
{
    public function handle(Request $request, Closure $next): Response
    {
        $server = ServerSinkron::dariToken($request->bearerToken());

        if (! $server) {
            return response()->json(['ok' => false, 'pesan' => 'Token sinkron tidak valid atau dinonaktifkan.'], 401);
        }

        $request->attributes->set('server_sinkron', $server);

        return $next($request);
    }
}
