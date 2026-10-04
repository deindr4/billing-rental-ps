<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Filament\Pages\Auth\Login as LoginAdmin;
use App\Livewire\Auth\Login;
use App\Support\BatasLogin;
use Illuminate\Http\Request;
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

    private function dariIp(string $ip, array $header = []): void
    {
        $server = ['REMOTE_ADDR' => $ip];
        foreach ($header as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $this->app->instance('request', Request::create('/login', 'POST', [], [], [], $server));
    }

    public function test_ip_lokal_dan_lan_dikenali(): void
    {
        $lokal = function (string $ip, array $h = []): bool {
            $this->dariIp($ip, $h);

            return BatasLogin::ipLokal(request());
        };

        foreach (['127.0.0.1', '::1', '192.168.1.20', '10.5.0.9', '172.20.1.1'] as $ip) {
            $this->assertTrue($lokal($ip), $ip);
        }
        foreach (['203.0.113.5', '8.8.8.8', '172.32.0.1', '2001:db8::1'] as $ip) {
            $this->assertFalse($lokal($ip), $ip);
        }

        // Di balik Cloudflare/Nginx tanpa TRUSTED_PROXIES: semua tampak 127.0.0.1 -> TIDAK dianggap lokal
        $this->assertFalse($lokal('127.0.0.1', ['X-Forwarded-For' => '198.51.100.7']));
        $this->assertFalse($lokal('127.0.0.1', ['CF-Connecting-IP' => '198.51.100.7']));
    }

    public function test_cloudflare_tunnel_memakai_ip_asli_pengunjung(): void
    {
        // Installer Windows: TRUSTED_PROXIES=127.0.0.1,::1,<LAN>; cloudflared di PC ini meneruskan dari 127.0.0.1
        Request::setTrustedProxies(['127.0.0.1', '::1', '192.168.0.0/16'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        // Hanya CF-Connecting-IP (tanpa X-Forwarded-For): tetap terbaca IP internet, bukan "lokal"
        $this->dariIp('127.0.0.1', ['CF-Connecting-IP' => '198.51.100.7']);
        $this->assertSame('198.51.100.7', BatasLogin::ipAsli(request()));
        $this->assertFalse(BatasLogin::ipLokal(request()));

        // Service Cloudflare diisi IP LAN PC (bukan localhost): sama
        $this->dariIp('192.168.1.10', ['CF-Connecting-IP' => '198.51.100.8', 'X-Forwarded-Proto' => 'https']);
        $this->assertSame('198.51.100.8', BatasLogin::ipAsli(request()));
        $this->assertTrue(request()->isSecure());

        // Pengunjung tunnel berbeda tidak berbagi kunci blokir
        BatasLogin::gagal(request());
        BatasLogin::gagal(request());
        BatasLogin::gagal(request());
        $this->assertGreaterThan(0, BatasLogin::sisaBlokir(request()));
        $this->dariIp('127.0.0.1', ['CF-Connecting-IP' => '198.51.100.9']);
        $this->assertSame(0, BatasLogin::sisaBlokir(request()));

        // Header palsu dari luar (bukan proxy tepercaya) diabaikan
        $this->dariIp('203.0.113.5', ['CF-Connecting-IP' => '10.0.0.1']);
        $this->assertSame('203.0.113.5', BatasLogin::ipAsli(request()));

        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    /**
     * Request uji Livewire selalu dari 127.0.0.1: pengecualian lokal dimatikan supaya
     * diperlakukan seperti pengunjung internet.
     */
    private function sepertiInternet(): void
    {
        config(['billing.login_bebas_lokal' => false]);
    }

    public function test_ip_publik_diblokir_setelah_3_gagal_lintas_akun(): void
    {
        $this->sepertiInternet();

        for ($i = 0; $i < 3; $i++) {
            Livewire::test(Login::class)->set('login', "tebak{$i}@x.test")->set('password', 'salah')->call('masuk');
        }

        // Percobaan ke-4, walau password benar: diblokir ±15 menit
        Livewire::test(Login::class)->set('login', 'owner@billing.test')->set('password', 'password')->call('masuk')
            ->assertHasErrors('login')->assertSee('Terlalu banyak percobaan login gagal')->assertSee('15 menit');
        $this->assertGuest();

        // Pengecualian lokal aktif lagi -> dari localhost / LAN tetap bisa masuk
        config(['billing.login_bebas_lokal' => true]);
        Livewire::test(Login::class)->set('login', 'owner@billing.test')->set('password', 'password')->call('masuk')
            ->assertHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_localhost_tidak_kena_batas_3x(): void
    {
        for ($i = 0; $i < 4; $i++) {
            Livewire::test(Login::class)->set('login', "salah{$i}@x.test")->set('password', 'salah')->call('masuk')
                ->assertSee('password salah');
        }

        Livewire::test(Login::class)->set('login', 'owner@billing.test')->set('password', 'password')->call('masuk')
            ->assertHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_login_panel_admin_juga_diblokir_3x(): void
    {
        $this->sepertiInternet();

        for ($i = 0; $i < 3; $i++) {
            Livewire::test(LoginAdmin::class)->set('data.email', 'owner@billing.test')->set('data.password', 'salah')->call('authenticate');
        }

        Livewire::test(LoginAdmin::class)->set('data.email', 'owner@billing.test')->set('data.password', 'password')->call('authenticate')
            ->assertNotified();
        $this->assertGuest();
    }
}
