<?php

namespace Tests\Feature;

use App\Filament\Pages\PengaturanPembayaranOnline;
use App\Models\Cabang;
use App\Models\PembayaranOnline;
use App\Models\Pengaturan;
use App\Models\PerangkatTv;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\ShiftService;
use App\Services\Gateway\BayarMandiriService;
use App\Services\Gateway\DokuGateway;
use App\Services\Gateway\PengaturanGateway;
use App\Services\Tv\StatusTvService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Midtrans, Duitku, iPaymu, DOKU: buat tagihan bertanda tangan, notifikasi valid/tidak, cek status */
class GatewayLainTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $cabang;

    private Unit $unit;

    private BayarMandiriService $layanan;

    private PengaturanGateway $g;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);

        $this->unit = Unit::where('kode', 'TV2')->firstOrFail(); // Rp8.000/jam
        $this->layanan = app(BayarMandiriService::class);
        $this->g = app(PengaturanGateway::class);

        Pengaturan::simpan('bayar_mandiri.aktif', true, $this->cabang->id);
        app(ShiftService::class)->buka(User::where('email', 'owner@billing.test')->firstOrFail(), $this->cabang, 0);
    }

    private function aturan(string $provider, array $isi): void
    {
        $this->g->simpan('provider', $provider);

        foreach ($isi as $k => $v) {
            $this->g->simpan($k, $v);
        }
    }

    private function selesai(PembayaranOnline $p): void
    {
        $this->assertSame('selesai', $p->fresh()->status);
        $this->assertSame(Unit::STATUS_MAIN, $this->unit->fresh()->status);
    }

    public function test_midtrans(): void
    {
        $this->aturan('midtrans', ['midtrans_server_key' => 'SB-Mid-server-x']);

        Http::fake(['api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code' => '201', 'transaction_id' => 'mt-1', 'qr_string' => '000201MIDTRANS', 'expiry_time' => now('Asia/Jakarta')->addMinutes(10)->format('Y-m-d H:i:s'),
        ])]);

        $p = $this->layanan->buatTagihan($this->unit, 12_000);

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('SB-Mid-server-x:'))
            && $r['payment_type'] === 'qris' && $r['transaction_details']['order_id'] === $p->merchant_ref
            && $r['custom_expiry']['unit'] === 'minute');
        $this->assertSame('000201MIDTRANS', $p->qr_string);
        $this->assertSame('mt-1', $p->referensi);

        $isi = ['order_id' => $p->merchant_ref, 'status_code' => '200', 'gross_amount' => '12000.00', 'transaction_status' => 'settlement', 'transaction_id' => 'mt-1'];

        $this->postJson('/api/gateway/midtrans/callback', $isi + ['signature_key' => 'salah'])->assertStatus(401);
        $this->postJson('/api/gateway/midtrans/callback', $isi + ['signature_key' => hash('sha512', $p->merchant_ref.'20012000.00SB-Mid-server-x')])->assertOk();

        $this->selesai($p);
    }

    public function test_duitku_callback_form(): void
    {
        $this->aturan('duitku', ['duitku_merchant_code' => 'D123', 'duitku_api_key' => 'kunci-duitku']);

        Http::fake(['sandbox.duitku.com/webapi/api/merchant/v2/inquiry' => Http::response([
            'merchantCode' => 'D123', 'reference' => 'DK-1', 'qrString' => '000201DUITKU', 'amount' => '12000', 'statusCode' => '00', 'statusMessage' => 'SUCCESS',
        ])]);

        $p = $this->layanan->buatTagihan($this->unit, 12_000);

        Http::assertSent(fn ($r) => $r['signature'] === md5('D123'.$p->merchant_ref.'12000kunci-duitku') && $r['paymentMethod'] === 'SP'
            && str_ends_with($r['callbackUrl'], '/api/gateway/duitku/callback'));

        $isi = ['merchantCode' => 'D123', 'amount' => '12000', 'merchantOrderId' => $p->merchant_ref, 'resultCode' => '00', 'reference' => 'DK-1'];

        $this->post('/api/gateway/duitku/callback', $isi + ['signature' => 'salah'])->assertStatus(401);
        $this->post('/api/gateway/duitku/callback', $isi + ['signature' => md5('D123'.'12000'.$p->merchant_ref.'kunci-duitku')])->assertOk();

        $this->selesai($p);
    }

    public function test_ipaymu_notifikasi_dicek_ulang_ke_api(): void
    {
        $this->aturan('ipaymu', ['ipaymu_va' => '0000001234', 'ipaymu_api_key' => 'SANDBOX-KEY']);
        $lunas = false;
        $ref = null;

        Http::fake([
            'sandbox.ipaymu.com/api/v2/payment/direct' => function ($r) use (&$ref) {
                $ref = $r['referenceId'];

                return Http::response(['Status' => 200, 'Data' => ['TransactionId' => 77, 'PaymentNo' => '000201IPAYMU', 'Fee' => 84, 'Expired' => now('Asia/Jakarta')->addMinutes(10)->format('Y-m-d H:i:s')]]);
            },
            'sandbox.ipaymu.com/api/v2/transaction' => function () use (&$lunas, &$ref) {
                return Http::response(['Status' => 200, 'Data' => ['TransactionId' => 77, 'ReferenceId' => $ref, 'Status' => $lunas ? 1 : 0, 'Fee' => 84]]);
            },
        ]);

        $p = $this->layanan->buatTagihan($this->unit, 12_000);

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/payment/direct')) {
                return false;
            }
            $tanda = hash_hmac('sha256', 'POST:0000001234:'.strtolower(hash('sha256', $r->body())).':SANDBOX-KEY', 'SANDBOX-KEY');

            return $r->hasHeader('va', '0000001234') && $r->hasHeader('signature', $tanda) && $r['paymentMethod'] === 'qris';
        });
        $this->assertSame('000201IPAYMU', $p->qr_string);
        $this->assertSame(84, $p->biaya);

        // Notifikasi palsu "berhasil" tidak dipercaya: API iPaymu masih pending
        $this->post('/api/gateway/ipaymu/callback', ['trx_id' => 77, 'reference_id' => $p->merchant_ref, 'status' => 'berhasil', 'status_code' => 1])->assertOk();
        $this->assertSame('menunggu', $p->fresh()->status);

        $lunas = true;
        $this->post('/api/gateway/ipaymu/callback', ['trx_id' => 77, 'reference_id' => $p->merchant_ref, 'status' => 'berhasil', 'status_code' => 1])->assertOk();

        $this->selesai($p);
    }

    public function test_doku_tautan_bayar_dan_notifikasi_bertanda_tangan(): void
    {
        $this->aturan('doku', ['doku_client_id' => 'BRN-1', 'doku_secret_key' => 'SK-doku']);

        Http::fake(['api-sandbox.doku.com/checkout/v1/payment' => Http::response([
            'message' => ['SUCCESS'], 'response' => ['payment' => ['url' => 'https://sandbox.doku.com/checkout/link/abc', 'token_id' => 'tok-1']],
        ])]);

        $p = $this->layanan->buatTagihan($this->unit, 12_000);

        Http::assertSent(fn ($r) => $r->hasHeader('Client-Id', 'BRN-1') && $r->hasHeader('Signature', DokuGateway::tandaTangan(
            'BRN-1', $r->header('Request-Id')[0], $r->header('Request-Timestamp')[0], '/checkout/v1/payment', $r->body(), 'SK-doku',
        )) && $r['order']['invoice_number'] === $p->merchant_ref);

        $this->assertSame('https://sandbox.doku.com/checkout/link/abc', $p->urlBayar());

        // TV menampilkan QR tautan (bukan string QRIS)
        $tv = new PerangkatTv;
        $tv->forceFill([
            'tenant_id' => $this->cabang->tenant_id, 'cabang_id' => $this->cabang->id, 'unit_id' => $this->unit->id,
            'android_id' => 'uji-doku', 'status' => PerangkatTv::STATUS_AKTIF, 'token_hash' => hash('sha256', 'x'),
        ])->save();
        Http::fake(['api-sandbox.doku.com/orders/*' => Http::response(['transaction' => ['status' => 'PENDING']])]);
        $t = app(StatusTvService::class)->untuk($tv)['bayar_mandiri']['tagihan'];
        $this->assertSame('tautan', $t['tipe']);
        $this->assertSame('https://sandbox.doku.com/checkout/link/abc', $t['qris']);

        // HP pelanggan mendapat tombol bayar
        $this->get('/main/'.$this->layanan->token($this->unit))->assertSee('Bayar sekarang')->assertSee('sandbox.doku.com/checkout/link/abc');

        $body = json_encode(['order' => ['invoice_number' => $p->merchant_ref, 'amount' => 12000], 'transaction' => ['status' => 'SUCCESS']]);
        $kepala = fn (string $tanda) => [
            'HTTP_Client-Id' => 'BRN-1', 'HTTP_Request-Id' => 'n-1', 'HTTP_Request-Timestamp' => '2026-09-30T05:00:00Z',
            'HTTP_Signature' => $tanda, 'CONTENT_TYPE' => 'application/json',
        ];

        $this->call('POST', '/api/gateway/doku/callback', [], [], [], $kepala('HMACSHA256=salah'), $body)->assertStatus(401);
        $this->call('POST', '/api/gateway/doku/callback', [], [], [], $kepala(
            DokuGateway::tandaTangan('BRN-1', 'n-1', '2026-09-30T05:00:00Z', '/api/gateway/doku/callback', $body, 'SK-doku'),
        ), $body)->assertOk();

        $this->selesai($p);
    }

    public function test_halaman_admin_simpan_tiap_gateway(): void
    {
        $this->actingAs(User::where('email', 'owner@billing.test')->firstOrFail());
        $halaman = Livewire::test(PengaturanPembayaranOnline::class);

        foreach (['midtrans' => 'Server key', 'duitku' => 'Metode QRIS', 'ipaymu' => 'Nomor VA', 'doku' => 'Client ID'] as $p => $isian) {
            $halaman->set('data.provider', $p)->assertSee($isian);
        }

        // Midtrans & DOKU: URL notifikasi diisi di dashboard; Duitku & iPaymu dikirim otomatis per tagihan
        $halaman->set('data.provider', 'doku')->assertSee('/api/gateway/doku/callback', false);

        $halaman->set('data.provider', 'duitku')
            ->set('data.duitku_merchant_code', 'D999')
            ->set('data.duitku_api_key', 'rahasia-duitku')
            ->call('simpan')->assertHasNoErrors();

        $this->assertSame('duitku', $this->g->provider());
        $this->assertSame('D999', $this->g->nilai('duitku_merchant_code'));
        $this->assertSame('rahasia-duitku', $this->g->nilai('duitku_api_key'));
        $this->assertNotSame('rahasia-duitku', Pengaturan::where('kunci', 'gateway.duitku_api_key')->first()->nilai['v']);

        // Simpan ulang tanpa mengisi kunci: kunci lama tetap
        $halaman->call('simpan');
        $this->assertSame('rahasia-duitku', $this->g->nilai('duitku_api_key'));
    }

    public function test_status_dicek_berkala_tanpa_callback(): void
    {
        $this->aturan('midtrans', ['midtrans_server_key' => 'SB-Mid-server-x']);
        $status = 'pending';

        Http::fake([
            'api.sandbox.midtrans.com/v2/charge' => Http::response(['status_code' => '201', 'transaction_id' => 'mt-2', 'qr_string' => '000201']),
            'api.sandbox.midtrans.com/v2/*/status' => function () use (&$status) {
                return Http::response(['status_code' => '200', 'transaction_status' => $status]);
            },
        ]);

        $p = $this->layanan->buatTagihan($this->unit, 12_000);
        $this->assertSame('menunggu', $this->layanan->periksa($p)->status);

        $status = 'settlement';
        Cache::forget('cek-bayar:'.$p->id);
        $this->layanan->periksa($p->fresh());

        $this->selesai($p);
    }
}
