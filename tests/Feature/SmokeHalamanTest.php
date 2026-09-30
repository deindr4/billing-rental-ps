<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\User;
use App\Services\Billing\ShiftService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Buka setiap halaman GET tanpa parameter (operator & admin) sebagai owner: tidak boleh error 500.
 */
class SmokeHalamanTest extends TestCase
{
    use RefreshDatabase;

    /** Regresi: Filament mengecek akses sebelum tim (tenant) Spatie diaktifkan middleware */
    public function test_owner_bisa_buka_admin_dan_kasir_tidak(): void
    {
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('email', 'owner@billing.test')->firstOrFail();

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $this->actingAs($owner)->get('/admin')->assertOk();

        // Tombol Panel Admin tampil di aplikasi kasir
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $this->get('/laporan')->assertOk()->assertSee('Panel Admin');

        $kasir = User::whereHas('roles', fn ($q) => $q->where('name', 'Kasir'))->first();

        if ($kasir) {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
            $this->actingAs($kasir)->get('/admin')->assertForbidden();
        }
    }

    public function test_semua_halaman_bisa_dibuka_owner(): void
    {
        $this->seed(DatabaseSeeder::class);

        $owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($cabang->tenant_id, $cabang->id);
        app(ShiftService::class)->buka($owner, $cabang, 0);

        $this->actingAs($owner)->withSession(['cabang_id' => $cabang->id, 'tenant_id' => $cabang->tenant_id]);

        $lewati = ['logout', 'apk', 'login', 'filament.admin.auth.login', 'filament.admin.auth.logout'];
        $diuji = 0;

        /** @var Route $route */
        foreach (RouteFacade::getRoutes() as $route) {
            $nama = $route->getName();

            if (! $nama || ! in_array('GET', $route->methods(), true) || in_array($nama, $lewati, true)
                || str_contains($route->uri(), '{') || str_starts_with($route->uri(), 'api/')
                || str_starts_with($nama, 'livewire.') || str_starts_with($nama, 'storage.') || str_starts_with($nama, 'ignition.')) {
                continue;
            }

            $status = $this->get('/'.ltrim($route->uri(), '/'))->getStatusCode();
            $this->assertLessThan(500, $status, "Halaman {$nama} ({$route->uri()}) error {$status}");

            if (! in_array($status, [200, 403], true)) { // 403 = khusus super admin
                $bukan200[] = "{$nama}={$status}";
            }

            $diuji++;
        }

        $this->assertGreaterThan(20, $diuji);

        // Redirect yang wajar saja (mis. halaman pilih cabang / buka shift); sisanya harus tampil 200
        $this->assertLessThanOrEqual(3, count($bukan200 ?? []), 'Tidak tampil: '.implode(', ', $bukan200 ?? []));
    }
}
