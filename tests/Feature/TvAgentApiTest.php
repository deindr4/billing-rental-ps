<?php

namespace Tests\Feature;

use App\Events\PerintahTv;
use App\Events\TvSegarkan;
use App\Exceptions\BillingException;
use App\Filament\Pages\PengaturanOperasional;
use App\Filament\Resources\PerangkatTv\Pages\ListPerangkatTv;
use App\Filament\Resources\PerangkatTv\PerangkatTvResource;
use App\Filament\Resources\RilisApk\Pages\CreateRilisApk;
use App\Filament\Resources\RilisApk\Pages\ListRilisApk;
use App\Filament\Resources\RilisApk\RilisApkResource;
use App\Livewire\Operator\KelolaTv;
use App\Livewire\Operator\PanggilanTv;
use App\Livewire\Operator\Rental;
use App\Models\Cabang;
use App\Models\LogTv;
use App\Models\PaketHarga;
use App\Models\Pengaturan;
use App\Models\PerangkatTv;
use App\Models\RilisApk;
use App\Models\Tenant;
use App\Models\Transaksi;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\ShiftService;
use App\Services\Tv\BypassTvService;
use App\Services\Tv\KodeDarurat;
use App\Services\Tv\PairingTvService;
use App\Services\Tv\StatusTvService;
use App\Services\Tv\TvRemoteService;
use App\Support\HakAkses;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TvAgentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->owner->forceFill(['pin' => Hash::make('1234')])->save(); // pin tidak mass-assignable

        $this->aktifkanTenancy();
        $this->unit = Unit::where('kode', 'TV2')->firstOrFail();
    }

    private function aktifkanTenancy(): void
    {
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($cabang->tenant_id, $cabang->id);
    }

    /** Jalankan alur pairing lengkap, return token TV */
    private function pasangkanTv(string $androidId = 'android-abc'): string
    {
        $mulai = $this->postJson('/api/tv/pairing', [
            'android_id' => $androidId,
            'merek' => 'Xiaomi',
            'model' => 'TV A 43',
            'versi_android' => '11',
            'versi_app' => '0.1.0',
        ])->assertCreated()->json();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $mulai['kode']);

        $this->postJson('/api/tv/pairing/cek', ['kunci' => $mulai['kunci']])
            ->assertOk()
            ->assertJson(['status' => 'menunggu']);

        $this->aktifkanTenancy();
        app(PairingTvService::class)->pasangkan($mulai['kode'], $this->unit, $this->owner);

        $hasil = $this->postJson('/api/tv/pairing/cek', ['kunci' => $mulai['kunci']])
            ->assertOk()
            ->assertJson(['status' => 'berhasil'])
            ->json();

        // Kredensial hanya bisa diambil sekali
        $this->postJson('/api/tv/pairing/cek', ['kunci' => $mulai['kunci']])->assertNotFound();

        $this->assertNotEmpty($hasil['rahasia_offline']);

        return $hasil['token'];
    }

    public function test_pairing_lalu_status_terkunci(): void
    {
        $token = $this->pasangkanTv();

        // Tanpa Reverb (mis. shared hosting): TV cukup polling
        $this->withToken($token)->getJson('/api/tv/status')->assertOk()->assertJsonPath('realtime', null);

        config(['broadcasting.default' => 'reverb']);
        $this->withToken($token)->getJson('/api/tv/status')
            ->assertOk()
            ->assertJsonPath('layar', 'kunci')
            ->assertJsonPath('unit.nama', 'TV 2 - PS4')
            ->assertJsonPath('perangkat.nama', 'Xiaomi TV A 43')
            ->assertJsonPath('realtime.channel', 'private-tv.'.PerangkatTv::withoutGlobalScopes()->value('id'));

        $this->assertSame(Unit::MODE_TV_AGENT, $this->unit->fresh()->mode_kontrol);
        $this->assertNotNull(PerangkatTv::withoutGlobalScopes()->value('terakhir_online'));
    }

    public function test_tanpa_token_atau_token_salah_ditolak(): void
    {
        $this->getJson('/api/tv/status')->assertUnauthorized()->assertJsonPath('kode', 'perlu_pairing');
        $this->withToken('salah')->getJson('/api/tv/status')->assertUnauthorized();
    }

    public function test_kode_pairing_salah_ditolak(): void
    {
        $this->expectExceptionMessage('Kode tidak ditemukan');

        app(PairingTvService::class)->pasangkan('000000', $this->unit, $this->owner);
    }

    public function test_sesi_dimulai_tv_menampilkan_main_dan_menerima_sinyal(): void
    {
        $token = $this->pasangkanTv();
        $perangkatId = PerangkatTv::withoutGlobalScopes()->value('id');

        Event::fake([TvSegarkan::class]);

        $this->aktifkanTenancy();
        app(ShiftService::class)->buka($this->owner, Cabang::where('kode', 'DGH1')->firstOrFail(), 100000);

        $paket = PaketHarga::where('nama', 'Paket 3 Jam PS4')->firstOrFail();
        app(BillingService::class)->mulai($this->unit, $this->owner, ['mode' => 'paket', 'paket_harga_id' => $paket->id]);

        Event::assertDispatched(TvSegarkan::class, fn (TvSegarkan $e) => $e->perangkatId === $perangkatId);

        $status = $this->withToken($token)->getJson('/api/tv/status')->assertOk()->json();

        $this->assertSame('main', $status['layar']);
        $this->assertSame('paket', $status['sesi']['mode']);
        $this->assertSame(20000, $status['sesi']['tagihan']['total']);
        $this->assertSame(20000, $status['sesi']['tagihan']['sisa']);
        $this->assertSame('sewa', $status['sesi']['tagihan']['items'][0]['jenis']);
        $this->assertSame(20000, $status['sesi']['tagihan']['items'][0]['subtotal']);
        $this->assertSame('WITA', $status['tema']['zona_label']);
        $this->assertSame(8000, $status['unit']['tarif_per_jam']);
        $this->assertTrue($status['pengaturan']['suara_aktif']);
        $this->assertEqualsWithDelta(180 * 60, $status['sesi']['sisa_detik'], 5);
    }

    public function test_bypass_butuh_pin_yang_benar(): void
    {
        $token = $this->pasangkanTv();

        $this->withToken($token)->postJson('/api/tv/bypass', ['pin' => '9999'])->assertStatus(422);

        $this->withToken($token)->postJson('/api/tv/bypass', ['pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('layar', 'bypass');

        $this->withToken($token)->postJson('/api/tv/bypass/akhiri')
            ->assertOk()
            ->assertJsonPath('layar', 'kunci');
    }

    public function test_tv_dicabut_tidak_bisa_akses_lagi(): void
    {
        $token = $this->pasangkanTv();

        $this->aktifkanTenancy();
        app(PairingTvService::class)->cabut(PerangkatTv::firstOrFail(), $this->owner);

        $this->withToken($token)->getJson('/api/tv/status')->assertUnauthorized();
    }

    public function test_tv_baru_di_unit_yang_sama_mencabut_tv_lama(): void
    {
        $tokenLama = $this->pasangkanTv('android-lama');
        $tokenBaru = $this->pasangkanTv('android-baru');

        $this->withToken($tokenLama)->getJson('/api/tv/status')->assertUnauthorized();
        $this->withToken($tokenBaru)->getJson('/api/tv/status')->assertOk();
    }

    public function test_auth_broadcast_hanya_untuk_channel_sendiri(): void
    {
        $token = $this->pasangkanTv();
        $channel = 'private-tv.'.PerangkatTv::withoutGlobalScopes()->value('id');

        $auth = $this->withToken($token)
            ->postJson('/api/tv/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])
            ->assertOk()
            ->json('auth');

        $koneksi = config('broadcasting.connections.reverb');
        $this->assertSame($koneksi['key'].':'.hash_hmac('sha256', '123.456:'.$channel, $koneksi['secret']), $auth);

        $this->withToken($token)
            ->postJson('/api/tv/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-tv.lain'])
            ->assertForbidden();
    }

    public function test_admin_memasangkan_tv_lewat_panel(): void
    {
        $mulai = $this->postJson('/api/tv/pairing', ['android_id' => 'android-panel', 'merek' => 'TCL', 'model' => '43P635'])->json();

        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $this->actingAs($this->owner)->withSession(['cabang_id' => $cabang->id]);

        $this->get(PerangkatTvResource::getUrl('index'))->assertOk()->assertSee('Pasangkan TV');

        $this->aktifkanTenancy();
        Livewire::test(ListPerangkatTv::class)
            ->callAction('pasangkan', ['kode' => $mulai['kode'], 'unit_id' => $this->unit->id])
            ->assertHasNoFormErrors();

        $this->assertSame(
            $this->unit->id,
            PerangkatTv::withoutGlobalScopes()->where('android_id', 'android-panel')->value('unit_id')
        );

        $this->postJson('/api/tv/pairing/cek', ['kunci' => $mulai['kunci']])->assertJsonPath('status', 'berhasil');
    }

    public function test_grid_rental_menampilkan_status_tv(): void
    {
        $token = $this->pasangkanTv();
        $this->withToken($token)->getJson('/api/tv/status')->assertOk(); // TV melapor -> online

        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $this->aktifkanTenancy();
        app(ShiftService::class)->buka($this->owner, $cabang, 0);

        $this->actingAs($this->owner)
            ->withSession(['cabang_id' => $cabang->id])
            ->get(route('rental'))
            ->assertOk()
            ->assertSee('TV online');
    }

    public function test_operator_bypass_dan_kode_darurat_butuh_pin_berizin(): void
    {
        $token = $this->pasangkanTv();
        $this->withToken($token)->getJson('/api/tv/status')->assertOk(); // TV melapor -> online
        $perangkat = PerangkatTv::withoutGlobalScopes()->firstOrFail();

        // Kasir: role bawaan tanpa izin tv.bypass
        $tenant = Tenant::where('kode', 'DGH')->firstOrFail();
        HakAkses::siapkanPermission();
        HakAkses::siapkanTenant($tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $kasir = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Kasir Satu', 'username' => 'kasir1',
            'email' => 'kasir1@billing.test', 'password' => 'password',
        ]);
        $kasir->forceFill(['pin' => Hash::make('5555')])->save();
        $kasir->assignRole('Kasir');

        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $kasir->cabang()->attach($cabang->id);
        $this->aktifkanTenancy();

        $komponen = Livewire::actingAs($kasir)
            ->test(KelolaTv::class)
            ->dispatch('buka-kelola-tv', unitId: $this->unit->id)
            ->assertSet('buka', true)
            ->assertSee('Online');

        // PIN kasir sendiri tidak cukup
        $komponen->call('bypass', ['pin' => '5555']);
        $this->assertNull($perangkat->fresh()->bypass_sampai);

        // PIN owner menyetujui
        $komponen->call('bypass', ['pin' => '1234']);
        $this->assertTrue($perangkat->fresh()->sedangBypass());
        $this->assertDatabaseHas('log_tv', ['perangkat_id' => $perangkat->id, 'jenis' => 'bypass', 'user_id' => $this->owner->id]);

        $komponen->call('akhiriBypass');
        $this->assertFalse($perangkat->fresh()->sedangBypass());

        // Kode darurat: ditolak untuk PIN kasir, tampil untuk PIN owner
        $komponen->call('lihatKodeDarurat', ['pin' => '5555'])->assertSet('kodeDarurat', null);
        $komponen->call('lihatKodeDarurat', ['pin' => '1234'])
            ->assertSet('kodeDarurat', KodeDarurat::buat($perangkat->fresh()->rahasia_offline));

        $perintah = fn () => collect(TvRemoteService::antrean($perangkat->id))->pluck('perintah')->all();

        // Tutup aplikasi: butuh PIN supervisor/owner
        $komponen->call('tutupAplikasi', ['pin' => '5555']);
        $this->assertNotContains('tutup_aplikasi', $perintah());
        $komponen->call('tutupAplikasi', ['pin' => '1234']);
        $this->assertContains('tutup_aplikasi', $perintah());

        // TV melapor aplikasi ditutup -> panel kasir menampilkannya
        $this->withToken($token)->postJson('/api/tv/heartbeat', ['versi_app' => '0.5.0', 'layar' => 'tutup'])->assertOk();
        $komponen->call('$refresh')->assertSee('Aplikasi ditutup');

        // Lock (tanpa PIN): unlock berjalan diakhiri + TV diperintah tampil & terkunci lagi
        $komponen->call('bypass', ['pin' => '1234']);
        $this->assertTrue($perangkat->fresh()->sedangBypass());
        $komponen->call('kunci');
        $this->assertFalse($perangkat->fresh()->sedangBypass());
        $this->assertContains('kunci', $perintah());

        // Remote kartu unit tidak boleh dipakai untuk tutup aplikasi (jalan pintas tanpa PIN)
        Cache::forget('tv:perintah:'.$perangkat->id);
        $this->actingAs($this->owner);
        Livewire::test(Rental::class)->call('perintahTv', $this->unit->id, 'tutup_aplikasi');
        $this->assertNotContains('tutup_aplikasi', $perintah());
        $this->assertDatabaseHas('log_tv', ['perangkat_id' => $perangkat->id, 'jenis' => 'perintah', 'user_id' => $kasir->id]);
    }

    public function test_super_admin_merilis_apk_dan_tv_mengunduh(): void
    {
        Storage::fake('local');
        $token = $this->pasangkanTv();
        $perangkatId = PerangkatTv::withoutGlobalScopes()->value('id');

        // Belum ada rilis
        $this->withToken($token)->getJson('/api/tv/update?versi_kode=1')->assertOk()->assertJson(['ada_update' => false]);

        // Owner rental boleh melihat daftar rilis (untuk push update), tapi tidak boleh mengunggah
        $this->actingAs($this->owner)->get(RilisApkResource::getUrl('index'))->assertOk();
        $this->actingAs($this->owner)->get(RilisApkResource::getUrl('create'))->assertForbidden();

        Event::fake([TvSegarkan::class]);

        $superAdmin = User::where('email', 'admin@billing.test')->firstOrFail();
        $isi = 'PK-isi-apk-palsu-untuk-test';
        $apk = UploadedFile::fake()->createWithContent('tv-agent.apk', $isi);

        Livewire::actingAs($superAdmin)
            ->test(CreateRilisApk::class)
            ->fillForm([
                'file' => $apk,
                'versi_nama' => '0.2.0',
                'versi_kode' => 2,
                'catatan' => 'Perbaikan timer',
                'aktif' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Event::assertDispatched(TvSegarkan::class, fn (TvSegarkan $e) => $e->perangkatId === $perangkatId && $e->alasan === 'update');

        // TV versi 1 ditawari update, lalu mengunduh file yang sama persis
        $info = $this->withToken($token)->getJson('/api/tv/update?versi_kode=1')
            ->assertOk()
            ->assertJson(['ada_update' => true, 'versi_nama' => '0.2.0', 'versi_kode' => 2, 'sha256' => hash('sha256', $isi)])
            ->json();

        $unduh = $this->withToken($token)->get($info['url'])->assertOk();
        $this->assertSame(hash('sha256', $isi), hash_file('sha256', $unduh->baseResponse->getFile()->getPathname()));

        // Tanpa token tidak bisa mengunduh
        $this->withToken('salah')->get($info['url'])->assertUnauthorized();

        // TV yang sudah versi 2 tidak ditawari lagi; rilis ditarik tidak ditawarkan
        $this->withToken($token)->getJson('/api/tv/update?versi_kode=2')->assertJson(['ada_update' => false]);
        RilisApk::query()->update(['aktif' => false]);
        $this->withToken($token)->getJson('/api/tv/update?versi_kode=1')->assertJson(['ada_update' => false]);
    }

    public function test_admin_push_update_ke_tv_yang_belum_terbaru(): void
    {
        Storage::fake('local');
        $token = $this->pasangkanTv();
        $tv = PerangkatTv::withoutGlobalScopes()->firstOrFail();
        $tv->forceFill(['versi_app' => '0.1.0'])->save();

        Storage::disk('local')->put('apk/a.apk', 'isi');
        RilisApk::create(['versi_nama' => '0.2.0', 'versi_kode' => 2, 'file' => 'apk/a.apk', 'ukuran' => 3, 'sha256' => hash('sha256', 'isi'), 'aktif' => true]);

        $this->actingAs($this->owner);

        // Satu TV dari daftar Perangkat TV, dengan pilihan "pasang sekarang juga"
        Livewire::test(ListPerangkatTv::class)
            ->assertSee('Terbaru 0.2.0')
            ->callTableAction('pushUpdate', $tv, ['paksa' => true])
            ->assertNotified('Update dikirim ke 1 TV');

        $perintah = $this->withToken($token)->getJson('/api/tv/status')->assertOk()->json('perintah');
        $this->assertSame('update_aplikasi_paksa', collect($perintah)->last()['perintah']);

        // Dari daftar Rilis APK: semua TV, mode aman (tunggu TV kosong)
        Livewire::test(ListRilisApk::class)->callAction('pushUpdate', ['paksa' => false])->assertNotified('Update dikirim ke 1 TV');
        $this->assertSame('update_aplikasi', collect($this->withToken($token)->getJson('/api/tv/status')->json('perintah'))->last()['perintah']);

        // Setelah TV melaporkan versi terbaru: tidak ada yang dikirim lagi, tombol per TV hilang
        $this->withToken($token)->postJson('/api/tv/heartbeat', ['versi_app' => '0.2.0', 'layar' => 'kunci'])->assertOk();
        Livewire::test(ListRilisApk::class)->callAction('pushUpdate', ['paksa' => false])->assertNotified('Semua TV sudah memakai versi terbaru');
        Livewire::test(ListPerangkatTv::class)->assertTableActionHidden('pushUpdate', $tv->fresh());
    }

    public function test_tarik_rilis_dan_panduan_rollback(): void
    {
        Storage::fake('local');
        $token = $this->pasangkanTv();
        Storage::disk('local')->put('apk/a.apk', 'a');
        $lama = RilisApk::create(['versi_nama' => '0.3.0', 'versi_kode' => 3, 'file' => 'apk/a.apk', 'ukuran' => 1, 'sha256' => hash('sha256', 'a'), 'aktif' => true]);
        $baru = RilisApk::create(['versi_nama' => '0.4.0', 'versi_kode' => 5, 'file' => 'apk/a.apk', 'ukuran' => 1, 'sha256' => hash('sha256', 'a'), 'aktif' => true]);

        $this->actingAs(User::where('email', 'admin@billing.test')->firstOrFail());

        // Rilis bermasalah ditarik: TV versi 3 tidak lagi ditawari 0.4.0
        Livewire::test(ListRilisApk::class)->callTableAction('tarik', $baru)->assertNotified('Rilis 0.4.0 ditarik');
        $this->assertFalse($baru->fresh()->aktif);
        $this->withToken($token)->getJson('/api/tv/update?versi_kode=3')->assertJson(['ada_update' => false]);

        // Rollback ke 0.3.0: panduan build ulang dengan kode baru (6) & nama berikutnya (0.4.1)
        Livewire::test(ListRilisApk::class)
            ->assertTableActionHidden('rollback', $baru)
            ->assertTableActionVisible('rollback', $lama);

        $panduan = RilisApkResource::panduanRollback($lama);
        $this->assertStringContainsString('-Dari 0.3.0 -Kode 6 -Nama 0.4.1', $panduan);
        $this->assertStringContainsString('tidak mengizinkan', $panduan);

        // Owner tidak melihat tombol kelola rilis
        $this->actingAs($this->owner);
        Livewire::test(ListRilisApk::class)->assertTableActionHidden('tarik', $lama)->assertTableActionHidden('rollback', $lama);
    }

    public function test_heartbeat_menyimpan_diagnostik(): void
    {
        $token = $this->pasangkanTv();

        $this->withToken($token)->postJson('/api/tv/heartbeat', [
            'versi_app' => '0.1.0',
            'layar' => 'kunci',
            'diagnostik' => ['izin_overlay' => false, 'jumlah_hdmi' => 3, 'input' => ['HDMI 1 [HDMI] = com.x/hw1']],
        ])->assertOk();

        $perangkat = PerangkatTv::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('kunci', $perangkat->layar);
        $this->assertSame(3, $perangkat->diagnostik['jumlah_hdmi']);
        $this->assertFalse($perangkat->diagnostik['izin_overlay']);
        $this->assertNotNull($perangkat->diagnostik_pada);
    }

    public function test_heartbeat_menyimpan_ping_server_dan_info_teknis_bisa_dimatikan(): void
    {
        $token = $this->pasangkanTv();

        $this->withToken($token)->getJson('/api/tv/status')->assertJsonPath('pengaturan.info_teknis', true);

        $this->withToken($token)->postJson('/api/tv/heartbeat', ['ping_lokal_ms' => 12, 'ping_cloud_ms' => -1, 'server_dipakai' => 'lokal'])->assertOk();
        $tv = PerangkatTv::withoutGlobalScopes()->firstOrFail();
        $this->assertSame([12, -1, 'lokal'], [$tv->ping_lokal_ms, $tv->ping_cloud_ms, $tv->server_dipakai]);
        $this->assertSame('L 12 ms · C putus', PerangkatTvResource::teksPing($tv));

        // Cloud dikosongkan di admin: TV mengirim null -> ikut dikosongkan
        $this->withToken($token)->postJson('/api/tv/heartbeat', ['ping_lokal_ms' => 15, 'ping_cloud_ms' => null, 'server_dipakai' => 'lokal'])->assertOk();
        $this->assertNull($tv->fresh()->ping_cloud_ms);

        // APK lama tidak mengirim ping: nilai terakhir tetap
        $this->withToken($token)->postJson('/api/tv/heartbeat', ['layar' => 'kunci'])->assertOk();
        $this->assertSame(15, $tv->fresh()->ping_lokal_ms);

        $this->withToken($token)->postJson('/api/tv/heartbeat', ['ping_lokal_ms' => 99999])->assertUnprocessable();

        Pengaturan::simpan('tv.info_teknis', false, $tv->cabang_id);
        $this->withToken($token)->getJson('/api/tv/status')->assertJsonPath('pengaturan.info_teknis', false);
    }

    public function test_halaman_apk_publik_mengunduh_rilis_terbaru(): void
    {
        Storage::fake('local');

        $this->get('/apk')->assertNotFound();

        Storage::disk('local')->put('apk/v2.apk', 'isi-apk-v2');
        RilisApk::create(['versi_nama' => '0.2.0', 'versi_kode' => 2, 'file' => 'apk/v2.apk', 'ukuran' => 10, 'sha256' => hash('sha256', 'isi-apk-v2')]);

        $this->get('/apk')->assertOk()->assertDownload('tv-agent.apk');
    }

    public function test_cek_pairing_berulang_tidak_menghabiskan_jatah_minta_kode(): void
    {
        $kunci = $this->postJson('/api/tv/pairing', ['android_id' => 'android-limit'])->assertCreated()->json('kunci');

        // TV mengecek tiap 3 detik (20x/menit)
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/tv/pairing/cek', ['kunci' => $kunci])->assertOk();
        }

        // Kode baru tetap bisa diminta
        $this->postJson('/api/tv/pairing', ['android_id' => 'android-limit'])->assertCreated();
    }

    public function test_panggil_kasir_dari_tv_sampai_ke_aplikasi_kasir(): void
    {
        $token = $this->pasangkanTv();

        $this->aktifkanTenancy();
        $komponen = Livewire::actingAs($this->owner)->test(PanggilanTv::class);

        // Belum ada panggilan
        $komponen->call('periksa')->assertNotDispatched('ui:panggil-kasir');

        $this->travel(1)->seconds();
        $this->withToken($token)->postJson('/api/tv/panggil-kasir')->assertOk();
        $this->withToken($token)->postJson('/api/tv/panggil-kasir')->assertOk(); // dipencet ulang: diabaikan

        $this->assertSame(1, LogTv::withoutGlobalScopes()->where('jenis', 'panggil_kasir')->count());

        $this->aktifkanTenancy();
        $komponen->call('periksa')->assertDispatched('ui:panggil-kasir', unit: 'TV 2 - PS4');

        // Tidak diulang pada pemeriksaan berikutnya
        $komponen->call('periksa')->assertNotDispatched('ui:panggil-kasir');
    }

    public function test_bypass_durasi_pilihan_dibatasi_dan_bisa_diperpanjang(): void
    {
        $token = $this->pasangkanTv();
        $this->aktifkanTenancy();
        Pengaturan::simpan('tv.bypass_maks_menit', 60);

        $status = $this->withToken($token)->getJson('/api/tv/status')->json('pengaturan');
        $this->assertSame([15, 30, 60], $status['bypass_pilihan']);
        $this->assertSame('com.google.android.youtube.tv', $status['aplikasi'][0]['paket']);

        // Owner nonton YouTube 30 menit
        $this->withToken($token)->postJson('/api/tv/bypass', ['pin' => '1234', 'menit' => 30])->assertOk();
        $perangkat = PerangkatTv::withoutGlobalScopes()->firstOrFail();
        $this->assertEqualsWithDelta(30 * 60, now()->diffInSeconds($perangkat->bypass_sampai), 5);

        // Minta 120 menit: dipotong ke batas 60
        $this->withToken($token)->postJson('/api/tv/bypass', ['pin' => '1234', 'menit' => 120])->assertOk();
        $this->assertEqualsWithDelta(60 * 60, now()->diffInSeconds($perangkat->fresh()->bypass_sampai), 5);

        // Perpanjang dari kasir: tetap tidak melewati batas
        $this->aktifkanTenancy();
        app(BypassTvService::class)->perpanjang($perangkat->fresh(), $this->owner, 30, 'operator');
        $this->assertEqualsWithDelta(60 * 60, now()->diffInSeconds($perangkat->fresh()->bypass_sampai), 5);
    }

    public function test_waktu_pilih_game_tidak_ditagih_dan_bisa_dipercepat(): void
    {
        $this->aktifkanTenancy();
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(ShiftService::class)->buka($this->owner, $cabang, 0);
        $billing = app(BillingService::class);
        $paket = PaketHarga::where('nama', 'Paket 3 Jam PS4')->firstOrFail();

        // Paket 3 jam + pilih game 5 menit: berakhir = sekarang + 5 menit + 3 jam
        $sesi = $billing->mulai($this->unit, $this->owner, ['mode' => 'paket', 'paket_harga_id' => $paket->id, 'pilih_game_menit' => 5]);
        $this->assertTrue($sesi->sedangPilihGame());
        $this->assertEqualsWithDelta(5 * 60, now()->diffInSeconds($sesi->mulai_pada), 3);
        $this->assertEqualsWithDelta((5 + 180) * 60, now()->diffInSeconds($sesi->berakhir_pada), 3);
        $this->assertSame(0, $sesi->durasiBerjalanDetik());

        // Pause tidak boleh selama pilih game
        try {
            $billing->pause($sesi, $this->owner);
            $this->fail('Pause seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('pilih game', $e->getMessage());
        }

        // Pelanggan siap lebih cepat: waktu sewa mulai sekarang, paket tetap 3 jam penuh
        $this->travel(2)->minutes();
        $sesi = $billing->mulaiSekarang($sesi, $this->owner);
        $this->assertFalse($sesi->sedangPilihGame());
        $this->assertEqualsWithDelta(180 * 60, now()->diffInSeconds($sesi->berakhir_pada), 3);

        // Open billing selesai saat masih pilih game: tidak ditagih
        $unit3 = Unit::where('kode', 'TV3')->firstOrFail();
        $open = $billing->mulai($unit3, $this->owner, ['mode' => 'open', 'pilih_game_menit' => 5]);
        $this->travel(4)->minutes();
        $billing->selesai($open, $this->owner);
        $this->assertSame(0, Transaksi::find($open->transaksi_id)->total);
    }

    public function test_remote_tv_dari_kasir(): void
    {
        $token = $this->pasangkanTv();
        $this->withToken($token)->postJson('/api/tv/heartbeat', ['volume' => 40, 'senyap' => false, 'layar_hidup' => true])->assertOk();

        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $this->aktifkanTenancy();
        app(ShiftService::class)->buka($this->owner, $cabang, 0);
        Event::fake([PerintahTv::class]);

        // Tombol remote tampil di kartu unit saat TV online
        $this->actingAs($this->owner)->withSession(['cabang_id' => $cabang->id])
            ->get(route('rental'))->assertOk()->assertSee('40%')->assertSee('Volume naik');

        $this->aktifkanTenancy();
        Livewire::actingAs($this->owner)->test(Rental::class)
            ->call('perintahTv', $this->unit->id, 'volume_naik')
            ->call('perintahTv', $this->unit->id, 'layar_mati', [])
            ->call('perintahTv', $this->unit->id, 'hapus_semua'); // tidak dikenal: ditolak

        Event::assertDispatchedTimes(PerintahTv::class, 2);

        // Perintah ikut di status (cadangan polling); hanya aksi daya yang dicatat di log
        $perintah = $this->withToken($token)->getJson('/api/tv/status')->json('perintah');
        $this->assertSame(['volume_naik', 'layar_mati'], array_column($perintah, 'perintah'));
        $this->assertSame(1, LogTv::withoutGlobalScopes()->where('jenis', 'perintah')->count());
    }

    public function test_input_hdmi_dipilih_di_tv_dan_bisa_diganti_admin(): void
    {
        $token = $this->pasangkanTv();

        // TV melaporkan 3 input di diagnostik
        $this->withToken($token)->postJson('/api/tv/heartbeat', ['diagnostik' => ['input' => [
            'HDMI 1 [HDMI] = com.tv/.Hw1',
            'HDMI 2 [HDMI] = com.tv/.Hw2',
            'HDMI 3 [HDMI] = com.tv/.Hw3',
        ]]])->assertOk();

        $perangkat = PerangkatTv::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(['com.tv/.Hw1' => 'HDMI 1', 'com.tv/.Hw2' => 'HDMI 2', 'com.tv/.Hw3' => 'HDMI 3'], $perangkat->daftarInput());

        // Staf memilih HDMI 2 saat setup di TV
        $this->withToken($token)->postJson('/api/tv/input-hdmi', ['id' => 'com.tv/.Hw2', 'label' => 'HDMI 2'])
            ->assertOk()
            ->assertJsonPath('pengaturan.input_hdmi', 'com.tv/.Hw2')
            ->assertJsonPath('pengaturan.input_hdmi_label', 'HDMI 2');

        // Admin mengganti ke HDMI 3 dari panel
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $this->actingAs($this->owner)->withSession(['cabang_id' => $cabang->id]);
        $this->aktifkanTenancy();
        Livewire::test(ListPerangkatTv::class)
            ->callTableAction('inputHdmi', $perangkat->fresh(), ['input' => 'com.tv/.Hw3'])
            ->assertHasNoTableActionErrors();

        $this->withToken($token)->getJson('/api/tv/status')->assertJsonPath('pengaturan.input_hdmi_label', 'HDMI 3');
    }

    public function test_wallpaper_unit_menimpa_default_dan_pengaturan_langsung_dikirim_ke_tv(): void
    {
        Storage::fake('public');
        $token = $this->pasangkanTv();
        $perangkatId = PerangkatTv::withoutGlobalScopes()->value('id');
        $this->aktifkanTenancy();

        Storage::disk('public')->put('tenants/x/wallpaper/default.webp', 'gambar');
        Storage::disk('public')->put('tenants/x/wallpaper/tv2.webp', 'gambar');
        Pengaturan::simpan('tv.wallpaper', 'tenants/x/wallpaper/default.webp');

        $this->withToken($token)->getJson('/api/tv/status')
            ->assertJsonPath('tema.wallpaper_url', url('/storage/tenants/x/wallpaper/default.webp'));

        // Wallpaper khusus unit menang atas default, dan TV langsung diberi sinyal
        Event::fake([TvSegarkan::class]);
        $this->aktifkanTenancy();
        $this->unit->update(['wallpaper' => 'tenants/x/wallpaper/tv2.webp']);
        Event::assertDispatched(TvSegarkan::class, fn ($e) => $e->perangkatId === $perangkatId);

        $this->withToken($token)->getJson('/api/tv/status')
            ->assertJsonPath('tema.wallpaper_url', url('/storage/tenants/x/wallpaper/tv2.webp'));

        // Simpan pengaturan operasional (mis. pengumuman) -> semua TV cabang langsung dimuat ulang
        Event::fake([TvSegarkan::class]);
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $this->actingAs($this->owner)->withSession(['cabang_id' => $cabang->id]);
        $this->aktifkanTenancy();
        $this->withToken($token)->getJson('/api/tv/status')->assertJsonPath('pengaturan.opasitas_timer', 90);

        Livewire::test(PengaturanOperasional::class)
            ->assertSet('data.opasitas_timer', 90)
            ->set('data.tv_pengumuman', 'Turnamen FC Sabtu ini!')
            ->set('data.opasitas_timer', 50)
            ->call('simpan')
            ->assertHasNoErrors();
        Event::assertDispatched(TvSegarkan::class, fn ($e) => $e->perangkatId === $perangkatId && $e->alasan === 'pengaturan');

        $this->withToken($token)->getJson('/api/tv/status')
            ->assertJsonPath('pengumuman', 'Turnamen FC Sabtu ini!')
            ->assertJsonPath('pengaturan.opasitas_timer', 50);

        // Di luar batas ditolak form
        Livewire::test(PengaturanOperasional::class)->set('data.opasitas_timer', 10)->call('simpan')->assertHasErrors(['data.opasitas_timer']);
    }

    public function test_menu_staf_di_tv_butuh_pin_berizin(): void
    {
        $token = $this->pasangkanTv();

        $this->withToken($token)->postJson('/api/tv/verifikasi-pin', ['pin' => '0000'])->assertStatus(422);
        $this->withToken($token)->postJson('/api/tv/verifikasi-pin', ['pin' => '1234'])
            ->assertOk()
            ->assertJson(['ok' => true, 'nama' => 'Owner']);

        $this->assertDatabaseHas('log_tv', ['jenis' => 'menu_staf', 'user_id' => $this->owner->id]);
    }

    public function test_status_memberi_alamat_server_lokal_dan_cloud_untuk_failover(): void
    {
        $token = $this->pasangkanTv();
        $this->aktifkanTenancy();
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();

        // Belum diatur: TV tetap memakai alamat saat setup
        $this->withToken($token)->getJson('/api/tv/status')
            ->assertJsonPath('server.lokal', null)
            ->assertJsonPath('server.cloud', null)
            ->assertJsonPath('server.asal', 'lokal');

        // Diatur admin (tanpa http:// dan dengan garis miring di akhir tetap dirapikan)
        Pengaturan::simpan('server.url_lokal', StatusTvService::urlServer('192.168.1.10/'), $cabang->id);
        Pengaturan::simpan('server.url_cloud', StatusTvService::urlServer('https://billing.contoh.id'), $cabang->id);

        $this->withToken($token)->getJson('/api/tv/status')
            ->assertJsonPath('server.lokal', 'http://192.168.1.10')
            ->assertJsonPath('server.cloud', 'https://billing.contoh.id');
    }

    public function test_kode_darurat_sesuai_rfc6238(): void
    {
        // Vektor uji RFC 6238 (SHA1, T=59 detik, periode 30) = 94287082 -> 6 digit 287082.
        // Dengan periode 300 detik, counter yang sama (1) dicapai pada T=599.
        $this->assertSame('287082', KodeDarurat::buat('12345678901234567890', 599));
        $this->assertTrue(KodeDarurat::cocok('12345678901234567890', '287082', 650)); // periode sebelumnya masih diterima
        $this->assertFalse(KodeDarurat::cocok('12345678901234567890', '287082', 1000));
    }
}
