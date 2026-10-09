<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Filament\Pages\PengaturanOperasional;
use App\Livewire\Operator\RentalPc;
use App\Models\Cabang;
use App\Models\PaketHarga;
use App\Models\PerangkatTv;
use App\Models\TipeKonsol;
use App\Models\Transaksi;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\ShiftService;
use App\Services\LaporanService;
use App\Services\Tv\PairingTvService;
use App\Services\Tv\TvRemoteService;
use App\Support\PengaturanPc;
use App\Support\Tenancy;
use App\Support\WakeOnLan;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Rental PC: menu kasir terpisah, agen kiosk Windows lewat API TV Agent, remote khusus PC, laporan satu */
class RentalPcTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Cabang $cabang;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $this->tenancy();
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
    }

    private function tenancy(): void
    {
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
    }

    private function unitPc(string $kode = 'PC1'): Unit
    {
        $tipe = TipeKonsol::firstOrCreate(['kode' => 'PCG'], ['nama' => 'PC Gaming', 'jenis' => TipeKonsol::JENIS_PC]);
        PaketHarga::firstOrCreate(['nama' => 'Per Jam PC'], ['jenis' => PaketHarga::JENIS_PER_JAM, 'tipe_konsol_id' => $tipe->id, 'harga' => 5000]);

        return Unit::create(['kode' => $kode, 'nama' => "PC {$kode}", 'tipe_konsol_id' => $tipe->id, 'urutan' => 10]);
    }

    /** Pairing agen PC lewat API; return [token, perangkat] */
    private function pasangkanPc(Unit $unit, string $id = 'mesin-01', string $mac = 'aa-bb-cc-00-11-22'): array
    {
        $mulai = $this->postJson('/api/tv/pairing', [
            'android_id' => $id, 'jenis' => 'pc', 'mac' => $mac,
            'merek' => 'Rakitan', 'model' => 'Ryzen 5', 'versi_android' => '11 23H2', 'versi_app' => '0.1.0',
        ])->assertCreated()->json();

        $this->tenancy();
        $perangkat = app(PairingTvService::class)->pasangkan($mulai['kode'], $unit, $this->owner);
        $token = $this->postJson('/api/tv/pairing/cek', ['kunci' => $mulai['kunci']])->assertOk()->json('token');
        $this->tenancy();

        return [$token, $perangkat->refresh()];
    }

    public function test_menu_rental_pc_terpisah_dan_hanya_berisi_unit_pc(): void
    {
        $this->actingAs($this->owner);
        $this->get('/')->assertOk()->assertSee('Rental PS')->assertDontSee('Rental PC');

        $this->unitPc();

        $this->get('/')->assertOk()->assertSee('Rental PC')->assertSee('TV1')->assertDontSee('PC1');
        $this->get('/pc')->assertOk()->assertSee('PC1')->assertSee('PC Gaming')->assertDontSee('TV1');
    }

    public function test_tampilan_daftar_per_halaman_dengan_sesi_dan_remote(): void
    {
        foreach (range(1, 18) as $n) {
            $this->unitPc(sprintf('PC%02d', $n));
        }
        $this->pasangkanPc(Unit::where('kode', 'PC01')->first());
        app(BillingService::class)->mulai(Unit::where('kode', 'PC02')->first(), $this->owner, ['mode' => 'open']);

        // Kotak: semua unit tampil
        Livewire::test(RentalPc::class)->assertSet('tampilan', 'kotak')->assertSee('PC18');

        Livewire::test(RentalPc::class)->set('tampilan', 'daftar')
            ->assertSee('PC01')->assertSee('PC15')->assertDontSee('PC16')
            ->assertSee('1–15 dari 18 unit')->assertSee('Kelola')->assertSee('Remote PC', false)
            ->call('nextPage')->assertSee('PC16')->assertSee('PC18')->assertDontSee('PC03')
            ->set('cari', 'PC17')->assertSee('PC17')->assertDontSee('PC18');

        // Pilihan tampilan diingat
        Livewire::test(RentalPc::class)->assertSet('tampilan', 'daftar');
    }

    public function test_pairing_agen_pc_dan_status_berisi_pengaturan_kiosk(): void
    {
        $pc = $this->unitPc();

        // Kode dari PC tidak bisa dipasang ke unit PS
        $mulai = $this->postJson('/api/tv/pairing', ['android_id' => 'mesin-x', 'jenis' => 'pc'])->json();
        $this->tenancy();
        try {
            app(PairingTvService::class)->pasangkan($mulai['kode'], Unit::where('kode', 'TV1')->first(), $this->owner);
            $this->fail('Kode PC ke unit PS seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('bukan unit PC', $e->getMessage());
        }

        [$token, $perangkat] = $this->pasangkanPc($pc);
        $this->assertTrue($perangkat->isPc());
        $this->assertSame('AA:BB:CC:00:11:22', $perangkat->mac);
        $this->assertSame('windows_pc', $pc->fresh()->tipe_perangkat);

        PengaturanPc::simpan(['akhir_sesi' => 'logoff', 'task_manager_menit' => 3, 'proteksi' => ['usb' => true],
            'aplikasi' => [['nama' => 'Steam', 'path' => 'C:\\Steam\\steam.exe'], ['nama' => '', 'path' => '']]], $this->cabang->id);

        $this->withToken($token)->getJson('/api/tv/status')->assertOk()
            ->assertJsonPath('perangkat.jenis', 'pc')
            ->assertJsonPath('layar', 'kunci')
            ->assertJsonPath('pc.akhir_sesi', 'logoff')
            ->assertJsonPath('pc.task_manager_menit', 3)
            ->assertJsonPath('pc.proteksi.task_manager', true)
            ->assertJsonPath('pc.proteksi.usb', true)
            ->assertJsonCount(1, 'pc.aplikasi');

        // Sesi dari kasir (tamu / member) → kiosk terbuka
        $this->tenancy();
        app(BillingService::class)->mulai($pc, $this->owner, ['mode' => 'durasi', 'durasi_menit' => 60]);
        $this->withToken($token)->getJson('/api/tv/status')->assertJsonPath('layar', 'main');

        // Heartbeat memperbarui MAC
        $this->withToken($token)->postJson('/api/tv/heartbeat', ['mac' => '11:22:33:44:55:66'])->assertOk();
        $this->assertSame('11:22:33:44:55:66', $perangkat->fresh()->mac);
    }

    public function test_remote_pc_dari_kasir(): void
    {
        $pc = $this->unitPc();
        [, $perangkat] = $this->pasangkanPc($pc);
        $perangkat->update(['terakhir_online' => now()]);
        $this->actingAs($this->owner);

        Livewire::test(RentalPc::class)
            ->assertSee('PC online')->assertSee('Tutup game')
            ->call('perintahTv', $pc->id, 'tutup_game')
            ->call('izinTaskManager', $pc->id)
            ->call('perintahTv', $pc->id, 'layar_mati'); // perintah TV ditolak untuk PC

        $antre = collect(TvRemoteService::antrean($perangkat->id));
        $this->assertSame(['tutup_game', 'izin_task_manager'], $antre->pluck('perintah')->all());
        $this->assertSame(5, $antre->last()['data']['menit']);

        // Perintah PC tidak berlaku untuk TV
        $tv = PerangkatTv::create(['tenant_id' => $this->cabang->tenant_id, 'cabang_id' => $this->cabang->id, 'android_id' => 'tv-1', 'status' => 'aktif']);
        $this->expectException(BillingException::class);
        app(TvRemoteService::class)->kirim($tv, 'restart_pc', $this->owner);
    }

    public function test_nyalakan_pc_dititipkan_ke_pc_lain_yang_menyala(): void
    {
        [, $mati] = $this->pasangkanPc($this->unitPc('PC1'), 'mesin-01', 'AA:BB:CC:00:11:22');
        $this->actingAs($this->owner);

        // Belum ada PC lain yang menyala (server dev tanpa ekstensi sockets)
        if (! extension_loaded('sockets')) {
            Livewire::test(RentalPc::class)->call('nyalakanPc', $mati->unit_id)->assertSee('PC mati / offline');
            $this->assertSame([], TvRemoteService::antrean($mati->id));

            [, $hidup] = $this->pasangkanPc($this->unitPc('PC2'), 'mesin-02', 'AA:BB:CC:00:11:33');
            $hidup->update(['terakhir_online' => now()]);

            Livewire::test(RentalPc::class)->call('nyalakanPc', $mati->unit_id);
            $titip = TvRemoteService::antrean($hidup->id)[0];
            $this->assertSame(['bangunkan_pc', 'AA:BB:CC:00:11:22'], [$titip['perintah'], $titip['data']['mac']]);
        }

        $paket = WakeOnLan::paket('AA:BB:CC:00:11:22');
        $this->assertSame(102, strlen($paket));
        $this->assertSame(str_repeat("\xFF", 6), substr($paket, 0, 6));
        $this->assertNull(WakeOnLan::rapikanMac('zz'));
    }

    public function test_pengaturan_pc_di_admin(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(PengaturanOperasional::class)
            ->assertSet('data.pc.akhir_sesi', 'tutup_aplikasi')
            ->assertSet('data.pc.proteksi.task_manager', true)
            ->set('data.pc.akhir_sesi', 'restart')
            ->set('data.pc.task_manager_menit', 10)
            ->set('data.pc.proteksi.task_manager', false)
            ->call('simpan')
            ->assertHasNoErrors();

        $pc = PengaturanPc::ambil($this->cabang->id);
        $this->assertSame(['restart', 10, false], [$pc['akhir_sesi'], $pc['task_manager_menit'], $pc['proteksi']['task_manager']]);
    }

    public function test_laporan_satu_dengan_sewa_pc_terpisah(): void
    {
        $pc = $this->unitPc();
        $billing = app(BillingService::class);

        foreach ([$pc, Unit::where('kode', 'TV2')->first()] as $unit) {
            $sesi = $billing->mulai($unit, $this->owner, ['mode' => 'durasi', 'durasi_menit' => 60]);
            $billing->selesai($sesi, $this->owner);
            $trx = Transaksi::find($sesi->transaksi_id);
            $billing->bayar($trx, $this->owner, [['metode' => 'tunai', 'jumlah' => $trx->sisaTagihan()]]);
        }

        $r = app(LaporanService::class)->ringkasan(today(), now()->endOfDay());
        $this->assertSame(5000, $r['pendapatan_sewa_pc']);
        $this->assertSame(13000, $r['pendapatan_sewa']);

        $this->actingAs($this->owner)->get('/laporan')->assertOk()->assertSee('Sewa PC')->assertSee('Sewa PS');
    }
}
