<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Livewire\Operator\BukaShift;
use App\Livewire\Operator\SerahTerima;
use App\Livewire\Operator\TutupKas;
use App\Models\AuditLog;
use App\Models\Cabang;
use App\Models\Shift;
use App\Models\Transaksi;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\ShiftService;
use App\Support\HakAkses;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Satu laci per cabang + serah terima shift antar kasir (modal tetap ditinggal, sisanya disetor) */
class SerahTerimaTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Cabang $cabang;

    private ShiftService $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->cabang->tenant_id);
        $this->shift = app(ShiftService::class);
    }

    private function kasir(string $nama, string $pin): User
    {
        HakAkses::siapkanPermission();
        HakAkses::siapkanTenant($this->cabang->tenant);
        $u = User::create(['tenant_id' => $this->cabang->tenant_id, 'name' => $nama, 'username' => strtolower($nama),
            'email' => strtolower($nama).'@billing.test', 'password' => 'password']);
        $u->forceFill(['pin' => Hash::make($pin)])->save();
        $u->assignRole('Kasir');
        $u->cabang()->attach($this->cabang->id);

        return $u->refresh();
    }

    /** Shift A berisi penjualan tunai Rp8.000 (kas awal Rp200.000 → seharusnya Rp208.000) */
    private function shiftDenganPenjualan(User $a): Shift
    {
        $s = $this->shift->buka($a, $this->cabang, 200_000);
        $billing = app(BillingService::class);
        $sesi = $billing->mulai(Unit::where('kode', 'TV2')->first(), $a, ['mode' => 'durasi', 'durasi_menit' => 60]);
        $billing->bayar(Transaksi::find($sesi->transaksi_id), $a, [['metode' => 'tunai', 'jumlah' => 8_000]]);
        // Satu sesi lagi masih main saat serah terima (diteruskan)
        $billing->mulai(Unit::where('kode', 'TV3')->first(), $a, ['mode' => 'open']);

        return $s;
    }

    public function test_satu_laci_per_cabang_owner_boleh_membantu(): void
    {
        $andi = $this->kasir('Andi', '1111');
        $budi = $this->kasir('Budi', '2222');
        $s = $this->shift->buka($andi, $this->cabang, 100_000);

        // Owner (shift.bantu) bertransaksi di laci Andi; Budi tidak bisa membuka laci kedua
        $this->assertSame($s->id, $this->shift->aktif($this->owner, $this->cabang->id)?->id);
        $this->assertNull($this->shift->aktif($budi, $this->cabang->id));

        try {
            $this->shift->buka($budi, $this->cabang, 0);
            $this->fail('Laci kedua seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('dipegang Andi', $e->getMessage());
        }

        $this->actingAs($budi)->get('/')->assertRedirect(route('shift.buka'));
        Livewire::actingAs($budi)->test(BukaShift::class)->assertSee('Laci sedang dipegang')->assertSee('Andi');
    }

    public function test_serah_terima_modal_ditinggal_sisanya_disetor(): void
    {
        $andi = $this->kasir('Andi', '1111');
        $budi = $this->kasir('Budi', '2222');
        $lama = $this->shiftDenganPenjualan($andi);

        // PIN salah: tidak ada yang berubah
        try {
            $this->shift->serahTerima($lama, $andi, $budi, '9999', 208_000, 200_000);
            $this->fail('PIN salah seharusnya ditolak');
        } catch (BillingException) {
        }
        $this->assertTrue($lama->fresh()->isBuka());

        ['lama' => $lama, 'baru' => $baru] = $this->shift->serahTerima($lama, $andi, $budi, '2222', 208_000, 200_000);

        $this->assertSame([0, 200_000, 8_000, $budi->id], [$lama->selisih, $lama->kas_ditinggal, $lama->setoran, $lama->diserahkan_ke]);
        $this->assertSame([$budi->id, 200_000, $lama->id], [$baru->user_id, $baru->kas_awal, $baru->shift_sebelum_id]);
        $this->assertSame($baru->id, $lama->shift_berikut_id);
        // Diteruskan ke Budi: TV2 (dibayar di muka, masih main) & TV3 (open billing, belum dibayar)
        $main = collect($lama->serah_terima['sesi_main'])->keyBy('unit');
        $this->assertSame([0, 0], [$main['TV2']['sisa'], $main['TV3']['tagihan']]);

        // Laci kini milik Budi; Andi tidak lagi memegang
        $this->assertSame($baru->id, $this->shift->aktif($budi, $this->cabang->id)?->id);
        $this->assertNull($this->shift->aktif($andi, $this->cabang->id));
        $this->assertSame(1, AuditLog::where('aksi', 'serah_terima_shift')->count());
    }

    public function test_hitungan_penerima_berbeda_tercatat_sebagai_selisih(): void
    {
        $andi = $this->kasir('Andi', '1111');
        $budi = $this->kasir('Budi', '2222');
        $lama = $this->shiftDenganPenjualan($andi);

        try {
            $this->shift->serahTerima($lama, $andi, $budi, '2222', 208_000, 200_000, 190_000);
            $this->fail('Selisih tanpa keterangan seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('keterangan', $e->getMessage());
        }

        ['baru' => $baru] = $this->shift->serahTerima($lama, $andi, $budi, '2222', 208_000, 200_000, 190_000, 'Uang 10rb terselip');

        $this->assertSame([190_000, -10_000], [$baru->kas_awal, $baru->selisih_terima]);
        $this->assertTrue(AuditLog::where('aksi', 'selisih_serah_terima')->where('anomali', true)->exists());
    }

    public function test_alur_halaman_serah_terima_laporan_dan_tutup_kas(): void
    {
        $andi = $this->kasir('Andi', '1111');
        $budi = $this->kasir('Budi', '2222');
        $this->shiftDenganPenjualan($andi);

        $hasil = Livewire::actingAs($andi)->test(SerahTerima::class)
            ->assertSee('Serah Terima Shift')->assertSee('TV3')->assertSee('Budi')
            ->assertSet('ditinggal', ShiftService::MODAL_TETAP_DEFAULT)
            ->set('kasFisik', 208_000)
            ->set('penerimaId', $budi->id)
            ->set('pin', '2222')
            ->call('serahkan')
            ->assertHasNoErrors();

        $lama = Shift::where('user_id', $andi->id)->firstOrFail();
        $hasil->assertRedirect(route('shift.laporan', ['id' => $lama->id]));

        // Laporan: setoran Rp8.000, penerima Budi, Andi ditawari keluar
        $this->actingAs($andi)->get(route('shift.laporan', ['id' => $lama->id]))->assertOk()
            ->assertSee('Diserahkan ke Budi')->assertSee('Keluar & ganti kasir')->assertSee('Rp 8.000');

        // Akhir hari: Budi tutup kas, tinggal Rp150.000 untuk besok
        Livewire::actingAs($budi)->test(TutupKas::class)
            ->set('kasFisik', 200_000)->set('ditinggal', 150_000)->call('tutup')->assertHasNoErrors();
        $this->assertSame([150_000, 50_000], [Shift::where('user_id', $budi->id)->value('kas_ditinggal'), Shift::where('user_id', $budi->id)->value('setoran')]);

        // Besok: saran kas awal = yang ditinggal
        Livewire::actingAs($andi)->test(BukaShift::class)->assertSet('kasAkhirSebelumnya', 150_000);
    }
}
