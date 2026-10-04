<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Livewire\Operator\Pos;
use App\Models\AuditLog;
use App\Models\Cabang;
use App\Models\Produk;
use App\Models\ProdukStok;
use App\Models\Sesi;
use App\Models\Tenant;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\PosService;
use App\Services\Billing\ShiftService;
use App\Services\Tv\StatusTvService;
use App\Support\HakAkses;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProdukSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Salah order F&B di tagihan unit: batal sebagian / semua, stok kembali, TV ikut berubah, aturan PIN */
class BatalFnbTest extends TestCase
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
        $this->owner->forceFill(['pin' => Hash::make('1234')])->save();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $this->tenancy();
        $this->seed(ProdukSeeder::class);
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
        $this->unit = Unit::where('kode', 'TV2')->firstOrFail();
    }

    private function tenancy(): void
    {
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->cabang->tenant_id);
    }

    private function mie(): Produk
    {
        return Produk::where('kode', 'MI01')->firstOrFail();
    }

    private function stokMie(): int
    {
        return (int) ProdukStok::where('produk_id', $this->mie()->id)->where('cabang_id', $this->cabang->id)->value('qty');
    }

    /** Sesi + 3 mie di tagihan; return [sesi, transaksi, item mie] */
    private function sesiDenganMie(User $user): array
    {
        $sesi = app(BillingService::class)->mulai($this->unit, $user, ['mode' => 'durasi', 'durasi_menit' => 60]);
        $trx = Transaksi::findOrFail($sesi->transaksi_id);
        app(PosService::class)->tambahKeTagihan($trx, $user, [$this->mie()->id => 3]);
        $item = TransaksiItem::where('transaksi_id', $trx->id)->where('jenis', TransaksiItem::JENIS_PRODUK)->firstOrFail();

        return [$sesi, $trx->refresh(), $item];
    }

    public function test_batal_sebagian_lalu_semua_stok_kembali_dan_tv_menyesuaikan(): void
    {
        $stokAwal = $this->stokMie();
        [$sesi, $trx, $item] = $this->sesiDenganMie($this->owner);
        $harga = $this->mie()->harga_jual;
        $totalAwal = $trx->total;
        $this->assertSame($stokAwal - 3, $this->stokMie());

        $this->actingAs($this->owner);
        $pos = Livewire::withQueryParams(['unit' => $this->unit->id])->test(Pos::class)->assertSee('−1')->assertSee('Semua');

        // Batal 1 dari 3 (owner punya izin batal: tanpa PIN)
        $pos->call('batalItem', $item->id, 1, ['reason' => 'Salah pesan']);
        $this->assertSame(2, $item->refresh()->qty);
        $this->assertSame($totalAwal - $harga, $trx->refresh()->total);
        $this->assertSame($stokAwal - 2, $this->stokMie());

        // Batal sisanya: qty 0, Rp0, "(dibatalkan)"; stok kembali penuh
        $pos->call('batalItem', $item->id, 2, ['reason' => 'Tidak jadi']);
        $item->refresh();
        $this->assertSame([0, 0], [$item->qty, (int) $item->subtotal]);
        $this->assertStringEndsWith('(dibatalkan)', $item->nama);
        $this->assertStringContainsString('Batal 1x: Salah pesan', $item->catatan);
        $this->assertSame($totalAwal - 3 * $harga, $trx->refresh()->total);
        $this->assertSame($stokAwal, $this->stokMie());

        // TV: mie tidak tampil lagi di rincian tagihan
        $rincian = (new \ReflectionMethod(StatusTvService::class, 'itemTagihan'))->invoke(app(StatusTvService::class), $trx, 'Asia/Makassar');
        $this->assertNotEmpty($rincian); // sewa tetap tampil
        $this->assertSame([], array_values(array_filter($rincian, fn ($i) => $i['jenis'] === TransaksiItem::JENIS_PRODUK)));
        $this->assertSame(2, AuditLog::where('aksi', 'batal_fnb')->count());

        // Batal seluruh transaksi sesudahnya: stok tidak kembali dua kali
        app(BillingService::class)->batalSesi($sesi->refresh(), $this->owner, 'Tidak jadi main');
        $this->assertSame($stokAwal, $this->stokMie());

        $this->expectException(BillingException::class);
        app(PosService::class)->batalItem($item->id, $this->owner, 1, 'Lagi');
    }

    public function test_kasir_tanpa_pin_dalam_batas_menit_sesudahnya_butuh_pin(): void
    {
        $tenant = Tenant::findOrFail($this->cabang->tenant_id);
        HakAkses::siapkanPermission();
        HakAkses::siapkanTenant($tenant);
        $kasir = User::create(['tenant_id' => $tenant->id, 'name' => 'Kasir Satu', 'username' => 'kasir1', 'email' => 'kasir1@billing.test', 'password' => 'password']);
        $kasir->assignRole('Kasir');
        $kasir->cabang()->attach($this->cabang->id);
        $this->tenancy();
        app(ShiftService::class)->buka($kasir, $this->cabang, 0);

        [, $trx, $item] = $this->sesiDenganMie($kasir);
        $this->actingAs($kasir);

        // Baru dicatat sendiri: tanpa PIN
        Livewire::withQueryParams(['unit' => $this->unit->id])->test(Pos::class)->call('batalItem', $item->id, 1, ['reason' => 'Salah']);
        $this->assertSame(2, $item->refresh()->qty);

        // 6 menit kemudian: butuh PIN supervisor/owner
        $this->travel(6)->minutes();
        $pos = Livewire::withQueryParams(['unit' => $this->unit->id])->test(Pos::class);
        $pos->call('batalItem', $item->id, 1, ['reason' => 'Salah']);
        $this->assertSame(2, $item->refresh()->qty);
        $pos->call('batalItem', $item->id, 1, ['reason' => 'Salah', 'pin' => '1234']);
        $this->assertSame(1, $item->refresh()->qty);
        $this->assertStringContainsString('disetujui', $item->catatan);
    }

    public function test_item_yang_sudah_dibayar_tidak_bisa_dibatalkan_dari_pos(): void
    {
        [$sesi, $trx] = $this->sesiDenganMie($this->owner);
        $item = TransaksiItem::where('transaksi_id', $trx->id)->where('jenis', TransaksiItem::JENIS_PRODUK)->firstOrFail();
        app(BillingService::class)->bayar($trx, $this->owner, [['metode' => 'tunai', 'jumlah' => $trx->total]]);

        try {
            app(PosService::class)->batalItem($item->id, $this->owner, 1, 'Salah');
            $this->fail('Seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('sudah dibayar', $e->getMessage());
        }

        $this->assertSame(3, $item->refresh()->qty); // transaksi DB di-rollback
        $this->assertSame(Sesi::STATUS_BERJALAN, $sesi->refresh()->status);
    }
}
