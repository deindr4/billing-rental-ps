<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Livewire\Operator\KelolaSesi;
use App\Models\AuditLog;
use App\Models\Cabang;
use App\Models\PerangkatTv;
use App\Models\Sesi;
use App\Models\SesiLog;
use App\Models\Tenant;
use App\Models\Transaksi;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\ShiftService;
use App\Services\Tv\PairingTvService;
use App\Support\HakAkses;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Pembatalan tambah waktu (salah pencet) & pembatalan sesi (tidak jadi main), termasuk yang dilihat TV */
class BatalWaktuSesiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Cabang $cabang;

    private Unit $unit;

    private BillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->owner->forceFill(['pin' => Hash::make('1234')])->save();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $this->tenancy();
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
        $this->unit = Unit::where('kode', 'TV2')->firstOrFail(); // Rp8.000/jam
        $this->billing = app(BillingService::class);
    }

    private function tenancy(): void
    {
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->cabang->tenant_id);
    }

    private function mulai(?User $user = null, int $menit = 60): Sesi
    {
        return $this->billing->mulai($this->unit, $user ?? $this->owner, ['mode' => 'durasi', 'durasi_menit' => $menit]);
    }

    private function logTambah(Sesi $sesi): SesiLog
    {
        return SesiLog::where('sesi_id', $sesi->id)->where('jenis', 'tambah_waktu')->latest()->firstOrFail();
    }

    private function kasir(): User
    {
        $tenant = Tenant::where('kode', 'DGH')->firstOrFail();
        HakAkses::siapkanPermission();
        HakAkses::siapkanTenant($tenant);
        $kasir = User::create(['tenant_id' => $tenant->id, 'name' => 'Kasir Satu', 'username' => 'kasir1', 'email' => 'kasir1@billing.test', 'password' => 'password']);
        $kasir->forceFill(['pin' => Hash::make('5555')])->save();
        $kasir->assignRole('Kasir');
        $kasir->cabang()->attach($this->cabang->id);
        $this->tenancy();
        app(ShiftService::class)->buka($kasir, $this->cabang, 0);

        return $kasir;
    }

    public function test_salah_tambah_1_jam_dibatalkan_jam_selesai_kembali_persis(): void
    {
        $sesi = $this->mulai();
        $awal = $sesi->fresh()->berakhir_pada->copy();

        $this->billing->tambahWaktu($sesi, $this->owner, 60);
        $log = $this->logTambah($sesi);
        $this->assertSame($awal->toIso8601String(), $log->data['berakhir_sebelum']); // jam sebelum ditambah tercatat
        $this->assertSame(16000, Transaksi::findOrFail($sesi->transaksi_id)->total);

        $this->billing->batalTambahWaktu($sesi, $log->id, $this->owner, 'Salah pencet, harusnya 30 menit');

        $sesi->refresh();
        $this->assertTrue($sesi->berakhir_pada->equalTo($awal));
        $this->assertSame(60, $sesi->durasi_menit);

        $trx = Transaksi::findOrFail($sesi->transaksi_id);
        $this->assertSame(8000, $trx->total);
        $this->assertTrue($trx->items->contains(fn ($i) => $i->nama === 'Tambah waktu 60 menit (dibatalkan)' && $i->subtotal === 0
            && str_contains($i->catatan, 'Salah pencet')));

        $batal = SesiLog::where('sesi_id', $sesi->id)->where('jenis', 'batal_tambah_waktu')->firstOrFail();
        $this->assertSame($log->id, $batal->data['log_id']);
        $this->assertSame($awal->toIso8601String(), $batal->data['berakhir_sesudah']);
        $this->assertTrue(AuditLog::where('aksi', 'batal_tambah_waktu')->exists());

        // Lalu tambah 30 menit yang benar
        $this->billing->tambahWaktu($sesi, $this->owner, 30);
        $this->assertTrue($sesi->fresh()->berakhir_pada->equalTo($awal->copy()->addMinutes(30)));
        $this->assertSame(12000, Transaksi::findOrFail($sesi->transaksi_id)->total);

        // Tidak bisa dibatalkan dua kali
        $this->expectException(BillingException::class);
        $this->billing->batalTambahWaktu($sesi, $log->id, $this->owner, 'lagi');
    }

    public function test_tetap_benar_setelah_pause_dan_tambah_waktu_lain(): void
    {
        $sesi = $this->mulai();
        $awal = $sesi->fresh()->berakhir_pada->copy();

        $this->billing->tambahWaktu($sesi, $this->owner, 30);
        $this->billing->tambahWaktu($sesi, $this->owner, 60); // salah
        $salah = $this->logTambah($sesi);

        // Pause 3 menit: jam selesai bergeser 3 menit
        $this->travel(5)->minutes();
        $this->billing->pause($sesi, $this->owner);
        $this->travel(3)->minutes();
        $this->billing->resume($sesi, $this->owner);

        $this->billing->batalTambahWaktu($sesi, $salah->id, $this->owner, 'Salah pencet');

        // = awal + 30 menit + 3 menit jeda (bukan disalin mentah dari "berakhir_sebelum")
        $this->assertEqualsWithDelta($awal->copy()->addMinutes(33)->getTimestamp(), $sesi->fresh()->berakhir_pada->getTimestamp(), 2);
    }

    public function test_ditolak_bila_waktu_tambahan_sudah_terpakai_atau_sudah_dibayar(): void
    {
        $sesi = $this->mulai(menit: 30);
        $this->billing->tambahWaktu($sesi, $this->owner, 15);
        $log = $this->logTambah($sesi);

        // 40 menit kemudian: 10 dari 15 menit tambahan sudah terpakai
        $this->travel(40)->minutes();

        try {
            $this->billing->batalTambahWaktu($sesi, $log->id, $this->owner, 'telat');
            $this->fail('Seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('sudah mulai terpakai', $e->getMessage());
        }

        // Sudah dibayar di depan: tidak dibatalkan di sini (uang harus tercatat kembali lewat batal transaksi)
        $this->travel(-40)->minutes();
        $sesi2 = $this->billing->mulai(Unit::where('kode', 'TV3')->firstOrFail(), $this->owner, ['mode' => 'durasi', 'durasi_menit' => 60]);
        $this->billing->tambahWaktu($sesi2, $this->owner, 60);
        $trx = Transaksi::findOrFail($sesi2->transaksi_id);
        $this->billing->bayar($trx, $this->owner, [['metode' => 'tunai', 'jumlah' => $trx->sisaTagihan(), 'diterima' => $trx->sisaTagihan()]]);

        try {
            $this->billing->batalTambahWaktu($sesi2, $this->logTambah($sesi2)->id, $this->owner, 'Salah');
            $this->fail('Seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('sudah dibayar', $e->getMessage());
        }

        $this->assertSame(16000, $trx->fresh()->total); // tidak ada yang berubah (rollback)
    }

    public function test_kasir_tanpa_pin_dalam_batas_menit_sesudahnya_butuh_pin(): void
    {
        $kasir = $this->kasir();
        $sesi = $this->mulai($kasir);
        $this->billing->tambahWaktu($sesi, $kasir, 60);

        $komponen = Livewire::actingAs($kasir)->test(KelolaSesi::class)->dispatch('buka-kelola-sesi', unitId: $this->unit->id);
        $r = $komponen->instance()->riwayatTambah->first();
        $this->assertFalse($r['butuhPin']);
        $komponen->assertSee('Batalkan');

        $komponen->call('batalTambahWaktu', $r['id'], ['reason' => 'Salah pencet']);
        $this->assertSame(8000, Transaksi::findOrFail($sesi->transaksi_id)->total);

        // Tambah lagi, 6 menit kemudian: perlu PIN supervisor/owner (PIN kasir sendiri ditolak)
        $this->billing->tambahWaktu($sesi, $kasir, 60);
        $this->travel(6)->minutes();
        $komponen = Livewire::actingAs($kasir)->test(KelolaSesi::class)->dispatch('buka-kelola-sesi', unitId: $this->unit->id);
        $r = $komponen->instance()->riwayatTambah->first();
        $this->assertTrue($r['butuhPin']);

        $komponen->call('batalTambahWaktu', $r['id'], ['reason' => 'Salah', 'pin' => '5555']);
        $this->assertSame(16000, Transaksi::findOrFail($sesi->transaksi_id)->total);

        $komponen->call('batalTambahWaktu', $r['id'], ['reason' => 'Salah', 'pin' => '1234']);
        $this->assertSame(8000, Transaksi::findOrFail($sesi->transaksi_id)->total);
    }

    public function test_batal_sesi_tidak_jadi_main_unit_kosong_dan_tv_terkunci(): void
    {
        // TV terpasang di unit
        $mulai = $this->postJson('/api/tv/pairing', ['android_id' => 'tv-x', 'merek' => 'TCL', 'model' => 'TV', 'versi_android' => '14', 'versi_app' => '0.6.1'])->json();
        $this->tenancy();
        app(PairingTvService::class)->pasangkan($mulai['kode'], $this->unit, $this->owner);
        $token = $this->postJson('/api/tv/pairing/cek', ['kunci' => $mulai['kunci']])->json('token');

        $kasir = $this->kasir();
        $sesi = $this->mulai($kasir);
        $this->withToken($token)->getJson('/api/tv/status')->assertJsonPath('layar', 'main');

        $komponen = Livewire::actingAs($kasir)->test(KelolaSesi::class)->dispatch('buka-kelola-sesi', unitId: $this->unit->id);
        $this->assertFalse($komponen->instance()->batalSesiButuhPin);

        // Alasan wajib
        $komponen->call('batalSesi', ['reason' => '']);
        $this->assertSame(Sesi::STATUS_BERJALAN, $sesi->fresh()->status);

        $komponen->call('batalSesi', ['reason' => 'Tidak jadi main'])->assertSet('buka', false);

        $this->assertSame(Sesi::STATUS_DIBATALKAN, $sesi->fresh()->status);
        $this->assertSame(Unit::STATUS_KOSONG, $this->unit->fresh()->status);
        $trx = Transaksi::findOrFail($sesi->transaksi_id);
        $this->assertSame(Transaksi::STATUS_DIBATALKAN, $trx->status);
        $this->assertSame('Tidak jadi main', $trx->alasan_batal);

        // TV: layar kunci, tidak ada sesi
        $this->withToken($token)->getJson('/api/tv/status')->assertJsonPath('layar', 'kunci')->assertJsonPath('sesi', null);
        $this->assertNotNull(PerangkatTv::withoutGlobalScopes()->first());
    }

    public function test_batal_sesi_lewat_batas_menit_butuh_pin(): void
    {
        $kasir = $this->kasir();
        $sesi = $this->mulai($kasir);
        $this->travel(6)->minutes();

        $komponen = Livewire::actingAs($kasir)->test(KelolaSesi::class)->dispatch('buka-kelola-sesi', unitId: $this->unit->id);
        $this->assertTrue($komponen->instance()->batalSesiButuhPin);

        $komponen->call('batalSesi', ['reason' => 'Tidak jadi main', 'pin' => '5555']);
        $this->assertSame(Sesi::STATUS_BERJALAN, $sesi->fresh()->status);

        $komponen->call('batalSesi', ['reason' => 'Tidak jadi main', 'pin' => '1234']);
        $this->assertSame(Sesi::STATUS_DIBATALKAN, $sesi->fresh()->status);
    }
}
