<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mengaktifkan tenant & cabang untuk user yang login.
 * Semua model dengan BelongsToTenant / BelongsToCabang otomatis tersaring.
 */
class SetTenancy
{
    public function __construct(private Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! $user->is_active) {
            return $this->keluar($request, 'Akun Anda dinonaktifkan.');
        }

        // Super admin platform (tanpa tenant) hanya memakai panel admin
        if ($user->isSuperAdmin() && ! $user->tenant_id) {
            if ($request->is('admin', 'admin/*') || $request->hasHeader('X-Livewire')) {
                return $next($request);
            }

            return redirect('/admin');
        }

        $tenant = $user->tenant;

        if (! $tenant || ! $tenant->isAktif()) {
            return $this->keluar($request, 'Tenant tidak aktif. Hubungi admin.');
        }

        // Tenant aktif + role/permission per tenant
        $this->tenancy->set($tenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        // Cabang aktif dari session, dicek ulang setiap request
        $cabangId = $request->session()->get('cabang_id');

        if ($cabangId && ! $user->cabangTersedia()->whereKey($cabangId)->exists()) {
            $request->session()->forget('cabang_id');
            $cabangId = null;
        }

        if (! $cabangId) {
            $daftar = $user->cabangTersedia()->pluck('id');

            if ($daftar->isEmpty()) {
                return $this->keluar($request, 'Akun Anda belum ditugaskan ke cabang mana pun.');
            }

            if ($daftar->count() === 1) {
                $cabangId = $daftar->first();
                $request->session()->put('cabang_id', $cabangId);
            } elseif (! $request->routeIs('pilih-cabang') && ! $request->hasHeader('X-Livewire')) {
                return redirect()->route('pilih-cabang');
            }
        }

        $this->tenancy->setCabang($cabangId);

        return $next($request);
    }

    private function keluar(Request $request, string $pesan): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('ui', [
            'type' => 'alert',
            'icon' => 'error',
            'title' => 'Tidak bisa masuk',
            'text' => $pesan,
        ]);
    }
}
