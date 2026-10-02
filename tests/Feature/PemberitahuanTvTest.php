<?php

namespace Tests\Feature;

use App\Livewire\Operator\KelolaRunningText;
use App\Livewire\Operator\KirimPemberitahuan;
use App\Livewire\Operator\Rental;
use App\Models\Cabang;
use App\Models\PerangkatTv;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Tv\PairingTvService;
use App\Services\Tv\TvRemoteService;
use App\Support\HakAkses;
use App\Support\RunningTextTv;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Pemberitahuan di tengah layar TV & running text promo (APK >= 0.6.0) */
class PemberitahuanTvTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $unit;

    private Cabang $cabang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $this->aktifkanTenancy();
        $this->unit = Unit::where('kode', 'TV2')->firstOrFail();
    }

    private function aktifkanTenancy(): void
    {
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
    }

    private function pasangkanTv(Unit $unit, string $androidId): string
    {
        $mulai = $this->postJson('/api/tv/pairing', [
            'android_id' => $androidId, 'merek' => 'Xiaomi', 'model' => 'TV', 'versi_android' => '14', 'versi_app' => '0.6.0',
        ])->assertCreated()->json();

        $this->aktifkanTenancy();
        app(PairingTvService::class)->pasangkan($mulai['kode'], $unit, $this->owner);

        return $this->postJson('/api/tv/pairing/cek', ['kunci' => $mulai['kunci']])->json('token');
    }

    /** Kasir dengan role bawaan (izin rental.kelola, tanpa tv.remote) */
    private function kasir(): User
    {
        $tenant = Tenant::where('kode', 'DGH')->firstOrFail();
        HakAkses::siapkanPermission();
        HakAkses::siapkanTenant($tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $kasir = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Kasir Satu', 'username' => 'kasir1',
            'email' => 'kasir1@billing.test', 'password' => 'password',
        ]);
        $kasir->assignRole('Kasir');
        $kasir->cabang()->attach($this->cabang->id);
        $this->aktifkanTenancy();

        return $kasir;
    }

    public function test_kasir_kirim_pemberitahuan_ke_satu_unit_dan_tv_menerima_isinya(): void
    {
        $token = $this->pasangkanTv($this->unit, 'tv-a');
        $perangkat = PerangkatTv::withoutGlobalScopes()->firstOrFail();

        $komponen = Livewire::actingAs($this->kasir())
            ->test(KirimPemberitahuan::class)
            ->dispatch('buka-pemberitahuan', unitId: $this->unit->id)
            ->assertSet('buka', true)
            ->assertSet('semua', false);

        // Kosong ditolak
        $komponen->call('kirim');
        $this->assertSame([], TvRemoteService::antrean($perangkat->id));

        // Pesan cepat + emoji + gaya
        $komponen->call('pilihPesan', 0)
            ->call('tambahEmoji', '🎮')
            ->call('tambahEmoji', 'bukan-emoji') // diabaikan
            ->set('detik', 15)->set('ukuran', 'jumbo')->set('huruf', 'serif')->set('tebal', false)
            ->call('kirim')
            ->assertSet('buka', false);

        $p = TvRemoteService::antrean($perangkat->id)[0];
        $this->assertSame('pemberitahuan', $p['perintah']);
        $this->assertSame('🙏 Mohon tenang saat bermain 🎮', $p['data']['teks']);
        $this->assertSame(['detik' => 15, 'ukuran' => 'jumbo', 'huruf' => 'serif', 'tebal' => false],
            array_diff_key($p['data'], ['teks' => 1]));

        // Sampai ke TV lewat status (cadangan websocket) & tercatat di riwayat TV
        $this->withToken($token)->getJson('/api/tv/status')->assertOk()
            ->assertJsonPath('perintah.0.perintah', 'pemberitahuan')
            ->assertJsonPath('perintah.0.data.teks', '🙏 Mohon tenang saat bermain 🎮');
        $this->assertDatabaseHas('log_tv', ['perangkat_id' => $perangkat->id, 'jenis' => 'perintah']);
    }

    public function test_pemberitahuan_ke_semua_tv_dan_batas_panjang(): void
    {
        $this->pasangkanTv($this->unit, 'tv-a');
        $this->pasangkanTv(Unit::where('kode', 'TV3')->firstOrFail(), 'tv-b');

        $komponen = Livewire::actingAs($this->owner)
            ->test(KirimPemberitahuan::class)
            ->dispatch('buka-pemberitahuan')
            ->assertSet('semua', true);

        // Lebih dari 150 karakter ditolak
        $komponen->set('teks', str_repeat('a', 151))->call('kirim');
        foreach (PerangkatTv::withoutGlobalScopes()->get() as $p) {
            $this->assertSame([], TvRemoteService::antrean($p->id));
        }

        $komponen->set('teks', 'Rental tutup 15 menit lagi')->call('kirim');
        foreach (PerangkatTv::withoutGlobalScopes()->get() as $p) {
            $this->assertSame('Rental tutup 15 menit lagi', TvRemoteService::antrean($p->id)[0]['data']['teks']);
        }
    }

    public function test_remote_kartu_unit_tidak_bisa_kirim_pemberitahuan_kosong(): void
    {
        $this->pasangkanTv($this->unit, 'tv-a');
        $perangkat = PerangkatTv::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->owner);
        Livewire::test(Rental::class)->call('perintahTv', $this->unit->id, 'pemberitahuan');

        $this->assertSame([], TvRemoteService::antrean($perangkat->id));
    }

    public function test_running_text_tayang_dengan_durasi_lalu_hilang_sendiri(): void
    {
        $token = $this->pasangkanTv($this->unit, 'tv-a');

        // Belum dinyalakan
        $this->withToken($token)->getJson('/api/tv/status')->assertJsonPath('running_text', null);

        $komponen = Livewire::actingAs($this->kasir())
            ->test(KelolaRunningText::class)
            ->dispatch('buka-running-text')
            ->assertSet('buka', true);

        // Teks wajib
        $komponen->call('nyalakan')->assertHasErrors('teks');

        $komponen->set('teks', "Promo   begadang\n6 jam Rp50.000")
            ->set('durasi', 30)->set('sembunyiSaatMain', false)->set('posisi', 'atas')
            ->set('opasitas', 35)->set('ukuran', 'besar')->set('tebal', true)->set('kecepatan', 'cepat')->set('warna', 'kuning')
            ->call('nyalakan')
            ->assertHasNoErrors()
            ->assertSet('buka', false);

        $rt = $this->withToken($token)->getJson('/api/tv/status')->assertOk()->json('running_text');
        $this->assertSame('Promo begadang 6 jam Rp50.000', $rt['teks']);
        $this->assertFalse($rt['sembunyi_saat_main']);
        $this->assertSame(['atas', 35, 'besar', true, 'cepat', '#FACC15'],
            [$rt['posisi'], $rt['opasitas'], $rt['ukuran'], $rt['tebal'], $rt['kecepatan'], $rt['warna']]);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->getTimestampMs(), $rt['sampai_ms'], 5000);

        // Lewat durasi -> mati sendiri
        $this->travel(31)->minutes();
        $this->withToken($token)->getJson('/api/tv/status')->assertJsonPath('running_text', null);
    }

    public function test_running_text_sampai_dimatikan_dan_tombol_matikan(): void
    {
        $token = $this->pasangkanTv($this->unit, 'tv-a');

        $komponen = Livewire::actingAs($this->owner)
            ->test(KelolaRunningText::class)
            ->dispatch('buka-running-text')
            ->set('teks', 'Turnamen FC Sabtu ini!')
            ->set('durasi', 0)
            ->call('nyalakan');

        $rt = $this->withToken($token)->getJson('/api/tv/status')->json('running_text');
        $this->assertNull($rt['sampai_ms']);
        $this->assertTrue($rt['sembunyi_saat_main']); // bawaan: tidak mengganggu yang sedang main

        $this->travel(2)->days();
        $this->withToken($token)->getJson('/api/tv/status')->assertJsonPath('running_text.teks', 'Turnamen FC Sabtu ini!');

        $komponen->dispatch('buka-running-text')->call('matikan');
        $this->withToken($token)->getJson('/api/tv/status')->assertJsonPath('running_text', null);

        // Teks tersimpan untuk dinyalakan lagi
        $this->assertSame('Turnamen FC Sabtu ini!', RunningTextTv::ambil($this->cabang->id)['teks']);
    }
}
