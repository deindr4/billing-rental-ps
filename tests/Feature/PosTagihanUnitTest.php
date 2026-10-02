<?php

namespace Tests\Feature;

use App\Livewire\Operator\Pos;
use App\Models\Cabang;
use App\Models\Produk;
use App\Models\Transaksi;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\PosService;
use App\Services\Billing\ShiftService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProdukSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pesan F&B berkali-kali selama main, lalu saat bayar ada yang lupa dicatat:
 * POS menampilkan isi tagihan unit (dengan jam) sebelum menambah, lalu langsung ke pembayaran.
 */
class PosTagihanUnitTest extends TestCase
{
    use RefreshDatabase;

    private function produk(string $kode): string
    {
        return Produk::where('kode', $kode)->value('id');
    }

    public function test_riwayat_tagihan_unit_tampil_di_pos_lalu_lanjut_bayar(): void
    {
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($cabang->tenant_id, $cabang->id);
        $this->seed(ProdukSeeder::class); // Indomie, Es Teh, Chips, Kacang, ...
        app(PermissionRegistrar::class)->setPermissionsTeamId($cabang->tenant_id);
        app(ShiftService::class)->buka($owner, $cabang, 0);

        $unit = Unit::where('kode', 'TV2')->firstOrFail();
        $sesi = app(BillingService::class)->mulai($unit, $owner, ['mode' => 'durasi', 'durasi_menit' => 120]);
        $trx = Transaksi::findOrFail($sesi->transaksi_id);
        $pos = app(PosService::class);

        // Menit ke-5 mie, menit ke-30 minum + cemilan
        $this->travel(5)->minutes();
        $pos->tambahKeTagihan($trx, $owner, [$this->produk('MI01') => 1]);
        $jamMie = now()->format('H:i');
        $this->travel(25)->minutes();
        $pos->tambahKeTagihan($trx, $owner, [$this->produk('MN01') => 2, $this->produk('SN02') => 1]);

        $this->actingAs($owner);

        // Dari Kelola Sesi "Tambah F&B" (?unit=...): isi tagihan langsung terlihat beserta jam
        $komponen = Livewire::withQueryParams(['unit' => $unit->id])->test(Pos::class)
            ->assertSee('Sudah di tagihan '.$unit->nama)
            ->assertSee('Indomie Goreng')
            ->assertSee('Es Teh ×2')
            ->assertSee('Kacang')
            ->assertSee($jamMie)
            ->assertSee('Sedang main')
            ->assertSee('Lihat tagihan '.$unit->nama); // bar HP walau keranjang kosong

        // Total sekarang & setelah ditambah keripik yang lupa dicatat
        $sebelum = $trx->fresh()->total;
        $komponen->call('tambah', $this->produk('SN01'))
            ->assertSee('Setelah ditambah')
            ->assertSee(number_format($sebelum + 1000, 0, ',', '.'));

        // Sesi masih berjalan: cukup masuk tagihan, tidak membuka pembayaran
        $komponen->call('proses')->assertNotDispatched('buka-pembayaran');
        $this->assertSame($sebelum + 1000, $trx->fresh()->total);

        // Sesi selesai, saat membayar pelanggan baru bilang ambil keripik lagi
        app(BillingService::class)->selesai($sesi, $owner);
        $sebelum = $trx->fresh()->total;

        Livewire::withQueryParams(['unit' => $unit->id])->test(Pos::class)
            ->assertSee('Menunggu bayar')
            ->assertSee('Chips') // keripik pertama tadi tercatat
            ->call('tambah', $this->produk('SN01'))
            ->call('proses')
            ->assertDispatched('buka-pembayaran', transaksiId: $trx->id); // langsung ke pembayaran

        $this->assertSame($sebelum + 1000, $trx->fresh()->total);
    }
}
