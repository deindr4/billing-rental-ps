<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Livewire\Operator\Stok;
use App\Models\AuditLog;
use App\Models\Cabang;
use App\Models\Produk;
use App\Models\ProdukStok;
use App\Models\StokMutasi;
use App\Models\Tenant;
use App\Models\TransaksiItem;
use App\Models\User;
use App\Services\Billing\InventoriService;
use App\Services\Billing\PosService;
use App\Services\Billing\ShiftService;
use App\Services\Billing\StokService;
use App\Support\HakAkses;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProdukSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Staf salah input harga pokok saat stok masuk: owner mengoreksi, HPP rata-rata & HPP penjualan dihitung ulang */
class KoreksiHppTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Cabang $cabang;

    private Produk $produk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->cabang->tenant_id);
        $this->seed(ProdukSeeder::class);
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);

        // Produk baru berstok nol supaya hitungan jelas
        $this->produk = Produk::create([
            'tenant_id' => $this->cabang->tenant_id, 'nama' => 'Kopi Uji', 'kode' => 'KPU1',
            'kategori_produk_id' => Produk::first()->kategori_produk_id, 'harga_jual' => 5000, 'lacak_stok' => true, 'is_active' => true,
        ]);
    }

    private function masuk(int $qty, int $harga): StokMutasi
    {
        app(InventoriService::class)->stokMasuk($this->cabang, $this->owner, [['produk_id' => $this->produk->id, 'qty' => $qty, 'harga' => $harga]]);

        return StokMutasi::where('produk_id', $this->produk->id)->where('jenis', 'masuk')->latest('created_at')->firstOrFail();
    }

    private function jual(int $qty): TransaksiItem
    {
        $trx = app(PosService::class)->jual($this->cabang, $this->owner, [$this->produk->id => $qty]);

        return TransaksiItem::where('transaksi_id', $trx->id)->firstOrFail();
    }

    private function hppRata(): int
    {
        return (int) ProdukStok::where('produk_id', $this->produk->id)->where('cabang_id', $this->cabang->id)->value('hpp_rata');
    }

    public function test_koreksi_menghitung_ulang_hpp_rata_dan_penjualan_sesudahnya(): void
    {
        $salah = $this->masuk(10, 2000);          // seharusnya 1.000
        $this->travel(1)->minutes();
        $jual1 = $this->jual(3);                  // HPP 2.000 (salah)
        $this->travel(1)->minutes();
        $this->masuk(10, 1500);                   // rata-rata (7×2000 + 10×1500) / 17
        $this->travel(1)->minutes();
        $jual2 = $this->jual(2);
        $this->assertSame([2000, 1706], [(int) $jual1->hpp_satuan, (int) $jual2->fresh()->hpp_satuan]);

        $hasil = app(StokService::class)->koreksiHargaPokok($salah, 1000, $this->owner, 'Salah ketik, nota Rp 1.000');

        // Setelah koreksi: 1.000 lalu (7×1000 + 10×1500) / 17 = 1.294
        $this->assertSame(1000, (int) $jual1->fresh()->hpp_satuan);
        $this->assertSame(1294, (int) $jual2->fresh()->hpp_satuan);
        $this->assertSame(1294, $this->hppRata());
        $this->assertSame(['hpp_lama' => 1706, 'hpp_baru' => 1294, 'item' => 2], $hasil);
        $this->assertSame(15, (int) ProdukStok::where('produk_id', $this->produk->id)->value('qty')); // jumlah stok tetap
        $this->assertSame(1000, (int) $salah->fresh()->harga_pokok);
        $this->assertStringContainsString('dikoreksi Rp2000 → Rp1000', $salah->fresh()->keterangan);
        $this->assertSame(1, AuditLog::where('aksi', 'koreksi_hpp')->count());

        // Selain stok masuk tidak bisa dikoreksi
        $this->expectException(BillingException::class);
        app(StokService::class)->koreksiHargaPokok(StokMutasi::where('jenis', 'penjualan')->firstOrFail(), 1, $this->owner, 'Coba');
    }

    public function test_hanya_owner_yang_bisa_mengoreksi_dari_halaman_stok(): void
    {
        $salah = $this->masuk(10, 2000);

        Livewire::actingAs($this->owner)->test(Stok::class)
            ->set('tab', 'riwayat')
            ->assertSee('mulaiKoreksi')
            ->call('mulaiKoreksi', $salah->id)
            ->assertSet('koreksiHarga', 2000)
            ->set('koreksiHarga', 1000)
            ->call('simpanKoreksi')->assertHasErrors('koreksiAlasan')
            ->set('koreksiAlasan', 'Salah ketik')
            ->call('simpanKoreksi')
            ->assertSet('koreksiId', null);
        $this->assertSame(1000, $this->hppRata());

        // Kasir: tombol tidak tampil & tidak bisa memanggil langsung
        $tenant = Tenant::findOrFail($this->cabang->tenant_id);
        HakAkses::siapkanPermission();
        HakAkses::siapkanTenant($tenant);
        $kasir = User::create(['tenant_id' => $tenant->id, 'name' => 'Kasir', 'username' => 'kasir9', 'email' => 'kasir9@billing.test', 'password' => 'password']);
        $kasir->assignRole('Kasir');
        $kasir->cabang()->attach($this->cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        Livewire::actingAs($kasir)->test(Stok::class)
            ->set('tab', 'riwayat')
            ->assertDontSee('mulaiKoreksi')
            ->set('koreksiId', $salah->id)->set('koreksiHarga', 5)->set('koreksiAlasan', 'iseng')
            ->call('simpanKoreksi');
        $this->assertSame(1000, $this->hppRata());
    }
}
