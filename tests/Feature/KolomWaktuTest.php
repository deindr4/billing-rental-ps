<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\ShiftService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regresi: kolom waktu penting tidak boleh berubah sendiri saat barisnya diperbarui
 * (MariaDB "ON UPDATE CURRENT_TIMESTAMP" pada kolom TIMESTAMP pertama).
 */
class KolomWaktuTest extends TestCase
{
    use RefreshDatabase;

    public function test_tidak_ada_kolom_ter_reset_otomatis(): void
    {
        $sisa = DB::select("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND extra LIKE '%on update%'");

        $this->assertSame([], $sisa, 'Kolom dengan ON UPDATE CURRENT_TIMESTAMP: '.json_encode($sisa));
    }

    public function test_jam_mulai_sesi_dan_buka_kas_tetap_setelah_diperbarui(): void
    {
        $this->seed(DatabaseSeeder::class);
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $owner = User::where('email', 'owner@billing.test')->firstOrFail();
        app(Tenancy::class)->set($cabang->tenant_id, $cabang->id);

        $shift = app(ShiftService::class)->buka($owner, $cabang, 0);
        $billing = app(BillingService::class);
        $sesi = $billing->mulai(Unit::where('kode', 'TV2')->first(), $owner, ['mode' => 'durasi', 'durasi_menit' => 60, 'pilih_game_menit' => 5]);
        $mulai = $sesi->fresh()->mulai_pada;
        $buka = $shift->fresh()->dibuka_pada;

        $this->travel(10)->minutes();
        $billing->tambahWaktu($sesi->fresh(), $owner, 30);
        $billing->bayar($sesi->transaksi->fresh(), $owner, [['metode' => 'tunai', 'jumlah' => $sesi->transaksi->fresh()->sisaTagihan()]]);

        $this->assertTrue($mulai->equalTo($sesi->fresh()->mulai_pada));
        $this->assertTrue($buka->equalTo($shift->fresh()->dibuka_pada));
    }
}
