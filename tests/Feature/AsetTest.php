<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Livewire\Operator\AsetModal;
use App\Livewire\Operator\DaftarMaintenance;
use App\Models\Aset;
use App\Models\Cabang;
use App\Models\KasMutasi;
use App\Models\Maintenance;
use App\Models\ModalMutasi;
use App\Models\Pengeluaran;
use App\Models\Unit;
use App\Models\User;
use App\Services\Aset\AsetService;
use App\Services\Aset\MaintenanceService;
use App\Services\Aset\ModalService;
use App\Services\Billing\BillingService;
use App\Services\Billing\ShiftService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AsetTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Cabang $cabang;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        $this->unit = Unit::where('kode', 'TV2')->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function aset(array $data = []): Aset
    {
        return app(AsetService::class)->simpan($data + [
            'cabang_id' => $this->cabang->id,
            'unit_id' => $this->unit->id,
            'kategori' => 'konsol',
            'nama' => 'PlayStation 4 Pro',
            'tanggal_beli' => now()->subMonths(12)->toDateString(),
            'harga_perolehan' => 4_800_000,
            'nilai_sisa' => 0,
            'umur_bulan' => 48,
        ]);
    }

    public function test_penyusutan_garis_lurus(): void
    {
        $a = $this->aset();

        $this->assertSame(100_000, $a->penyusutanPerBulan());
        $this->assertSame(1_200_000, $a->akumulasiPenyusutan());
        $this->assertSame(3_600_000, $a->nilaiBuku());
        $this->assertSame(25, $a->persenPenyusutan());

        // Lewat umur ekonomis: nilai buku = nilai sisa
        $lama = $this->aset(['tanggal_beli' => now()->subMonths(60)->toDateString(), 'nilai_sisa' => 300_000]);
        $this->assertSame(300_000, $lama->nilaiBuku());
    }

    public function test_nilai_sisa_harus_lebih_kecil_dari_harga(): void
    {
        $this->expectException(BillingException::class);
        $this->aset(['nilai_sisa' => 5_000_000]);
    }

    public function test_omzet_jam_operasional_dan_ringkasan_roi(): void
    {
        $a = $this->aset();
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
        $billing = app(BillingService::class);

        $sesi = $billing->mulai($this->unit, $this->owner, ['mode' => 'durasi', 'durasi_menit' => 120]);
        $this->travel(2)->hours();
        $billing->selesai($sesi->refresh(), $this->owner);
        $billing->bayar($sesi->transaksi->refresh(), $this->owner, [['metode' => 'tunai', 'jumlah' => 16_000]]);

        $stat = app(AsetService::class)->statistikUnit([$this->unit->id => $a->tanggal_beli->toDateString()]);
        $this->assertSame(16_000, $stat[$this->unit->id]['omzet']);
        $this->assertGreaterThanOrEqual(7_190, $stat[$this->unit->id]['detik']);

        $r = app(AsetService::class)->ringkasan();
        $this->assertSame(4_800_000, $r['investasi']);
        $this->assertSame(3_600_000, $r['nilai_buku']);
        $this->assertSame(16_000, $r['laba']);
    }

    public function test_aset_dilepas_tidak_dihitung_investasi(): void
    {
        $a = $this->aset();
        app(AsetService::class)->lepas($a, today()->toDateString(), 2_000_000);

        $this->assertSame('dilepas', $a->refresh()->status);
        $this->assertSame(0, app(AsetService::class)->ringkasan()['investasi']);
    }

    public function test_maintenance_unit_servis_lalu_siap_dan_biaya_jadi_pengeluaran(): void
    {
        $a = $this->aset(['interval_servis_hari' => 90]);
        $service = app(MaintenanceService::class);

        $m = $service->buat($this->cabang, $this->owner, ['jenis' => 'perbaikan', 'judul' => 'Stik drift', 'aset_id' => $a->id, 'mulai' => true]);

        $this->assertSame('dikerjakan', $m->status);
        $this->assertSame(Unit::STATUS_SERVIS, $this->unit->refresh()->status);
        $this->assertSame('servis', $a->refresh()->status);

        $service->selesai($m, $this->owner, ['hasil' => 'Ganti analog', 'biaya' => 75_000, 'catat_pengeluaran' => true, 'sumber_dana' => 'rekening']);

        $m->refresh();
        $this->assertSame('selesai', $m->status);
        $this->assertNotNull($m->pengeluaran_id);
        $this->assertSame(75_000, Pengeluaran::find($m->pengeluaran_id)->jumlah);
        $this->assertSame(Unit::STATUS_KOSONG, $this->unit->refresh()->status);
        $this->assertSame('aktif', $a->refresh()->status);
        $this->assertTrue($a->servis_terakhir->isToday());
    }

    public function test_maintenance_tidak_bisa_mulai_saat_unit_dipakai(): void
    {
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
        app(BillingService::class)->mulai($this->unit, $this->owner, ['mode' => 'durasi', 'durasi_menit' => 60]);

        $this->expectException(BillingException::class);
        app(MaintenanceService::class)->buat($this->cabang, $this->owner, ['jenis' => 'perbaikan', 'judul' => 'Cek HDMI', 'unit_id' => $this->unit->id, 'mulai' => true]);
    }

    public function test_jadwal_servis_jatuh_tempo(): void
    {
        $a = $this->aset(['interval_servis_hari' => 90]); // dibeli 12 bulan lalu, belum pernah servis

        $this->assertTrue($a->jatuhTempoServis());
        $this->assertCount(1, app(AsetService::class)->jatuhTempoServis());

        app(MaintenanceService::class)->buat($this->cabang, $this->owner, ['jenis' => 'servis_rutin', 'judul' => 'Bersihkan debu', 'aset_id' => $a->id]);
        $this->assertCount(0, app(AsetService::class)->jatuhTempoServis());
    }

    public function test_prive_dari_kas_laci_dan_pembatalan(): void
    {
        $shift = app(ShiftService::class)->buka($this->owner, $this->cabang, 200_000);
        $service = app(ModalService::class);

        $m = $service->catat($this->cabang, $this->owner, 'prive', 50_000, 'kas_laci', today()->toDateString(), 'Ambil laba');
        $this->assertSame(150_000, (int) KasMutasi::where('shift_id', $shift->id)->sum('jumlah'));

        $service->batalkan($m, $this->owner);
        $this->assertSame(200_000, (int) KasMutasi::where('shift_id', $shift->id)->sum('jumlah'));
        $this->assertSame('dibatalkan', $m->refresh()->status);

        $this->expectException(BillingException::class);
        $service->catat($this->cabang, $this->owner, 'prive', 500_000, 'kas_laci', today()->toDateString(), 'Terlalu besar');
    }

    public function test_halaman_aset_dan_maintenance(): void
    {
        $this->aset();

        Livewire::test(AsetModal::class)
            ->assertSee('PlayStation 4 Pro')
            ->call('tambah')
            ->set('form.nama', 'TV Xiaomi 43')
            ->set('form.kategori', 'tv')
            ->assertSet('form.umur_bulan', 60)
            ->set('form.harga_perolehan', 3_500_000)
            ->call('simpan')
            ->assertHasNoErrors()
            ->call('bukaModal', 'modal')
            ->set('modalJumlah', 15_000_000)
            ->set('modalKeterangan', '2 unit PS5 baru')
            ->call('simpanModal')
            ->assertHasNoErrors()
            ->assertSee('2 unit PS5 baru');

        $this->assertSame(2, Aset::count());
        $this->assertSame(1, ModalMutasi::count());

        Livewire::test(DaftarMaintenance::class)
            ->call('tambah', $this->unit->id)
            ->set('form.judul', 'Stik 2 drift')
            ->call('simpan')
            ->assertHasNoErrors()
            ->assertSee('Stik 2 drift');

        $this->assertSame(1, Maintenance::count());

        $this->get(route('aset'))->assertOk();
        $this->get(route('maintenance'))->assertOk();
        $this->get(route('aset.laporan', 'pdf'))->assertOk();
        $this->get(route('aset.laporan', 'csv'))->assertOk();
    }
}
