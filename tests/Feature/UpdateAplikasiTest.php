<?php

namespace Tests\Feature;

use App\Filament\Pages\Pemeliharaan;
use App\Models\User;
use App\Services\StatusSistemService;
use App\Services\UpdateAplikasi;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class UpdateAplikasiTest extends TestCase
{
    use RefreshDatabase;

    private function rilis(string $tag): array
    {
        return [
            'tag_name' => $tag,
            'published_at' => '2026-10-05T08:00:00Z',
            'html_url' => "https://github.com/deindr4/billing-rental-ps/releases/tag/{$tag}",
            'body' => "## Aplikasi billing\n- Fitur baru: batal F&B",
            'assets' => [
                ['name' => 'BillingPS-Setup-2026.10.05.exe', 'browser_download_url' => 'https://github.com/x/setup.exe', 'size' => 150_000_000],
                ['name' => 'BillingPS-cloud-2026.10.05.tar.gz', 'browser_download_url' => 'https://github.com/x/cloud.tar.gz', 'size' => 18_000_000],
            ],
        ];
    }

    public function test_versi_baru_terdeteksi_dan_unduhan_cloud_dipilih(): void
    {
        config(['app.mode' => 'cloud', 'billing.update.repo' => 'deindr4/billing-rental-ps']);
        Http::fake(['api.github.com/repos/deindr4/billing-rental-ps/releases/latest' => Http::response($this->rilis('v9999.01.01'))]);

        $r = app(UpdateAplikasi::class)->cek();
        $this->assertTrue($r['baru']);
        $this->assertSame('9999.01.01', $r['versi']);
        $this->assertSame(UpdateAplikasi::versiSekarang(), $r['sekarang']);
        $this->assertSame('BillingPS-cloud-2026.10.05.tar.gz', $r['unduh']['nama']);

        // Cache: tidak menghubungi GitHub lagi; dasbor membaca cache saja
        app(UpdateAplikasi::class)->cek();
        Http::assertSentCount(1);
        $kartu = app(StatusSistemService::class)->versi();
        $this->assertSame('peringatan', $kartu['status']);
        $this->assertSame('Update 9999.01.01 tersedia', $kartu['nilai']);
    }

    public function test_sudah_terbaru_atau_github_tidak_bisa_dihubungi(): void
    {
        config(['billing.update.repo' => 'deindr4/billing-rental-ps']);
        Http::fake(['*' => Http::sequence()
            ->push($this->rilis('v'.UpdateAplikasi::versiSekarang()))
            ->push([], 500)]);
        $this->assertFalse(app(UpdateAplikasi::class)->cek()['baru']);

        $this->assertArrayHasKey('error', app(UpdateAplikasi::class)->cek(paksa: true));

        // Dimatikan
        config(['billing.update.repo' => '']);
        $this->assertNull(app(UpdateAplikasi::class)->cek());
    }

    public function test_halaman_pemeliharaan_menampilkan_catatan_dan_cek_ulang(): void
    {
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('email', 'owner@billing.test')->firstOrFail();
        setPermissionsTeamId($owner->tenant_id);
        config(['billing.update.repo' => 'deindr4/billing-rental-ps']);
        Http::fake(['*' => Http::response($this->rilis('v9999.01.01'))]);

        $this->actingAs($owner);
        Livewire::test(Pemeliharaan::class)
            ->assertSee('Update aplikasi')
            ->assertSee('9999.01.01 — tersedia')
            ->assertSee('Fitur baru: batal F&amp;B', false)
            ->assertSee('Halaman rilis')
            ->call('cekUpdate')
            ->assertNotified('Versi baru 9999.01.01 tersedia');
    }
}
