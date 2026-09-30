<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Filament\Pages\PengaturanMember as PengaturanMemberPage;
use App\Livewire\Operator\Member as MemberPage;
use App\Livewire\Operator\MulaiSesi;
use App\Livewire\Operator\Pembayaran;
use App\Models\Cabang;
use App\Models\KasMutasi;
use App\Models\Member;
use App\Models\MemberMutasi;
use App\Models\Pengaturan;
use App\Models\Transaksi;
use App\Models\TransaksiDiskon;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\ShiftService;
use App\Services\LaporanService;
use App\Services\Member\MemberService;
use App\Services\Struk\StrukService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class MemberTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Cabang $cabang;

    private Unit $unit;

    private MemberService $service;

    private BillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);

        $this->unit = Unit::where('kode', 'TV2')->firstOrFail(); // PS4, Rp8.000/jam
        $this->service = app(MemberService::class);
        $this->billing = app(BillingService::class);

        app(ShiftService::class)->buka($this->owner, $this->cabang, 100_000);
    }

    private function member(string $telepon = '0812-3456-7890'): Member
    {
        return $this->service->daftar($this->cabang->tenant_id, $this->cabang->id, ['nama' => 'Budi Santoso', 'telepon' => $telepon]);
    }

    /** Sewa 2 jam di TV2 = Rp16.000 */
    private function sewa(?Member $member, int $menit = 120): Transaksi
    {
        return $this->billing->mulai($this->unit->refresh(), $this->owner, [
            'mode' => 'durasi',
            'durasi_menit' => $menit,
            'member_id' => $member?->id,
        ])->transaksi->refresh();
    }

    public function test_daftar_member_kode_berurutan_dan_telepon_unik(): void
    {
        $a = $this->member('0812-3456-7890');
        $b = $this->member('+62 813 1111 2222');

        $this->assertSame('M00001', $a->kode);
        $this->assertSame('M00002', $b->kode);
        $this->assertSame('081234567890', $a->telepon);
        $this->assertSame('081311112222', $b->telepon);
        $this->assertSame('Reguler', $a->tier);

        $this->expectException(BillingException::class);
        $this->member('6281234567890');
    }

    public function test_cari_member_dengan_format_nomor_berbeda(): void
    {
        $m = $this->member();

        $this->assertTrue(Member::cari('6281234567890')->whereKey($m->id)->exists());
        $this->assertTrue(Member::cari('budi')->whereKey($m->id)->exists());
        $this->assertTrue(Member::cari('M00001')->whereKey($m->id)->exists());
    }

    public function test_top_up_tunai_masuk_kas_bukan_omzet_dan_dapat_bonus(): void
    {
        Pengaturan::simpan('member.bonus_topup', [['min' => 100_000, 'bonus' => 10_000], ['min' => 50_000, 'bonus' => 3_000]]);
        $m = $this->member();

        $trx = $this->service->topUp($m, $this->owner, $this->cabang, 100_000, 'tunai', 150_000);

        $this->assertSame(Transaksi::JENIS_TOP_UP, $trx->jenis);
        $this->assertSame(Transaksi::STATUS_LUNAS, $trx->status);
        $this->assertSame(50_000, $trx->kembalian);
        $this->assertSame(110_000, $m->refresh()->saldo);
        $this->assertSame(100_000, (int) KasMutasi::where('jenis', 'topup')->sum('jumlah'));

        $r = app(LaporanService::class)->ringkasan(now()->startOfDay(), now()->endOfDay());
        $this->assertSame(0, $r['omzet_bersih']);
        $this->assertSame(100_000, $r['topup']);
    }

    public function test_diskon_tier_hanya_untuk_sewa_dan_ikut_berubah(): void
    {
        $m = $this->member();
        DB::table('members')->where('id', $m->id)->update(['tier' => 'Gold', 'total_belanja' => 2_000_000]);

        $trx = $this->sewa($m);

        $this->assertSame(16_000, $trx->subtotal);
        $this->assertSame(1_600, $trx->total_diskon); // Gold 10%
        $this->assertSame(14_400, $trx->total);

        // Lepas member -> diskon tier jadi 0 (baris diskon tidak dihapus)
        $this->service->pasangMember($trx, null);
        $trx->refresh();
        $this->assertSame(0, $trx->total_diskon);
        $this->assertSame(1, TransaksiDiskon::where('transaksi_id', $trx->id)->where('jenis', 'member')->count());

        // Pasang lagi
        $this->service->pasangMember($trx, $m->refresh());
        $this->assertSame(1_600, $trx->refresh()->total_diskon);
    }

    public function test_bayar_pakai_saldo_dapat_poin_stamp_dan_tier_naik(): void
    {
        $m = $this->member();
        $this->service->topUp($m, $this->owner, $this->cabang, 600_000, 'qris');

        $trx = $this->sewa($m);
        $this->billing->bayar($trx, $this->owner, [['metode' => 'saldo', 'jumlah' => 16_000]]);

        $m->refresh();
        $this->assertSame(584_000, $m->saldo);
        $this->assertSame(16, $m->poin);           // Rp1.000 = 1 poin
        $this->assertSame(1, $m->stamp);
        $this->assertSame(16_000, $m->total_belanja);
        $this->assertSame(1, $m->jumlah_kunjungan);
        $this->assertSame('Reguler', $m->tier);

        // Saldo kurang -> ditolak, tidak ada yang berubah
        DB::table('members')->where('id', $m->id)->update(['saldo' => 1_000]);
        $trx3 = $this->billing->mulai(Unit::where('kode', 'TV3')->first(), $this->owner, ['mode' => 'durasi', 'durasi_menit' => 60, 'member_id' => $m->id])->transaksi;

        try {
            $this->billing->bayar($trx3, $this->owner, [['metode' => 'saldo', 'jumlah' => 8_000]]);
            $this->fail('Seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('Saldo member tidak cukup', $e->getMessage());
        }

        $this->assertSame(1_000, $m->refresh()->saldo);
        $this->assertSame(Transaksi::STATUS_BELUM_BAYAR, $trx3->refresh()->status);
    }

    public function test_saldo_tidak_bisa_untuk_non_member(): void
    {
        $trx = $this->sewa(null);

        $this->expectException(BillingException::class);
        $this->billing->bayar($trx, $this->owner, [['metode' => 'saldo', 'jumlah' => 16_000]]);
    }

    public function test_tukar_poin_dan_batal_tukar(): void
    {
        $m = $this->member();
        DB::table('members')->where('id', $m->id)->update(['poin' => 100]);

        $trx = $this->sewa($m);
        $nilai = $this->service->tukarPoin($trx, 60, $this->owner);

        $this->assertSame(6_000, $nilai);
        $this->assertSame(40, $m->refresh()->poin);
        $this->assertSame(10_000, $trx->refresh()->total);

        $diskon = TransaksiDiskon::where('transaksi_id', $trx->id)->where('jenis', 'poin')->firstOrFail();
        $this->service->batalTukar($diskon, $this->owner);

        $this->assertSame(100, $m->refresh()->poin);
        $this->assertSame(16_000, $trx->refresh()->total);
    }

    public function test_tukar_poin_dibatasi_sisa_tagihan(): void
    {
        $m = $this->member();
        DB::table('members')->where('id', $m->id)->update(['poin' => 1_000]);

        $trx = $this->sewa($m); // Rp16.000 = maks 160 poin
        $this->service->tukarPoin($trx, 1_000, $this->owner);

        $this->assertSame(840, $m->refresh()->poin);
        $this->assertSame(0, $trx->refresh()->total);
    }

    public function test_tukar_stamp_gratis_satu_jam(): void
    {
        $m = $this->member();
        DB::table('members')->where('id', $m->id)->update(['stamp' => 10]);

        $trx = $this->sewa($m);
        $nilai = $this->service->tukarStamp($trx, $this->owner);

        $this->assertSame(8_000, $nilai); // 60 menit x Rp8.000/jam
        $this->assertSame(0, $m->refresh()->stamp);
        $this->assertSame(8_000, $trx->refresh()->total);

        $this->expectException(BillingException::class);
        $this->service->tukarStamp($trx, $this->owner);
    }

    public function test_batal_transaksi_kembalikan_saldo_poin_dan_tarik_manfaat(): void
    {
        $m = $this->member();
        $this->service->topUp($m, $this->owner, $this->cabang, 50_000, 'transfer');
        DB::table('members')->where('id', $m->id)->update(['poin' => 50]);

        $trx = $this->sewa($m);
        $this->service->tukarPoin($trx, 50, $this->owner);                 // -Rp5.000
        $this->billing->bayar($trx, $this->owner, [['metode' => 'saldo', 'jumlah' => 11_000]]);

        $m->refresh();
        $this->assertSame(39_000, $m->saldo);
        $this->assertSame(11, $m->poin);  // 0 + 11 poin dari Rp11.000
        $this->assertSame(1, $m->stamp);

        $this->billing->batalkan($trx, $this->owner, 'Salah input unit');

        $m->refresh();
        $this->assertSame(50_000, $m->saldo);
        $this->assertSame(50, $m->poin);
        $this->assertSame(0, $m->stamp);
        $this->assertSame(0, $m->total_belanja);
        $this->assertSame(0, $m->jumlah_kunjungan);

        // Idempoten: sinkron ulang tidak mengubah apa pun
        $jumlahMutasi = MemberMutasi::count();
        $this->service->sinkronManfaat($trx->refresh());
        $this->assertSame($jumlahMutasi, MemberMutasi::count());
    }

    public function test_batal_top_up_ditolak_jika_saldo_sudah_dipakai(): void
    {
        $m = $this->member();
        $topup = $this->service->topUp($m, $this->owner, $this->cabang, 20_000, 'tunai');

        $trx = $this->sewa($m);
        $this->billing->bayar($trx, $this->owner, [['metode' => 'saldo', 'jumlah' => 16_000]]);

        try {
            $this->billing->batalkan($topup, $this->owner, 'Pelanggan minta uang kembali');
            $this->fail('Seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('sudah terpakai', $e->getMessage());
        }

        $this->assertSame(Transaksi::STATUS_LUNAS, $topup->refresh()->status);
    }

    public function test_batal_top_up_tarik_saldo_dan_uang_keluar_kas(): void
    {
        $m = $this->member();
        $topup = $this->service->topUp($m, $this->owner, $this->cabang, 20_000, 'tunai');

        $this->billing->batalkan($topup, $this->owner, 'Salah pilih member');

        $this->assertSame(0, $m->refresh()->saldo);
        $this->assertSame(0, (int) KasMutasi::whereIn('jenis', ['topup', 'pembatalan'])->sum('jumlah'));
    }

    public function test_tier_naik_setelah_belanja_mencapai_batas(): void
    {
        Pengaturan::simpan('member.tier', [
            ['nama' => 'Reguler', 'min_belanja' => 0, 'diskon_persen' => 0],
            ['nama' => 'Silver', 'min_belanja' => 15_000, 'diskon_persen' => 5],
        ]);
        $m = $this->member();

        $trx = $this->sewa($m);
        $this->billing->bayar($trx, $this->owner, [['metode' => 'tunai', 'jumlah' => 16_000]]);

        $this->assertSame('Silver', $m->refresh()->tier);
    }

    public function test_koreksi_saldo_tidak_boleh_minus(): void
    {
        $m = $this->member();
        $this->service->koreksi($m, 'poin', 25, 'Kompensasi TV rusak', $this->owner, $this->owner);
        $this->assertSame(25, $m->refresh()->poin);

        $this->expectException(BillingException::class);
        $this->service->koreksi($m, 'saldo', -1, 'Tes koreksi minus', $this->owner, $this->owner);
    }

    public function test_halaman_member_daftar_top_up_dan_koreksi(): void
    {
        $this->owner->forceFill(['pin' => Hash::make('1234')])->save();
        $this->actingAs($this->owner);

        $lw = Livewire::test(MemberPage::class)
            ->call('formBaru')
            ->set('form.nama', 'Andi Saputra')
            ->set('form.telepon', '081288921102')
            ->call('simpanForm')
            ->assertHasNoErrors()
            ->assertSee('Andi Saputra');

        $m = Member::where('telepon', '081288921102')->firstOrFail();
        $lw->assertSet('pilihId', $m->id)
            ->call('bukaTopUp')
            ->call('setTopUp', 50_000)
            ->call('simpanTopUp')
            ->assertHasNoErrors()
            ->assertDispatched('ui:bayar-berhasil');

        $this->assertSame(50_000, $m->refresh()->saldo);

        $lw->call('bukaKoreksi')
            ->set('koreksiAkun', 'poin')
            ->set('koreksiJumlah', 20)
            ->set('koreksiAlasan', 'Kompensasi TV mati')
            ->call('simpanKoreksi', ['pin' => '1234'])
            ->assertHasNoErrors();

        $this->assertSame(20, $m->refresh()->poin);
        $this->get(route('member'))->assertOk()->assertSee('Member');
    }

    public function test_mulai_sesi_dan_pembayaran_dengan_member(): void
    {
        $this->actingAs($this->owner);
        $m = $this->member();
        $this->service->topUp($m, $this->owner, $this->cabang, 100_000, 'qris');
        DB::table('members')->where('id', $m->id)->update(['poin' => 100]);

        Livewire::test(MulaiSesi::class)
            ->call('bukaUntuk', $this->unit->id)
            ->set('jenisPelanggan', 'member')
            ->set('cariMember', '0812345')
            ->assertSee('Budi Santoso')
            ->call('pilihMember', $m->id)
            ->set('mode', 'durasi')
            ->set('durasiMenit', 120)
            ->call('simpan')
            ->assertHasNoErrors();

        $trx = Transaksi::where('member_id', $m->id)->where('jenis', 'billing')->firstOrFail();
        $this->assertSame('Budi Santoso', $trx->pelanggan_nama);

        Livewire::test(Pembayaran::class)
            ->call('bukaUntuk', $trx->id)
            ->assertSee('Saldo')
            ->set('poinDitukar', 50)
            ->call('tukarPoin')
            ->assertHasNoErrors()
            ->assertSet('baris.0.jumlah', 11_000)
            ->call('pakaiSaldo')
            ->assertSet('baris.0.metode', 'saldo')
            ->call('simpan')
            ->assertHasNoErrors()
            ->assertDispatched('ui:bayar-berhasil');

        $m->refresh();
        $this->assertSame(89_000, $m->saldo);
        $this->assertSame(50 + 11, $m->poin);
        $this->assertSame(Transaksi::STATUS_LUNAS, $trx->refresh()->status);
    }

    public function test_tagihan_tertutup_poin_bisa_dilunasi_rp0(): void
    {
        $this->actingAs($this->owner);
        $m = $this->member();
        DB::table('members')->where('id', $m->id)->update(['poin' => 500]);

        $trx = $this->sewa($m);

        Livewire::test(Pembayaran::class)
            ->call('bukaUntuk', $trx->id)
            ->set('poinDitukar', 500)
            ->call('tukarPoin')
            ->assertSet('baris', [])
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(Transaksi::STATUS_LUNAS, $trx->refresh()->status);
        $this->assertSame(340, $m->refresh()->poin); // 160 poin terpakai, dapat 0
        $this->assertSame(1, $m->stamp);
    }

    public function test_admin_pengaturan_member_menyesuaikan_tier(): void
    {
        $this->actingAs($this->owner);
        $m = $this->member();
        DB::table('members')->where('id', $m->id)->update(['total_belanja' => 300_000]);

        Livewire::test(PengaturanMemberPage::class)
            ->set('data.tier', [
                ['nama' => 'Reguler', 'min_belanja' => 0, 'diskon_persen' => 0],
                ['nama' => 'Silver', 'min_belanja' => 200_000, 'diskon_persen' => 5],
            ])
            ->set('data.bonus_topup', [['min' => 100_000, 'bonus' => 10_000]])
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame('Silver', $m->refresh()->tier);
        $this->assertSame(10_000, $this->service->bonusUntuk(150_000));
    }

    public function test_struk_menampilkan_info_member(): void
    {
        $this->actingAs($this->owner);
        $m = $this->member();
        $this->service->topUp($m, $this->owner, $this->cabang, 50_000, 'qris');

        $trx = $this->sewa($m);
        $this->billing->bayar($trx, $this->owner, [['metode' => 'saldo', 'jumlah' => 16_000]]);

        $teks = app(StrukService::class)->teksWa($trx->refresh());
        $this->assertStringContainsString('M00001', $teks);
        $this->assertStringContainsString('Poin didapat', $teks);
        $this->assertStringContainsString('Rp 34.000', $teks);

        $this->get(route('struk.nota', $trx->id))->assertOk()->assertSee('Budi Santoso');
    }

    public function test_riwayat_member_tidak_bisa_dihapus(): void
    {
        $m = $this->member();
        $this->service->koreksi($m, 'poin', 5, 'Bonus pembukaan', $this->owner, $this->owner);

        $this->expectException(QueryException::class);
        DB::table('member_mutasi')->delete();
    }
}
