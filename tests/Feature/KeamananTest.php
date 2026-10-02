<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Livewire\Auth\Login;
use App\Models\Cabang;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BackupService;
use App\Services\PinService;
use App\Support\HakAkses;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Regresi temuan pentest 2026-10-02 */
class KeamananTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_restore_backup_hanya_memulihkan_gambar(): void
    {
        foreach (['tenants/01a0/logo/abc.webp', 'tenants/x/wallpaper/w.PNG', 'nota/foto.jpeg', 'a.gif'] as $boleh) {
            $this->assertTrue(BackupService::unggahanAman($boleh), $boleh);
        }

        foreach (['shell.php', 'tenants/x/a.php', 'x.phtml', 'a.webp.php', '../.env', 'tenants/../../app/a.webp',
            '.htaccess', 'tenants/.htaccess', 'x/.hidden.png', 'x\\a.webp', 'a.svg', 'tenants/x/'] as $tolak) {
            $this->assertFalse(BackupService::unggahanAman($tolak), $tolak);
        }
    }

    public function test_header_keamanan_dan_versi_php_tidak_diumumkan(): void
    {
        $res = $this->get('/login')->assertOk();

        $res->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $res->headers->get('Content-Security-Policy'));
        $this->assertFalse($res->headers->has('X-Powered-By'));
    }

    public function test_pin_tanpa_izin_tidak_menyebut_nama_dan_ikut_dihitung(): void
    {
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $tenant = Tenant::where('kode', 'DGH')->firstOrFail();
        app(Tenancy::class)->set($tenant->id, $cabang->id);
        HakAkses::siapkanPermission();
        HakAkses::siapkanTenant($tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $kasir = User::create(['tenant_id' => $tenant->id, 'name' => 'Kasir Rahasia', 'username' => 'kr', 'email' => 'kr@x.test', 'password' => 'password']);
        $kasir->forceFill(['pin' => Hash::make('4321')])->save();
        $kasir->assignRole('Kasir');

        try {
            app(PinService::class)->setujui('4321', 'transaksi.batal', $tenant->id);
            $this->fail('PIN kasir tidak boleh menyetujui batal transaksi');
        } catch (BillingException $e) {
            $this->assertStringNotContainsString('Kasir Rahasia', $e->getMessage());
        }

        // Percobaan "PIN benar tapi tanpa izin" ikut terhitung -> setelah 5x terblokir
        for ($i = 0; $i < 4; $i++) {
            rescue(fn () => app(PinService::class)->setujui('4321', 'transaksi.batal', $tenant->id), null, false);
        }
        $this->expectExceptionMessage('Terlalu banyak PIN salah');
        app(PinService::class)->setujui('4321', 'transaksi.batal', $tenant->id);
    }

    public function test_login_dibatasi_per_ip_walau_berganti_akun(): void
    {
        // 20 percobaan salah ke akun berbeda-beda dari satu IP
        for ($i = 0; $i < 20; $i++) {
            Livewire::test(Login::class)->set('login', "tebak{$i}@x.test")->set('password', 'salah')->call('masuk');
        }

        // Akun berikutnya (bahkan dengan password benar) ikut tertahan sementara
        Livewire::test(Login::class)->set('login', 'owner@billing.test')->set('password', 'password')->call('masuk')
            ->assertHasErrors('login');
        $this->assertGuest();
    }
}
