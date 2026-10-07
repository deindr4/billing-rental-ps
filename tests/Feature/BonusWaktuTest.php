<?php

namespace Tests\Feature;

use App\Livewire\Operator\DetailTransaksi;
use App\Livewire\Operator\KelolaSesi;
use App\Models\AuditLog;
use App\Models\Cabang;
use App\Models\Transaksi;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\KalkulatorOpenBilling;
use App\Services\Billing\ShiftService;
use App\Services\Struk\StrukService;
use App\Support\HakAkses;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Bonus waktu (kompensasi PS restart/hang) & struk sementara open billing */
class BonusWaktuTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Cabang $cabang;

    private BillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->owner->forceFill(['pin' => Hash::make('1234')])->save();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->cabang->tenant_id);
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
        $this->billing = app(BillingService::class);
    }

    public function test_bonus_open_billing_tidak_ditagih(): void
    {
        $unit = Unit::where('kode', 'TV2')->firstOrFail(); // Rp8.000/jam
        $sesi = $this->billing->mulai($unit, $this->owner, ['mode' => 'open']);

        $this->travel(90)->minutes();
        $this->billing->bonusWaktu($sesi, $this->owner, 30, 'PS restart sendiri');

        $sesi->refresh();
        $this->assertSame(1800, $sesi->bonus_detik);
        $this->assertEqualsWithDelta(60 * 60, $sesi->durasiBerjalanDetik(), 5); // 90 menit - 30 bonus

        $trx = Transaksi::findOrFail($sesi->transaksi_id);
        $this->assertTrue($trx->items->contains(fn ($i) => $i->nama === 'Bonus waktu 30 menit' && $i->subtotal === 0 && str_contains($i->catatan, 'PS restart')));
        $this->assertTrue(AuditLog::where('aksi', 'waktu_gratis')->exists());

        // Struk sementara: perkiraan sewa 60 menit
        $baris = collect(app(StrukService::class)->baris(app(StrukService::class)->data($trx->fresh()), 32))->pluck('t')->implode("\n");
        $this->assertStringContainsString('Sewa berjalan (perkiraan)', $baris);
        $this->assertStringContainsString('TOTAL SEMENTARA', $baris);
        $this->assertStringEndsWith("Copyright (c) deindr4\ngithub.com/deindr4", $baris);

        // Perkiraan = kalkulator open billing untuk durasi setelah bonus (± 60 menit, bukan 90)
        $perkiraan = $this->billing->estimasiSewaOpen($sesi->fresh());
        $tanpaBonus = app(KalkulatorOpenBilling::class)->hitung((int) $sesi->tarif_per_jam, 90 * 60, 15, 5, 60, 0)['biaya'];
        $this->assertLessThan($tanpaBonus, $perkiraan);
        $this->assertStringContainsString(number_format($perkiraan, 0, ',', '.'), $baris);

        // Ditagih sesuai durasi setelah bonus saat selesai
        $this->billing->selesai($sesi, $this->owner);
        $this->assertSame($perkiraan, Transaksi::findOrFail($sesi->transaksi_id)->total);
    }

    public function test_bonus_paket_mengundur_waktu_selesai(): void
    {
        $unit = Unit::where('kode', 'TV2')->firstOrFail();
        $sesi = $this->billing->mulai($unit, $this->owner, ['mode' => 'durasi', 'durasi_menit' => 60]);
        $akhir = $sesi->berakhir_pada->copy();
        $total = Transaksi::findOrFail($sesi->transaksi_id)->total;

        $this->billing->bonusWaktu($sesi, $this->owner, 15, 'PS hang / freeze');

        $sesi->refresh();
        $this->assertTrue($sesi->berakhir_pada->equalTo($akhir->addMinutes(15)));
        $this->assertSame($total, Transaksi::findOrFail($sesi->transaksi_id)->total);
    }

    public function test_panel_kasir_bonus_owner_langsung_kasir_butuh_pin(): void
    {
        $unit = Unit::where('kode', 'TV2')->firstOrFail();
        $sesi = $this->billing->mulai($unit, $this->owner, ['mode' => 'open']);

        // Owner: tanpa PIN
        Livewire::actingAs($this->owner)->test(KelolaSesi::class)
            ->call('bukaUntuk', $unit->id)->call('kePanel', 'bonus')
            ->assertSee('Bonus berapa menit?')->assertDontSee('PIN supervisor / owner')
            ->set('bonusMenit', 12)->set('bonusAlasan', 'Stik error')
            ->call('bonusWaktu')->assertHasNoErrors();
        $this->assertSame(720, $sesi->fresh()->bonus_detik);

        // Kasir: wajib PIN orang berizin
        HakAkses::siapkanPermission();
        HakAkses::siapkanTenant($this->owner->tenant);
        $kasir = User::create(['tenant_id' => $this->owner->tenant_id, 'name' => 'Kasir', 'username' => 'kasirb', 'email' => 'kasirb@billing.test', 'password' => 'password']);
        $kasir->assignRole('Kasir');
        $kasir->cabang()->attach($this->cabang->id);
        app(ShiftService::class)->buka($kasir, $this->cabang, 0); // shift berlaku per orang

        $panel = Livewire::actingAs($kasir)->test(KelolaSesi::class)
            ->call('bukaUntuk', $unit->id)->call('kePanel', 'bonus')
            ->assertSee('PIN supervisor / owner')
            ->set('bonusMenit', 5)->set('bonusAlasan', 'PS hang / freeze')
            ->set('bonusPin', '9999')->call('bonusWaktu')->assertHasErrors('bonusPin');
        $this->assertSame(720, $sesi->fresh()->bonus_detik);

        $panel->set('bonusPin', '1234')->call('bonusWaktu')->assertHasNoErrors();
        $this->assertSame(1020, $sesi->fresh()->bonus_detik);
        $this->assertStringContainsString('disetujui', Transaksi::findOrFail($sesi->transaksi_id)->items->last()->catatan);
    }

    public function test_struk_open_bill_berjalan_bisa_dicetak_tanpa_item(): void
    {
        $unit = Unit::where('kode', 'TV2')->firstOrFail();
        $sesi = $this->billing->mulai($unit, $this->owner, ['mode' => 'open']);

        $this->actingAs($this->owner);
        Livewire::test(DetailTransaksi::class)
            ->dispatch('buka-detail-transaksi', transaksiId: $sesi->transaksi_id)
            ->assertSee('Cetak struk');
    }
}
