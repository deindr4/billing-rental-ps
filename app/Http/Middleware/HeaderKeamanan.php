<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Header keamanan dasar untuk semua halaman web (admin, kasir, halaman publik) */
class HeaderKeamanan
{
    public function handle(Request $request, Closure $next): Response
    {
        $res = $next($request);

        $res->headers->set('X-Frame-Options', 'SAMEORIGIN');            // tidak bisa disisipkan di situs lain
        $res->headers->set('X-Content-Type-Options', 'nosniff');
        $res->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $res->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // HSTS hanya lewat https (server LAN http tidak terpengaruh)
        if ($request->isSecure()) {
            $res->headers->set('Strict-Transport-Security', 'max-age=15552000');
        }

        return $res;
    }
}
