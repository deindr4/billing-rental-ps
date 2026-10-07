<?php

namespace App\Http\Middleware;

use App\Support\HakCipta;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menjaga syarat atribusi lisensi (LICENSE): teks hak cipta pengembang selalu tampil di halaman web.
 * - Kaki halaman dihapus dari tampilan → teks disisipkan otomatis sebelum </body>.
 * - Teks hak cipta di kode diubah → pita peringatan. Aplikasi & data tetap berjalan normal.
 * Struk thermal & nota punya baris hak cipta sendiri (StrukService / struk.nota), jadi tidak disisipi di sini.
 */
class JagaHakCipta
{
    private const SIDIK = '053d51f572acfcac706d90b2d41eac101ec45f34965cac845646e91970e3dd2e';

    public const PESAN = 'Jangan mengganti Copyright tanpa seijin Pengembang';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Hanya halaman HTML utuh; struk/nota cetak & billboard TV tidak disisipi
        if ($request->headers->has('X-Livewire') || $request->routeIs('struk.*', 'billboard')
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $html = $response->getContent();

        if (! is_string($html) || ! str_contains($html, '</body>')) {
            return $response;
        }

        $sisip = '';

        if (! self::utuh()) {
            $sisip .= '<div style="position:fixed;left:0;right:0;bottom:0;z-index:2147483647;background:#b91c1c;color:#fff;'
                .'font:600 13px/1.4 system-ui,sans-serif;text-align:center;padding:8px 12px">'
                .self::PESAN.' · Copyright &copy; deindr4</div>';
        } elseif (! str_contains($html, e(HakCipta::asli()))) {
            $sisip .= '<div style="text-align:center;font:11px/1.4 system-ui,sans-serif;opacity:.6;padding:12px 0 16px">'
                .HakCipta::html().'</div>';
        }

        if ($sisip !== '') {
            $pos = strripos($html, '</body>');
            $response->setContent(substr($html, 0, $pos).$sisip.substr($html, $pos));
        }

        return $response;
    }

    public static function utuh(): bool
    {
        return hash_equals(self::SIDIK, hash('sha256', HakCipta::asli()));
    }
}
