<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Livewire\Operator\KelolaSesi;
use App\Livewire\Operator\MulaiSesi;
use App\Models\Aksesori;
use App\Models\Cabang;
use App\Models\SesiAksesori;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\AksesoriService;
use App\Services\Billing\BillingService;
use App\Services\Billing\ShiftService;
use App\Services\LaporanService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Sewa aksesori di lokasi (flat per sesi / per jam) + warna penanda kartu unit */
class AksesoriTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Cabang $cabang;

    private Aksesori $headset;

    private Aksesori $stik;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->cabang->tenant_id);
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);

        $this->headset = Aksesori::create(['nama' => 'Headset', 'harga' => 5000, 'satuan' => 'sesi', 'stok' => 2]);
        $this->stik = Aksesori::create(['nama' => 'Stik tambahan', 'harga' => 2000, 'satuan' => 'jam', 'stok' => 3]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function item(string $transaksiId, string $awalan): TransaksiItem
    {
        return TransaksiItem::where('transaksi_id', $transaksiId)->where('nama', 'like', "{$awalan}%")->firstOrFail();
    }

    public function test_flat_langsung_ditagih_per_jam_dihitung_saat_selesai(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        $billing = app(BillingService::class);
        $sesi = $billing->mulai(Unit::where('kode', 'TV2')->first(), $this->owner, [
            'mode' => 'durasi', 'durasi_menit' => 120, 'aksesori' => [$this->headset->id => 1, $this->stik->id => 2],
        ]);
        $trx = Transaksi::find($sesi->transaksi_id);

        // Sewa 2 jam Rp16.000 + headset flat Rp5.000; stik per jam belum ditagih
        $this->assertSame(16_000 + 5_000, $trx->total);
        $this->assertSame(0, $this->item($trx->id, 'Sewa Stik')->subtotal);
        $this->assertSame([1, 1], [$this->headset->tersedia(), $this->stik->tersedia()]);

        // Stok tidak cukup untuk unit lain
        try {
            $billing->mulai(Unit::where('kode', 'TV3')->first(), $this->owner, ['mode' => 'open', 'aksesori' => [$this->stik->id => 2]]);
            $this->fail('Stik tersisa 1, seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('tersisa 1', $e->getMessage());
        }

        // Selesai setelah 90 menit: stik 2 × Rp2.000/jam × 90 menit = Rp6.000 (blok 15 menit)
        Carbon::setTestNow(now()->addMinutes(90));
        $billing->selesai($sesi, $this->owner);

        $stik = $this->item($trx->id, 'Sewa Stik');
        $this->assertSame(6_000, $stik->subtotal);
        $this->assertSame('Disewa 90 menit', $stik->catatan);
        $this->assertSame(16_000 + 5_000 + 6_000, $trx->fresh()->total);
        $this->assertSame([2, 3], [$this->headset->tersedia(), $this->stik->tersedia()]); // otomatis kembali

        $billing->bayar($trx->fresh(), $this->owner, [['metode' => 'tunai', 'jumlah' => 27_000]]);
        $this->assertSame(11_000, app(LaporanService::class)->ringkasan(today(), now()->endOfDay())['pendapatan_aksesori']);
    }

    public function test_kembalikan_lebih_awal_dan_batal_salah_input(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        $sesi = app(BillingService::class)->mulai(Unit::where('kode', 'TV2')->first(), $this->owner, ['mode' => 'open']);
        $layanan = app(AksesoriService::class);

        // Disewa di menit 30, dikembalikan menit 60: 30 menit → minimal 60 menit = Rp2.000
        Carbon::setTestNow(now()->addMinutes(30));
        $sewa = $layanan->sewa($sesi, $this->stik, 1, $this->owner);
        Carbon::setTestNow(now()->addMinutes(30));
        $layanan->kembalikan($sewa, $this->owner);
        $this->assertSame(2_000, $this->item($sesi->transaksi_id, 'Sewa Stik')->subtotal);
        $this->assertSame(3, $this->stik->tersedia());

        // Salah input: batal ≤ 5 menit tanpa biaya; lewat dari itu ditolak
        $salah = $layanan->sewa($sesi, $this->headset, 2, $this->owner);
        $layanan->batal($salah, $this->owner);
        $this->assertSame(0, (int) TransaksiItem::whereKey($salah->transaksi_item_id)->value('subtotal'));
        $this->assertSame(2, $this->headset->tersedia());

        $lama = $layanan->sewa($sesi, $this->headset, 1, $this->owner);
        Carbon::setTestNow(now()->addMinutes(6));
        $this->expectException(BillingException::class);
        $layanan->batal($lama, $this->owner);
    }

    public function test_halaman_admin_aksesori(): void
    {
        $this->actingAs($this->owner)->get('/admin/aksesori')->assertOk()
            ->assertSee('Stik tambahan')->assertSee('Rp2.000 / jam')->assertSee('0 / 3');
        $this->get('/admin/aksesori/create')->assertOk()->assertSee('Per jam (mengikuti lama sewa)');
        $this->get('/admin/unit')->assertOk()->assertSee('TV2');
    }

    public function test_kasir_memilih_aksesori_dan_kartu_berwarna(): void
    {
        $this->actingAs($this->owner);
        $tv2 = Unit::where('kode', 'TV2')->first();

        Livewire::test(MulaiSesi::class)->call('bukaUntuk', $tv2->id)
            ->assertSee('Sewa aksesori')->assertSee('Headset')
            ->call('ubahAksesori', $this->headset->id, 1)
            ->call('ubahAksesori', $this->headset->id, 5) // dibatasi stok tersedia (2)
            ->assertSet('aksesori', [$this->headset->id => 2])
            ->call('simpan')->assertHasNoErrors();

        $this->assertSame(2, (int) SesiAksesori::where('aksesori_id', $this->headset->id)->sum('qty'));

        Livewire::test(KelolaSesi::class)->call('bukaUntuk', $tv2->id)
            ->assertSee('Aksesori disewa')->assertSee('Sewa Aksesori')
            ->call('kePanel', 'aksesori')
            ->call('ubahAksesori', $this->stik->id, 1)
            ->call('sewaAksesori')->assertHasNoErrors();

        // Kartu: chip aksesori + warna unit (otomatis dari palet urutan 2, atau pilihan admin)
        $this->get('/')->assertOk()->assertSee('Headset ×2, Stik tambahan')->assertSee(Unit::PALET[1], false);
        $tv2->update(['warna' => '#123456']);
        $this->get('/')->assertSee('border-left: 4px solid #123456', false);
    }
}
