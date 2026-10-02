<?php

namespace Tests\Feature;

use App\Filament\Pages\Sinkronisasi;
use App\Models\Cabang;
use App\Models\PaketHarga;
use App\Models\Produk;
use App\Models\ServerSinkron;
use App\Models\User;
use App\Services\Billing\StokService;
use App\Services\Sinkron\PaketSinkron;
use App\Services\Sinkron\SinkronService;
use App\Services\Sinkron\TerapkanSinkron;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SinkronTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $cabang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        Storage::fake('local');
        DB::table('sync_antrean')->delete();
    }

    public function test_trigger_mencatat_semua_perubahan_termasuk_query_langsung(): void
    {
        PaketHarga::where('nama', 'Per Jam PS4')->firstOrFail()->update(['harga' => 9000]);
        DB::table('games')->limit(1)->update(['nama' => 'FC 26']);

        $antrean = DB::table('sync_antrean')->get();
        $this->assertTrue($antrean->contains('tabel', 'paket_harga'));
        $this->assertTrue($antrean->contains('tabel', 'games'));
        $this->assertSame($this->cabang->tenant_id, $antrean->firstWhere('tabel', 'games')->tenant_id);

        // Perubahan yang datang dari cloud tidak dicatat ulang (tidak dikirim balik)
        DB::table('sync_antrean')->delete();
        $paket = app(PaketSinkron::class)->bangun(collect([(object) ['tabel' => 'games', 'row_id' => DB::table('games')->value('id')]]));
        app(TerapkanSinkron::class)->terapkan($paket);
        $this->assertSame(0, DB::table('sync_antrean')->count());
    }

    public function test_endpoint_cloud_menerima_menolak_tenant_lain_dan_tarik(): void
    {
        config(['app.mode' => 'cloud']);
        [$server, $token] = ServerSinkron::buat('Rental uji', $this->cabang->tenant_id);
        $tenant = $this->cabang->tenant_id;

        $this->getJson('/api/sync/info')->assertStatus(401);
        $this->withToken($token)->getJson('/api/sync/info')->assertOk()->assertJson(['tenant_id' => $tenant]);

        $paket = PaketHarga::where('nama', 'Per Jam PS4')->firstOrFail();
        $data = (array) DB::table('paket_harga')->where('id', $paket->id)->first();
        $data['harga'] = 12345;
        $data['updated_at'] = now()->addMinute()->toDateTimeString();

        $asing = $data;
        $asing['id'] = (string) Str::uuid7();
        $asing['tenant_id'] = (string) Str::uuid7();

        $this->withToken($token)->postJson('/api/sync/dorong', [
            'tenant_id' => $tenant,
            'perubahan' => [
                ['tabel' => 'paket_harga', 'aksi' => 'upsert', 'id' => $data['id'], 'data' => $data],
                ['tabel' => 'paket_harga', 'aksi' => 'upsert', 'id' => $asing['id'], 'data' => $asing],
                ['tabel' => 'sessions', 'aksi' => 'upsert', 'id' => 'x', 'data' => []],
            ],
        ])->assertOk()->assertJson(['diterapkan' => 1])->assertJsonCount(2, 'ditolak');

        $this->assertSame(12345, (int) DB::table('paket_harga')->where('id', $paket->id)->value('harga'));

        // Perubahan dari server ini sendiri tidak dikirim balik; perubahan di cloud dikirim
        $this->withToken($token)->getJson('/api/sync/tarik?setelah=0')->assertOk()->assertJsonCount(0, 'perubahan');

        DB::table('games')->limit(1)->update(['nama' => 'Game Uji Cloud', 'updated_at' => now()]);
        $res = $this->withToken($token)->getJson('/api/sync/tarik?setelah=0')->assertOk();
        $this->assertSame('games', $res->json('perubahan.0.tabel'));
        $this->assertGreaterThan(0, $res->json('kursor'));

        // Dengan semua=1 (setelah restore) perubahan sendiri ikut diambil
        $this->assertGreaterThan(1, count($this->withToken($token)->getJson('/api/sync/tarik?setelah=0&semua=1')->json('perubahan')));
    }

    /** Pentest: token sinkron satu tenant tidak bisa membuat super admin / menimpa data tenant lain / super admin */
    public function test_token_sinkron_tidak_bisa_eskalasi_super_admin_atau_menimpa_tenant_lain(): void
    {
        config(['app.mode' => 'cloud']);
        $tenant = $this->cabang->tenant_id;
        [, $token] = ServerSinkron::buat('Rental uji', $tenant);

        $owner = (array) DB::table('users')->where('email', 'owner@billing.test')->first();
        $superAdmin = DB::table('users')->where('email', 'admin@billing.test')->first();

        // Pengguna tenant lain (korban)
        $tenantLain = (string) Str::uuid7();
        DB::table('tenants')->insert(['id' => $tenantLain, 'kode' => 'LAIN', 'nama' => 'Rental Lain', 'status' => 'aktif', 'created_at' => now(), 'updated_at' => now()]);
        $korbanId = (string) Str::uuid7();
        DB::table('users')->insert(array_merge($owner, [
            'id' => $korbanId, 'tenant_id' => $tenantLain, 'email' => 'korban@lain.test', 'username' => 'korban', 'password' => 'hash-asli',
        ]));

        $baris = fn (array $ubah) => array_merge($owner, ['updated_at' => now()->addMinute()->toDateTimeString()], $ubah);
        $penyusupId = (string) Str::uuid7();

        $this->withToken($token)->postJson('/api/sync/dorong', [
            'tenant_id' => $tenant,
            'perubahan' => [
                // 1. Pengguna baru di tenant sendiri yang mengaku super admin
                ['tabel' => 'users', 'aksi' => 'upsert', 'id' => $penyusupId, 'data' => $baris([
                    'id' => $penyusupId, 'email' => 'penyusup@x.test', 'username' => 'penyusup', 'is_super_admin' => 1,
                ])],
                // 2. Menimpa pengguna tenant lain (id sama, tenant diganti ke tenant sendiri)
                ['tabel' => 'users', 'aksi' => 'upsert', 'id' => $korbanId, 'data' => $baris([
                    'id' => $korbanId, 'email' => 'korban@lain.test', 'username' => 'korban', 'password' => 'hash-penyusup',
                ])],
                // 3. Mengambil alih akun super admin
                ['tabel' => 'users', 'aksi' => 'upsert', 'id' => $superAdmin->id, 'data' => $baris([
                    'id' => $superAdmin->id, 'email' => 'admin@billing.test', 'username' => 'su', 'password' => 'hash-penyusup',
                ])],
                // 4. Menghapus super admin
                ['tabel' => 'users', 'aksi' => 'hapus', 'id' => $superAdmin->id],
            ],
        ])->assertOk();

        $this->assertFalse((bool) DB::table('users')->where('id', $penyusupId)->value('is_super_admin'));
        $this->assertSame(['tenant_id' => $tenantLain, 'password' => 'hash-asli'],
            (array) DB::table('users')->where('id', $korbanId)->first(['tenant_id', 'password']));
        $this->assertSame($superAdmin->password, DB::table('users')->where('id', $superAdmin->id)->value('password'));
        $this->assertTrue((bool) DB::table('users')->where('id', $superAdmin->id)->value('is_super_admin'));
    }

    public function test_token_baru_tidak_bisa_mengambil_alih_tenant_yang_sudah_ada(): void
    {
        config(['app.mode' => 'cloud']);
        [, $token] = ServerSinkron::buat('Penyusup');

        $this->withToken($token)->postJson('/api/sync/dorong', ['tenant_id' => $this->cabang->tenant_id, 'perubahan' => []])
            ->assertStatus(403);
    }

    public function test_data_lama_tidak_menimpa_data_baru(): void
    {
        $paket = PaketHarga::where('nama', 'Per Jam PS4')->firstOrFail();
        $data = (array) DB::table('paket_harga')->where('id', $paket->id)->first();
        $data['harga'] = 1;
        $data['updated_at'] = now()->subDay()->toDateTimeString();

        $h = app(TerapkanSinkron::class)->terapkan([['tabel' => 'paket_harga', 'aksi' => 'upsert', 'id' => $paket->id, 'data' => $data]]);

        $this->assertSame(1, $h['dilewati']);
        $this->assertSame(8000, (int) $paket->fresh()->harga);
    }

    public function test_stok_dan_saldo_dijumlah_dari_buku_besar(): void
    {
        $owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $produk = Produk::create(['nama' => 'Air Mineral Uji', 'harga_jual' => 5000, 'lacak_stok' => true, 'is_active' => true]);
        app(StokService::class)->catat($produk, $this->cabang->id, 20, 'masuk', $owner, null, 'Stok awal', 3000);
        $awal = 20;

        // Penjualan yang terjadi di server lain: -3
        $mutasi = [
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->cabang->tenant_id, 'cabang_id' => $this->cabang->id,
            'produk_id' => $produk->id, 'jenis' => 'penjualan', 'qty' => -3, 'created_at' => now(), 'updated_at' => now(),
        ];

        app(TerapkanSinkron::class)->terapkan([['tabel' => 'stok_mutasi', 'aksi' => 'upsert', 'id' => $mutasi['id'], 'data' => $mutasi]]);

        $this->assertSame($awal - 3, (int) DB::table('produk_stok')->where('produk_id', $produk->id)->where('cabang_id', $this->cabang->id)->value('qty'));
    }

    public function test_layanan_lokal_dorong_dan_tarik(): void
    {
        $sinkron = app(SinkronService::class);
        $sinkron->simpanPengaturan('https://cloud.contoh.id', 'sk_uji', true);

        PaketHarga::where('nama', 'Per Jam PS4')->firstOrFail()->update(['harga' => 9500]);
        $this->assertGreaterThan(0, $sinkron->jumlahAntrean());

        $game = DB::table('games')->first();
        $game->nama = 'Dari Cloud';
        $game->updated_at = now()->addMinute()->toDateTimeString();

        Http::fake([
            'cloud.contoh.id/api/sync/dorong' => Http::response(['ok' => true, 'diterapkan' => 1, 'dilewati' => 0, 'ditolak' => []]),
            'cloud.contoh.id/api/sync/tarik*' => Http::response([
                'ok' => true, 'kursor' => 77, 'lagi' => false,
                'perubahan' => [['tabel' => 'games', 'aksi' => 'upsert', 'id' => $game->id, 'data' => (array) $game]],
            ]),
        ]);

        $h = $sinkron->jalankan();

        $this->assertSame(1, $h['dorong']);
        $this->assertSame(1, $h['tarik']);
        $this->assertSame(0, $sinkron->jumlahAntrean());
        $this->assertSame('Dari Cloud', DB::table('games')->where('id', $game->id)->value('nama'));
        $this->assertSame(77, $sinkron->status()['kursor']);
        $this->assertNull($sinkron->status()['error']);

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk_uji') && str_contains($r->url(), 'dorong')
            && $r['tenant_id'] === $this->cabang->tenant_id);
    }

    public function test_halaman_sinkronisasi(): void
    {
        $owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->actingAs($owner);

        Http::fake(['cloud.contoh.id/api/sync/info' => Http::response(['ok' => true, 'server' => 'Rental uji', 'tenant' => null])]);

        Livewire::test(Sinkronisasi::class)
            ->assertSee('Koneksi ke cloud')
            ->set('url', 'https://cloud.contoh.id')->set('token', 'sk_rahasia')->set('aktif', true)
            ->call('simpan')->assertHasNoErrors()
            ->call('tes')->assertNotified('Terhubung ke cloud')
            ->assertDontSee('sk_rahasia');

        $this->assertSame('sk_rahasia', app(SinkronService::class)->pengaturan()['token']);

        // Di cloud: halaman khusus super admin untuk membuat token
        config(['app.mode' => 'cloud']);
        $this->get('/admin/sinkronisasi')->assertForbidden();

        $this->actingAs(User::where('email', 'admin@billing.test')->firstOrFail());
        Livewire::test(Sinkronisasi::class)->set('namaServer', 'Rental baru')->call('buatServer')
            ->assertSet('tokenBaru', fn ($t) => str_starts_with((string) $t, 'sk_'));
        $this->assertSame(1, ServerSinkron::count());
    }

    public function test_token_acak_dibuat_di_lokal_lalu_didaftarkan_di_cloud(): void
    {
        $this->actingAs(User::where('email', 'owner@billing.test')->firstOrFail());

        // Server lokal: tombol "Buat token acak" mengisi kolom token (terlihat, belum disimpan)
        $lokal = Livewire::test(Sinkronisasi::class)->call('buatTokenAcak')->assertSet('tokenTerlihat', true);
        $token = $lokal->get('token');
        $this->assertMatchesRegularExpression(ServerSinkron::POLA_TOKEN, $token);
        $lokal->assertSee('Token baru belum berlaku');
        $this->assertNull(app(SinkronService::class)->pengaturan()['token']);

        $lokal->set('url', 'https://cloud.contoh.id')->set('aktif', true)->call('simpan')->assertSet('tokenTerlihat', false);
        $this->assertSame($token, app(SinkronService::class)->pengaturan()['token']);

        // Server cloud: super admin menempel token tersebut
        config(['app.mode' => 'cloud']);
        $this->actingAs(User::where('email', 'admin@billing.test')->firstOrFail());

        Livewire::test(Sinkronisasi::class)->set('namaServer', 'Rental lokal')->set('tokenServer', 'sk_pendek')->call('buatServer')
            ->assertHasErrors('tokenServer');

        Livewire::test(Sinkronisasi::class)->set('namaServer', 'Rental lokal')->set('tokenServer', ' '.$token.' ')->call('buatServer')
            ->assertHasNoErrors()->assertSet('tokenBaru', null);

        $this->assertSame('Rental lokal', ServerSinkron::dariToken($token)?->nama);

        // Token yang sama tidak bisa didaftarkan dua kali
        Livewire::test(Sinkronisasi::class)->set('namaServer', 'Dobel')->set('tokenServer', $token)->call('buatServer')
            ->assertHasErrors('tokenServer');
        $this->assertSame(1, ServerSinkron::count());

        // Token dari lokal langsung diterima endpoint sync
        $this->withToken($token)->getJson('/api/sync/info')->assertOk();
    }
}
