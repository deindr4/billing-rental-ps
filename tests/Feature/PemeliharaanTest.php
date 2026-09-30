<?php

namespace Tests\Feature;

use App\Filament\Pages\Pemeliharaan;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class PemeliharaanTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        setPermissionsTeamId($this->owner->tenant_id);
    }

    public function test_owner_menjalankan_tombol_dan_tercatat_di_audit(): void
    {
        $this->actingAs($this->owner)->get('/admin/pemeliharaan')->assertOk()
            ->assertSee('Kondisi sistem')->assertSee('Update database')->assertSee('php artisan optimize');

        Cache::put('uji-pemeliharaan', 1);

        Livewire::test(Pemeliharaan::class)
            ->call('jalankan', 'cache_data')
            ->assertSet('hasilGagal', false)
            ->assertSee('Hasil: Kosongkan cache data');

        $this->assertFalse(Cache::has('uji-pemeliharaan'));
        $this->assertTrue(AuditLog::where('aksi', 'pemeliharaan')->where('keterangan', 'Kosongkan cache data')->exists());
    }

    public function test_hanya_perintah_dalam_daftar(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Pemeliharaan::class)->call('jalankan', 'db:wipe')->assertNotFound();
    }

    public function test_owner_tidak_boleh_di_server_cloud(): void
    {
        config(['app.mode' => 'cloud']);

        $this->actingAs($this->owner)->get('/admin/pemeliharaan')->assertForbidden();
        $this->actingAs(User::where('email', 'admin@billing.test')->firstOrFail());
        $this->assertTrue(Pemeliharaan::canAccess());
        Livewire::test(Pemeliharaan::class)->assertOk()->assertSee('Mode server')->assertSee('Cloud');
    }
}
