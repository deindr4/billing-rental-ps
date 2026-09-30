<?php

namespace App\Http\Middleware;

use App\Services\Billing\ShiftService;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman kasir (Rental, POS) hanya bisa dibuka jika user punya shift terbuka.
 */
class EnsureShiftAktif
{
    public function __construct(
        private Tenancy $tenancy,
        private ShiftService $shift,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $cabangId = $this->tenancy->cabangId();

        if ($user && $cabangId && ! $this->shift->aktif($user, $cabangId)) {
            return redirect()->route('shift.buka');
        }

        return $next($request);
    }
}
