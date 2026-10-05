<?php

namespace Tests\Feature;

use App\Filament\Widgets\RekapHariIni;
use App\Filament\Widgets\RingkasanOperasional;
use App\Filament\Widgets\StatusSistem;
use App\Models\Cabang;
use App\Models\Pengaturan;
use App\Models\User;
use App\Services\StatusSistemService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class DasborAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($cabang->tenant_id, $cabang->id);
        $this->actingAs(User::where('email', 'owner@billing.test')->firstOrFail());
    }

    public function test_ping_server(): void
    {
        $this->getJson('/api/ping')->assertOk()->assertJson(['ok' => true, 'mode' => 'lokal']);
    }

    public function test_dasbor_menampilkan_status_dan_rekap(): void
    {
        // Widget dimuat lazy setelah halaman tampil
        $this->get('/admin')->assertOk()
            ->assertSeeLivewire(StatusSistem::class)
            ->assertSeeLivewire(RekapHariIni::class)
            ->assertSeeLivewire(RingkasanOperasional::class);
    }

    public function test_status_sistem_dan_tombol(): void
    {
        Pengaturan::simpan('server.url_cloud', 'https://vps.contoh.id');
        $rusak = false;
        Http::fake(['vps.contoh.id/*' => function () use (&$rusak) {
            return $rusak ? Http::response('', 500) : Http::response(['ok' => true]);
        }]);

        $s = app(StatusSistemService::class)->semua();
        $this->assertSame('ok', $s['database']['status']);
        $this->assertSame('ok', $s['cloud']['status']);
        $this->assertStringContainsString('perubahan menunggu', $s['sync']['detail']);

        Livewire::test(StatusSistem::class)
            ->assertSee('Database')
            ->call('tesCloud')->assertNotified()
            ->call('sinkron')->assertNotified()
            ->call('periksaUlang')->assertNotified();

        $rusak = true;
        $this->assertSame('peringatan', app(StatusSistemService::class)->cloud(paksa: true)['status']);
    }

    /** Di cloud, alamat LAN server lokal tidak terjangkau: status dibaca dari kontak sinkron terakhir, tanpa ping */
    public function test_di_cloud_status_server_lokal_dari_kontak_sinkron(): void
    {
        config(['app.mode' => 'cloud']);
        Http::fake();
        $tenant = \App\Models\Tenant::firstOrFail();
        $this->assertSame('Belum terhubung', app(StatusSistemService::class)->cloud(paksa: true)['nilai']);

        $s = \App\Models\ServerSinkron::create(['tenant_id' => $tenant->id, 'nama' => 'PC rental', 'token_hash' => hash('sha256', 'x'), 'is_active' => true, 'terakhir_kontak' => now()->subMinute()]);
        $kartu = app(StatusSistemService::class)->cloud(paksa: true);
        $this->assertSame(['ok', 'Online'], [$kartu['status'], $kartu['nilai']]);

        $s->update(['terakhir_kontak' => now()->subHour()]);
        $this->assertSame('mati', app(StatusSistemService::class)->cloud(paksa: true)['status']);
        Http::assertNothingSent(); // tidak mencoba menghubungi 192.168.x.x
    }

    public function test_widget_rekap_dan_operasional(): void
    {
        Livewire::test(RekapHariIni::class)->assertSee('Omzet hari ini')->assertSee('Unit terpakai');
        Livewire::test(RingkasanOperasional::class)->assertSee('TV Agent')->assertSee('Laba bersih bulan ini');
    }
}
