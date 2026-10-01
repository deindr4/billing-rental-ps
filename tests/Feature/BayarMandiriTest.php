<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Livewire\Publik\MainMandiri;
use App\Models\Cabang;
use App\Models\PaketHarga;
use App\Models\Pembayaran;
use App\Models\PembayaranOnline;
use App\Models\Pengaturan;
use App\Models\PerangkatTv;
use App\Models\Sesi;
use App\Models\Transaksi;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\ShiftService;
use App\Services\Gateway\BayarMandiriService;
use App\Services\Gateway\PengaturanGateway;
use App\Services\Gateway\SimulasiGateway;
use App\Services\Tv\StatusTvService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class BayarMandiriTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $cabang;

    private User $owner;

    private Unit $unit;

    private BayarMandiriService $layanan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);

        $this->unit = Unit::where('kode', 'TV2')->firstOrFail(); // PS4 Rp8.000/jam, paket 3 jam Rp20.000
        $this->layanan = app(BayarMandiriService::class);

        app(PengaturanGateway::class)->simpan('provider', 'simulasi');
        Pengaturan::simpan('bayar_mandiri.aktif', true, $this->cabang->id);
    }

    private function bukaKas(): void
    {
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
    }

    /** Pelanggan membayar tagihan simulasi */
    private function bayar(PembayaranOnline $p): PembayaranOnline
    {
        SimulasiGateway::bayar($p->referensi);
        Cache::forget('cek-bayar:'.$p->id);

        return $this->layanan->periksa($p->fresh());
    }

    public function test_durasi_dihitung_dari_nominal_dan_paket(): void
    {
        $this->assertSame(90, $this->layanan->hitung($this->unit, 12_000)['menit']);
        $this->assertSame(75, $this->layanan->hitung($this->unit, 10_000)['menit']);

        $paket = $this->layanan->hitung($this->unit, 20_000); // pas harga paket 3 jam
        $this->assertSame(180, $paket['menit']);
        $this->assertSame('Paket 3 Jam PS4', $paket['paket']->nama);
    }

    public function test_hanya_saat_kas_dibuka_dan_minimal_menit(): void
    {
        $this->assertFalse($this->layanan->konteks($this->unit)['bisa']);
        $this->assertSame('Rental sedang tutup.', $this->layanan->konteks($this->unit)['alasan']);

        $this->bukaKas();
        $this->assertSame('mulai', $this->layanan->konteks($this->unit)['jenis']);

        $this->expectException(BillingException::class); // Rp2.000 = 15 menit < minimal 30
        $this->layanan->buatTagihan($this->unit, 2_000);
    }

    public function test_bayar_qris_tv_terbuka_otomatis_dan_lunas(): void
    {
        $this->bukaKas();
        $p = $this->layanan->buatTagihan($this->unit, 12_000);

        $this->assertSame('menunggu', $p->status);
        $this->assertSame(90, $p->menit);
        $this->assertSame('menunggu', $this->layanan->periksa($p)->status);

        $p = $this->bayar($p);

        $this->assertSame('selesai', $p->status);
        $sesi = Sesi::findOrFail($p->sesi_id);
        $this->assertSame(Unit::STATUS_MAIN, $this->unit->fresh()->status);
        $this->assertTrue($sesi->sedangPilihGame());                       // 5 menit pilih game dulu
        $this->assertSame(90, (int) $sesi->mulai_pada->diffInMinutes($sesi->berakhir_pada));

        $trx = Transaksi::findOrFail($p->transaksi_id);
        $this->assertSame(Transaksi::STATUS_LUNAS, $trx->status);
        $this->assertSame(12_000, $trx->total);
        $this->assertSame('qris_gateway', $trx->pembayaran()->first()->metode);

        // Diproses ulang (callback dobel / server lain) tidak membuat sesi baru
        $this->assertSame('selesai', $this->layanan->proses($p)->status);
        $this->assertSame(1, Sesi::count());
    }

    public function test_waktu_habis_isi_ulang_dan_tambah_manual_bayar_tunai(): void
    {
        $this->bukaKas();
        $p = $this->bayar($this->layanan->buatTagihan($this->unit, 8_000)); // 60 menit
        $sesi = Sesi::findOrFail($p->sesi_id);

        // Waktu habis -> TV terkunci, konteks isi ulang
        $this->travelTo($sesi->berakhir_pada->copy()->addMinutes(3));
        $k = $this->layanan->konteks($this->unit->fresh());
        $this->assertSame('isi_ulang', $k['jenis']);

        $isi = $this->bayar($this->layanan->buatTagihan($this->unit->fresh(), 4_000)); // 30 menit
        $sesi->refresh();

        $this->assertSame('selesai', $isi->status);
        $this->assertSame($sesi->id, $isi->sesi_id);
        $this->assertTrue($sesi->berakhir_pada->between(now()->addMinutes(29), now()->addMinutes(31))); // dari sekarang
        $this->assertSame(Transaksi::STATUS_LUNAS, $sesi->transaksi->fresh()->status);

        // Kasir tambah 1 jam manual, bayar tunai: sisa tagihan = hanya tambahan
        app(BillingService::class)->tambahWaktu($sesi->fresh(), $this->owner, 60);
        $trx = $sesi->transaksi->fresh();
        $this->assertSame(12_000, $trx->totalDibayar());
        $this->assertSame(8_000, $trx->sisaTagihan());

        app(BillingService::class)->bayar($trx, $this->owner, [['metode' => 'tunai', 'jumlah' => 8_000]]);
        $this->assertSame(Transaksi::STATUS_LUNAS, $trx->fresh()->status);
    }

    public function test_unit_keburu_dipakai_perlu_tindakan_lalu_diterapkan_kasir(): void
    {
        $this->bukaKas();
        $p = $this->layanan->buatTagihan($this->unit, 16_000);

        // Sebelum pelanggan bayar, kasir memakai unit itu
        app(BillingService::class)->mulai($this->unit, $this->owner, ['mode' => 'durasi', 'durasi_menit' => 60]);

        $p = $this->bayar($p);
        $this->assertSame('perlu_tindakan', $p->status);
        $this->assertStringContainsString('sudah dipakai', $p->catatan);

        $lain = Unit::where('kode', 'TV3')->firstOrFail();
        $p = $this->layanan->terapkanKeUnit($p, $lain);
        $this->assertSame('selesai', $p->status);
        $this->assertSame(Unit::STATUS_MAIN, $lain->fresh()->status);
    }

    public function test_sesi_lunas_yang_habis_diselesaikan_otomatis(): void
    {
        $this->bukaKas();
        $p = $this->bayar($this->layanan->buatTagihan($this->unit, 4_000)); // 30 menit
        $sesi = Sesi::findOrFail($p->sesi_id);

        $this->travelTo($sesi->berakhir_pada->copy()->addMinutes(11)); // lewat 10 menit bawaan
        $this->assertSame(1, $this->layanan->selesaikanYangHabis());
        $this->assertSame(Unit::STATUS_KOSONG, $this->unit->fresh()->status);
    }

    public function test_status_tv_berisi_qr_dan_tagihan(): void
    {
        $this->bukaKas();
        $tv = new PerangkatTv;
        $tv->forceFill([
            'tenant_id' => $this->cabang->tenant_id, 'cabang_id' => $this->cabang->id, 'unit_id' => $this->unit->id,
            'android_id' => 'uji-bm', 'status' => PerangkatTv::STATUS_AKTIF, 'token_hash' => hash('sha256', 'x'),
        ])->save();

        $s = app(StatusTvService::class)->untuk($tv);
        $this->assertSame('mulai', $s['bayar_mandiri']['jenis']);
        $this->assertStringContainsString('/main/', $s['bayar_mandiri']['url']);
        $this->assertNull($s['bayar_mandiri']['tagihan']);

        $p = $this->layanan->buatTagihan($this->unit, 12_000);
        $s = app(StatusTvService::class)->untuk($tv);
        $this->assertSame(12_000, $s['bayar_mandiri']['tagihan']['nominal']);
        $this->assertSame('1 jam 30 menit', $s['bayar_mandiri']['tagihan']['label']);
        $this->assertNotEmpty($s['bayar_mandiri']['tagihan']['qris']);

        // Dibayar -> status TV berikutnya langsung "main"
        SimulasiGateway::bayar($p->referensi);
        Cache::forget('cek-bayar:'.$p->id);
        $s = app(StatusTvService::class)->untuk($tv->fresh());
        $this->assertContains($s['layar'], ['main', 'kunci']);
        $this->assertNull($s['bayar_mandiri']);
        $this->assertNotNull($s['sesi']);
    }

    public function test_halaman_hp_pelanggan(): void
    {
        $this->bukaKas();
        $url = $this->layanan->urlHp($this->unit);

        $this->get($url)->assertOk()->assertSee('Mau main berapa lama?');

        Livewire::test(MainMandiri::class, ['token' => $this->unit->fresh()->token_bayar])
            ->set('nominal', 12_000)
            ->assertSee('1 jam 30 menit')
            ->call('tampilkan')
            ->assertHasNoErrors()
            ->assertSee('Scan QRIS di layar TV');

        $this->assertSame(1, PembayaranOnline::count());
        $this->get('/main/salah')->assertNotFound();
    }

    public function test_tripay_buat_qris_dan_callback_bertanda_tangan(): void
    {
        $g = app(PengaturanGateway::class);
        $g->simpan('provider', 'tripay');
        $g->simpan('tripay_merchant_code', 'T0001');
        $g->simpan('tripay_api_key', 'kunci-api');
        $g->simpan('tripay_private_key', 'kunci-rahasia');
        $this->bukaKas();

        Http::fake(['tripay.co.id/api-sandbox/transaction/create' => Http::response(['success' => true, 'data' => [
            'reference' => 'DEV-T123', 'qr_string' => '00020101021226...', 'expired_time' => now()->addMinutes(10)->timestamp, 'total_fee' => 834,
        ]])]);

        $p = $this->layanan->buatTagihan($this->unit, 12_000);

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer kunci-api')
            && $r['signature'] === hash_hmac('sha256', 'T0001'.$p->merchant_ref.'12000', 'kunci-rahasia')
            && $r['method'] === 'QRIS');
        $this->assertSame('DEV-T123', $p->referensi);
        $this->assertSame(834, $p->biaya);

        // Kunci API tersimpan terenkripsi
        $this->assertNotSame('kunci-api', Pengaturan::where('kunci', 'gateway.tripay_api_key')->first()->nilai['v']);

        $body = json_encode(['reference' => 'DEV-T123', 'merchant_ref' => $p->merchant_ref, 'status' => 'PAID', 'fee_merchant' => 834]);

        // Tanda tangan salah ditolak
        $this->call('POST', '/api/gateway/tripay/callback', [], [], [], [
            'HTTP_X-Callback-Signature' => 'salah', 'HTTP_X-Callback-Event' => 'payment_status', 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);

        $this->call('POST', '/api/gateway/tripay/callback', [], [], [], [
            'HTTP_X-Callback-Signature' => hash_hmac('sha256', $body, 'kunci-rahasia'), 'HTTP_X-Callback-Event' => 'payment_status', 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk()->assertJson(['success' => true]);

        $this->assertSame('selesai', $p->fresh()->status);
        $this->assertSame(Unit::STATUS_MAIN, $this->unit->fresh()->status);
    }

    public function test_notifikasi_ganda_dengan_data_basi_tidak_diproses_dua_kali(): void
    {
        $this->bukaKas();
        $basi = $this->layanan->buatTagihan($this->unit, 12_000);   // salinan lama, status "menunggu"
        $this->bayar(PembayaranOnline::findOrFail($basi->id));       // pengecekan berkala memproses lebih dulu

        // Callback gateway datang belakangan dengan objek basi
        $this->layanan->perbarui($basi, 'dibayar', 0);
        $this->layanan->perbarui($basi, 'kedaluwarsa');

        $p = $basi->fresh();
        $this->assertSame('selesai', $p->status);
        $this->assertSame(1, Sesi::where('unit_id', $this->unit->id)->count());
        $this->assertSame(1, Pembayaran::where('metode', 'qris_gateway')->count());
        $this->assertSame(90, (int) Sesi::findOrFail($p->sesi_id)->durasi_menit);
    }

    public function test_paket_dipakai_saat_nominal_pas(): void
    {
        $this->bukaKas();
        $p = $this->bayar($this->layanan->buatTagihan($this->unit, 20_000));

        $sesi = Sesi::findOrFail($p->sesi_id);
        $this->assertSame(PaketHarga::where('nama', 'Paket 3 Jam PS4')->value('id'), $sesi->paket_harga_id);
        $this->assertSame(180, (int) $sesi->durasi_menit);
    }
}
