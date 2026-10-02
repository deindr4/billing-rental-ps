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
        // CSP ringan yang aman untuk Livewire/Alpine: larang plugin, <base> asing & disisipkan di situs lain
        $res->headers->set('Content-Security-Policy', "object-src 'none'; base-uri 'self'; frame-ancestors 'self'");

        // Jangan umumkan versi PHP (hosting / PHP bawaan yang expose_php=On)
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }
        $res->headers->remove('X-Powered-By');

        // HSTS hanya lewat https (server LAN http tidak terpengaruh)
        if ($request->isSecure()) {
            $res->headers->set('Strict-Transport-Security', 'max-age=15552000');
        }

        return $res;
    }
}
