<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Livewire\Operator\Pembayaran;
use App\Models\AuditLog;
use App\Models\Cabang;
use App\Models\KasMutasi;
use App\Models\Produk;
use App\Models\Sesi;
use App\Models\Transaksi;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\PosService;
use App\Services\Billing\ShiftService;
use App\Services\Struk\StrukService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProdukSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Rombongan main di beberapa TV + jajan F&B, bayar sekali: tiap tagihan tetap tercatat sendiri, struk gabungan */
class BayarGabunganTest extends TestCase
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
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->cabang->tenant_id);
        $this->seed(ProdukSeeder::class);
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
    }

    /** Sesi durasi 60 menit di unit, langsung diselesaikan → tagihan menunggu bayar */
    private function sesiSelesai(string $kode): Transaksi
    {
        $sesi = app(BillingService::class)->mulai(Unit::where('kode', $kode)->firstOrFail(), $this->owner, ['mode' => 'durasi', 'durasi_menit' => 60]);
        app(BillingService::class)->selesai($sesi, $this->owner);

        return Transaksi::findOrFail($sesi->transaksi_id);
    }

    public function test_bayar_tiga_tagihan_sekaligus_dari_dialog_pembayaran(): void
    {
        $tv1 = $this->sesiSelesai('TV1');
        $tv2 = $this->sesiSelesai('TV2');
        $pos = app(PosService::class)->jual($this->cabang, $this->owner, [Produk::where('kode', 'MI01')->value('id') => 2]);
        $total = $tv1->sisaTagihan() + $tv2->sisaTagihan() + $pos->sisaTagihan();

        $this->actingAs($this->owner);
        $dialog = Livewire::test(Pembayaran::class)->call('bukaUntuk', $tv1->id)
            ->assertSee('Bayar sekaligus dengan tagihan lain')
            ->assertSee($tv2->nomor)->assertSee($pos->nomor)
            ->call('toggleGabung', $tv2->id)
            ->call('toggleGabung', $pos->id)
            ->assertSee('Total gabungan 3 tagihan')
            ->assertSet('baris.0.jumlah', $total)
            ->set('baris.0.diterima', $total + 3000)
            ->call('simpan')
            ->assertHasNoErrors()
            ->assertDispatched('ui:bayar-berhasil');

        foreach ([$tv1, $tv2, $pos] as $t) {
            $this->assertTrue($t->fresh()->isLunas(), $t->nomor);
        }

        $grup = $tv1->fresh()->grup_bayar;
        $this->assertNotNull($grup);
        $this->assertSame(3, Transaksi::where('grup_bayar', $grup)->count());
        $this->assertSame(3000, (int) Transaksi::where('grup_bayar', $grup)->sum('kembalian'));
        $this->assertSame($total, (int) KasMutasi::where('jenis', 'penjualan')->sum('jumlah')); // kas laci = total, bukan uang diterima
        $this->assertSame(Unit::STATUS_KOSONG, Unit::where('kode', 'TV2')->value('status'));
        $this->assertSame(1, AuditLog::where('aksi', 'bayar_gabungan')->count());

        // Satu struk gabungan: kedua unit + POS, total & kembalian gabungan (dicetak dari tagihan mana pun)
        $struk = collect(app(StrukService::class)->baris(app(StrukService::class)->data($tv2->fresh()), 32))->pluck('t')->implode("\n");
        $this->assertStringContainsString('Bayar gabungan', $struk);
        $this->assertStringContainsString($tv1->nomor, $struk);
        $this->assertStringContainsString($pos->nomor, $struk);
        $this->assertStringContainsString(number_format($total, 0, ',', '.'), $struk);
        $this->assertStringContainsString('Kembalian', $struk);
    }

    public function test_sesi_masih_main_tidak_bisa_digabung_dan_saldo_ditolak(): void
    {
        $tv1 = $this->sesiSelesai('TV1');
        $main = app(BillingService::class)->mulai(Unit::where('kode', 'TV2')->firstOrFail(), $this->owner, ['mode' => 'durasi', 'durasi_menit' => 60]);
        $tagihanMain = Transaksi::findOrFail($main->transaksi_id);

        $this->actingAs($this->owner);
        Livewire::test(Pembayaran::class)->call('bukaUntuk', $tv1->id)->assertDontSee($tagihanMain->nomor);

        try {
            app(BillingService::class)->bayarGabungan([$tv1->id, $tagihanMain->id], $this->owner, [['metode' => 'tunai', 'jumlah' => 1]]);
            $this->fail('Sesi berjalan seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('masih main', $e->getMessage());
        }

        $tv3 = $this->sesiSelesai('TV3');
        $this->expectException(BillingException::class);
        app(BillingService::class)->bayarGabungan([$tv1->id, $tv3->id], $this->owner, [['metode' => 'saldo', 'jumlah' => 1]]);
    }

    public function test_tunai_dan_qris_dibagi_berurutan(): void
    {
        $tv1 = $this->sesiSelesai('TV1');
        $tv2 = $this->sesiSelesai('TV2');
        $a = $tv1->sisaTagihan();
        $b = $tv2->sisaTagihan();

        // QRIS menutup tagihan pertama + sebagian kedua, sisanya tunai dengan kembalian
        app(BillingService::class)->bayarGabungan([$tv1->id, $tv2->id], $this->owner, [
            ['metode' => 'qris', 'jumlah' => $a + 1000],
            ['metode' => 'tunai', 'jumlah' => $b - 1000, 'diterima' => $b + 4000],
        ]);

        $this->assertSame(['qris' => $a], $tv1->fresh()->pembayaran->pluck('jumlah', 'metode')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['qris' => 1000, 'tunai' => $b - 1000], $tv2->fresh()->pembayaran->pluck('jumlah', 'metode')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(5000, (int) $tv2->fresh()->kembalian);
        $this->assertTrue($tv1->fresh()->isLunas() && $tv2->fresh()->isLunas());
        $this->assertSame(Sesi::STATUS_SELESAI, Sesi::where('transaksi_id', $tv2->id)->value('status'));
    }
}
