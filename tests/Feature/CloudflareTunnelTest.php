<?php

namespace Tests\Feature;

use App\Filament\Pages\CloudflareTunnel as Halaman;
use App\Models\User;
use App\Support\CloudflareTunnel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class CloudflareTunnelTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        setPermissionsTeamId($this->owner->tenant_id);

        // Tiruan struktur pemasangan Windows: <root>\runtime\nssm.exe & cloudflared.exe
        $this->root = storage_path('framework/testing/tunnel-'.uniqid());
        File::ensureDirectoryExists($this->root.'/runtime/cloudflared');
        File::put($this->root.'/runtime/nssm.exe', '');
        File::put($this->root.'/runtime/cloudflared/cloudflared.exe', '');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private static function token(): string
    {
        return base64_encode(json_encode(['a' => str_repeat('a', 32), 't' => '6ff42ae2-765d-4adf-8112-31c55c1551ef', 's' => base64_encode(random_bytes(32))]));
    }

    public function test_token_diambil_dari_perintah_cloudflare(): void
    {
        $token = self::token();

        $this->assertSame($token, CloudflareTunnel::ambilToken($token));
        $this->assertSame($token, CloudflareTunnel::ambilToken("cloudflared.exe service install {$token}"));
        $this->assertSame($token, CloudflareTunnel::ambilToken("  cloudflared tunnel run --token \"{$token}\"\n"));
        $this->assertNull(CloudflareTunnel::ambilToken('cloudflared.exe service install eyJhIjoiYWJj'));
        $this->assertNull(CloudflareTunnel::ambilToken(base64_encode(json_encode(['bukan' => 'token', 'isi' => str_repeat('x', 60)]))));
        $this->assertNull(CloudflareTunnel::ambilToken(''));
    }

    public function test_menu_hanya_di_pemasangan_windows_untuk_owner(): void
    {
        // Server dev / cloud: tidak ada nssm & cloudflared → menu tidak ada
        $this->actingAs($this->owner)->get('/admin/cloudflare-tunnel')->assertForbidden();

        $this->app->instance(CloudflareTunnel::class, new CloudflareTunnel($this->root));
        Process::fake(['*' => Process::result("SERVICE_NAME: BillingPS-Tunnel\n        STATE              : 1  STOPPED")]);

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Menu tunnel hanya untuk pemasangan Windows');
        }

        $this->actingAs($this->owner)->get('/admin/cloudflare-tunnel')->assertOk()->assertSee('Mati')->assertSee('Cara membuat tunnel');

        $kasir = User::where('email', 'kasir@billing.test')->first();
        if ($kasir) {
            $this->actingAs($kasir)->get('/admin/cloudflare-tunnel')->assertForbidden();
        }
    }

    public function test_aktifkan_menyimpan_token_lalu_menyalakan_layanan(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Menu tunnel hanya untuk pemasangan Windows');
        }

        $this->app->instance(CloudflareTunnel::class, new CloudflareTunnel($this->root));
        Process::fake(['*' => Process::result('STATE              : 4  RUNNING')]);
        $token = self::token();

        // Token salah: tidak disimpan, nssm tidak dipanggil
        Livewire::actingAs($this->owner)->test(Halaman::class)->set('isian', 'bukan token')->call('aktifkan');
        $this->assertFileDoesNotExist($this->root.'/data/cloudflared/token.txt');
        Process::assertDidntRun(fn (PendingProcess $p) => str_ends_with($p->command[0], 'nssm.exe'));

        Livewire::actingAs($this->owner)->test(Halaman::class)
            ->set('isian', "cloudflared.exe service install {$token}")
            ->call('aktifkan')
            ->assertSet('isian', '')
            ->assertSee('Menyala');

        $this->assertSame($token, File::get($this->root.'/data/cloudflared/token.txt'));
        Process::assertRan(fn (PendingProcess $p) => $p->command === [$this->root.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'nssm.exe', 'set', 'BillingPS-Tunnel', 'Start', 'SERVICE_AUTO_START']);
        Process::assertRan(fn (PendingProcess $p) => end($p->command) === 'BillingPS-Tunnel' && in_array('restart', $p->command, true));

        // Matikan & hapus token
        Livewire::actingAs($this->owner)->test(Halaman::class)->call('hapusToken');
        $this->assertFileDoesNotExist($this->root.'/data/cloudflared/token.txt');
        Process::assertRan(fn (PendingProcess $p) => in_array('SERVICE_DEMAND_START', $p->command, true));
    }
}
