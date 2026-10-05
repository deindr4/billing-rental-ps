<?php

namespace Tests\Feature;

use App\Livewire\Operator\AnalisaPintar;
use App\Models\Cabang;
use App\Models\Produk;
use App\Models\ProdukStok;
use App\Models\Shift;
use App\Models\StokMutasi;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\Analisa\AnalisaService;
use App\Services\Billing\ShiftService;
use App\Support\Audit;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProdukSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Analisa pintar berbasis aturan: skor risiko kasir, keuangan, operasional, stok */
class AnalisaPintarTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $kasir;

    private Cabang $cabang;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->cabang->tenant_id);
        $this->seed(ProdukSeeder::class);

        $this->kasir = User::create(['tenant_id' => $this->cabang->tenant_id, 'name' => 'Kasir Curang',
            'email' => 'kasir@billing.test', 'password' => Hash::make('rahasia123')]);
        $this->kasir->assignRole('Kasir');
    }

    private function transaksi(User $user, string $status, int $total): Transaksi
    {
        static $n = 0;

        return Transaksi::create([
            'tenant_id' => $this->cabang->tenant_id, 'cabang_id' => $this->cabang->id, 'user_id' => $user->id,
            'nomor' => 'TES-'.(++$n), 'jenis' => Transaksi::JENIS_POS, 'status' => $status,
            'subtotal' => $total, 'total' => $total,
            'dibayar_pada' => $status === Transaksi::STATUS_LUNAS ? now() : null,
            'dibatalkan_pada' => $status === Transaksi::STATUS_DIBATALKAN ? now() : null,
        ]);
    }

    private function skenario(): void
    {
        // Kasir: 4 dari 10 transaksi dibatalkan, owner 0 dari 10
        foreach (range(1, 6) as $_) {
            $this->transaksi($this->kasir, Transaksi::STATUS_LUNAS, 20_000);
        }
        foreach (range(1, 4) as $_) {
            $this->transaksi($this->kasir, Transaksi::STATUS_DIBATALKAN, 30_000);
        }
        foreach (range(1, 10) as $_) {
            $this->transaksi($this->owner, Transaksi::STATUS_LUNAS, 20_000);
        }

        // Kas kurang Rp60.000 di shift kasir
        $shift = app(ShiftService::class)->buka($this->kasir, $this->cabang, 0);
        app(ShiftService::class)->tutup($shift, $this->kasir, 0);
        Shift::whereKey($shift->id)->update(['selisih' => -60_000]);

        // Bypass TV 3× oleh kasir
        foreach (range(1, 3) as $_) {
            Audit::catat('bypass_tv', 'Bypass TV', anomali: true, tenantId: $this->cabang->tenant_id,
                cabangId: $this->cabang->id, userId: $this->kasir->id);
        }

        // Indomie dijual rugi (pokok 8000 > jual 7000) + hilang 5 saat opname
        $mie = Produk::where('kode', 'MI01')->firstOrFail();
        ProdukStok::where('produk_id', $mie->id)->update(['hpp_rata' => 8000, 'qty' => 20]);
        StokMutasi::create(['tenant_id' => $this->cabang->tenant_id, 'cabang_id' => $this->cabang->id, 'produk_id' => $mie->id,
            'user_id' => $this->kasir->id, 'jenis' => 'opname', 'qty' => -5, 'keterangan' => 'Opname']);
    }

    public function test_temuan_dari_semua_bagian(): void
    {
        $this->skenario();
        $hasil = app(AnalisaService::class)->semua(today()->startOfMonth(), today()->endOfDay());

        $kasir = $hasil['kasir']['baris']->firstWhere('nama', 'Kasir Curang');
        $this->assertSame(65, $kasir['skor']); // batal 25 + kas kurang 25 + bypass 15
        $this->assertSame(4, $kasir['batal']);
        $this->assertSame(60_000, $kasir['kas_kurang']);
        $this->assertSame(0, $hasil['kasir']['baris']->firstWhere('user_id', $this->owner->id)['skor']);

        $judul = $hasil['temuan']->pluck('judul');
        $this->assertContains('Kasir Curang: skor risiko 65', $judul);
        $this->assertContains('Indomie Goreng dijual rugi', $judul);
        $this->assertTrue($judul->contains(fn ($j) => str_starts_with($j, 'Barang hilang saat opname Rp 40.000')));
        $this->assertSame('tinggi', $hasil['temuan']->first()['tingkat']); // urut: penting dulu

        $hilang = $hasil['stok']['hilang']->first();
        $this->assertSame([5, 40_000, 'Kasir Curang'], [$hilang['qty'], $hilang['nilai'], $hilang['oleh']]);
        $this->assertSame(320_000, $hasil['keuangan']['r']['omzet_bersih']);
    }

    public function test_halaman_khusus_owner_dengan_tab(): void
    {
        $this->skenario();

        $this->actingAs($this->owner)->get('/analisa')->assertOk()
            ->assertSee('Analisa Pintar')->assertSee('Kasir Curang: skor risiko 65')->assertSee('data-rahasia', false);

        $this->actingAs($this->owner);
        Livewire::test(AnalisaPintar::class)
            ->call('pilihTab', 'kasir')->assertSee('Skor risiko per kasir')->assertSee('bypass / kode darurat TV 3×')
            ->call('pilihTab', 'keuangan')->assertSee('Rasio kesehatan')->assertSee('Proyeksi akhir bulan')
            ->call('pilihTab', 'operasional')->assertSee('Utilisasi unit')->assertSee('Omzet per hari')
            ->call('pilihTab', 'stok')->assertSee('Hilang saat opname')->assertSee('Margin tipis');

        // Kasir tidak boleh membuka analisa
        $this->actingAs($this->kasir);
        Livewire::test(AnalisaPintar::class)->assertForbidden();
    }
}
