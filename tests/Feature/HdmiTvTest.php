<?php

namespace Tests\Feature;

use App\Livewire\Operator\MulaiSesi;
use App\Livewire\Operator\PilihHdmiTv;
use App\Livewire\Operator\Rental;
use App\Models\Cabang;
use App\Models\PerangkatTv;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\ShiftService;
use App\Services\Tv\HdmiTvService;
use App\Services\Tv\PairingTvService;
use App\Services\Tv\TvRemoteService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** 1 TV berisi beberapa konsol (HDMI 1 PS3, HDMI 2 PS4, HDMI 3 PS5): kasir memilih / memindah HDMI, tarif tetap */
class HdmiTvTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $unit;

    private Cabang $cabang;

    private PerangkatTv $tv;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        $this->unit = Unit::where('kode', 'TV2')->firstOrFail();

        $mulai = $this->postJson('/api/tv/pairing', [
            'android_id' => 'tv-hdmi', 'merek' => 'TCL', 'model' => 'TV', 'versi_android' => '14', 'versi_app' => '0.6.1',
        ])->json();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        app(PairingTvService::class)->pasangkan($mulai['kode'], $this->unit, $this->owner);
        $this->token = $this->postJson('/api/tv/pairing/cek', ['kunci' => $mulai['kunci']])->json('token');

        // Daftar input yang dilaporkan TV lewat diagnostik
        $this->tv = PerangkatTv::withoutGlobalScopes()->firstOrFail();
        $this->tv->update(['diagnostik' => ['input' => [
            'HDMI 1 [HDMI] = hw/HW1', 'HDMI 2 [HDMI] = hw/HW2', 'HDMI 3 [HDMI] = hw/HW3',
        ]]]);
        app(HdmiTvService::class)->namai($this->tv, ['hw/HW1' => 'PS3', 'hw/HW2' => ' PS4 ', 'hw/HW3' => 'PS5', 'tidak/ada' => 'X']);
    }

    private function perintah(): array
    {
        return TvRemoteService::antrean($this->tv->id);
    }

    public function test_nama_konsol_per_hdmi_dan_label(): void
    {
        $tv = $this->tv->fresh();
        $this->assertSame(['hw/HW1' => 'PS3', 'hw/HW2' => 'PS4', 'hw/HW3' => 'PS5'], $tv->hdmi_nama); // input tak dikenal dibuang
        $this->assertSame(['hw/HW1' => 'HDMI 1 · PS3', 'hw/HW2' => 'HDMI 2 · PS4', 'hw/HW3' => 'HDMI 3 · PS5'], $tv->pilihanHdmi());
    }

    public function test_kasir_pindah_hdmi_dari_kartu_unit_dan_tv_menerima_perintah(): void
    {
        $this->actingAs($this->owner);

        // Chip HDMI tampil di kartu unit
        Livewire::test(Rental::class)->assertSee('Pilih HDMI');

        Livewire::test(PilihHdmiTv::class)
            ->dispatch('buka-pilih-hdmi', unitId: $this->unit->id)
            ->assertSet('buka', true)
            ->assertSee('HDMI 3 · PS5')
            ->call('pilih', 'hw/HW3')
            ->assertSet('buka', false);

        $tv = $this->tv->fresh();
        $this->assertSame('hw/HW3', $tv->input_hdmi);
        $this->assertSame('HDMI 3 · PS5', $tv->input_hdmi_label);
        $this->assertDatabaseHas('log_tv', ['perangkat_id' => $tv->id, 'jenis' => 'input_hdmi']);

        $p = collect($this->perintah())->firstWhere('perintah', 'pindah_hdmi');
        $this->assertSame(['id' => 'hw/HW3', 'label' => 'HDMI 3 · PS5'], $p['data']);

        // TV menerima pilihan baru di status (dipakai setiap sesi dimulai)
        $this->withToken($this->token)->getJson('/api/tv/status')
            ->assertJsonPath('pengaturan.input_hdmi', 'hw/HW3')
            ->assertJsonPath('pengaturan.input_hdmi_label', 'HDMI 3 · PS5');

        Livewire::test(Rental::class)->assertSee('HDMI 3 · PS5');

        // Input yang tidak dilaporkan TV ditolak
        Livewire::test(PilihHdmiTv::class)->dispatch('buka-pilih-hdmi', unitId: $this->unit->id)->call('pilih', 'palsu/HW9');
        $this->assertSame('hw/HW3', $this->tv->fresh()->input_hdmi);
    }

    public function test_pilih_hdmi_saat_mulai_rental_tarif_tidak_berubah(): void
    {
        $this->actingAs($this->owner);
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
        app(HdmiTvService::class)->pindah($this->tv->fresh(), 'hw/HW1', $this->owner);
        Cache::forget('tv:perintah:'.$this->tv->id);

        $tanpaHdmi = Livewire::test(MulaiSesi::class)
            ->call('bukaUntuk', $this->unit->id)
            ->assertSet('hdmi', 'hw/HW1')
            ->assertSee('HDMI 2 · PS4')
            ->instance()->tarifPerJam;

        $komponen = Livewire::test(MulaiSesi::class)
            ->call('bukaUntuk', $this->unit->id)
            ->set('hdmi', 'hw/HW2')
            ->set('mode', 'durasi')
            ->set('durasiMenit', 60)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame($tanpaHdmi, $komponen->instance()->tarifPerJam);
        $this->assertSame('hw/HW2', $this->tv->fresh()->input_hdmi);
        $this->assertContains('pindah_hdmi', collect($this->perintah())->pluck('perintah')->all());
        $this->assertSame('main', $this->unit->fresh()->status);
    }

    public function test_remote_kartu_unit_tidak_bisa_kirim_pindah_hdmi_tanpa_isi(): void
    {
        $this->actingAs($this->owner);
        Cache::forget('tv:perintah:'.$this->tv->id);

        Livewire::test(Rental::class)->call('perintahTv', $this->unit->id, 'pindah_hdmi');

        $this->assertSame([], $this->perintah());
    }
}
