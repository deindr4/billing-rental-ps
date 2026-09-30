<?php

namespace Tests\Feature;

use App\Livewire\Operator\Pembayaran;
use App\Models\Cabang;
use App\Models\Pengaturan;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\ShiftService;
use App\Services\Publik\QrisService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QrisTest extends TestCase
{
    use RefreshDatabase;

    private function statis(): string
    {
        $q = app(QrisService::class);
        $tlv = fn ($t, $v) => $t.str_pad((string) strlen($v), 2, '0', STR_PAD_LEFT).$v;
        $isi = $tlv('00', '01').$tlv('01', '11')
            .$tlv('26', $tlv('00', 'ID.DANA.WWW').$tlv('01', '936009153022591481').$tlv('03', 'UMI'))
            .$tlv('51', $tlv('00', 'ID.CO.QRIS.WWW').$tlv('02', 'ID1020017611473').$tlv('03', 'UMI'))
            .$tlv('52', '5812').$tlv('53', '360').$tlv('58', 'ID').$tlv('59', 'Delta Gaming').$tlv('60', 'Surabaya').'6304';

        return $isi.$q->crc($isi);
    }

    public function test_crc_dan_konversi_statis_ke_dinamis(): void
    {
        $q = app(QrisService::class);

        $this->assertSame('29B1', $q->crc('123456789')); // vektor uji CRC16-CCITT-FALSE
        $this->assertTrue($q->valid($this->statis()));
        $this->assertFalse($q->valid(substr($this->statis(), 0, -1).'0'));

        $d = $q->dinamis($this->statis(), 16500);
        $t = $q->parse($d);

        $this->assertTrue($q->valid($d));
        $this->assertSame('12', $t['01']);
        $this->assertSame('16500', $t['54']);
        $this->assertSame(['00', '01', '26', '51', '52', '53', '54', '58', '59', '60', '63'], array_map('strval', array_keys($t)));

        // Dinamis ulang dari QRIS yang sudah dinamis: nominal diganti, tidak dobel
        $this->assertSame('8000', $q->parse($q->dinamis($d, 8000))['54']);
    }

    public function test_qr_tampil_di_pembayaran_dan_tv(): void
    {
        $this->seed(DatabaseSeeder::class);
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $owner = User::where('email', 'owner@billing.test')->firstOrFail();
        app(Tenancy::class)->set($cabang->tenant_id, $cabang->id);
        $this->actingAs($owner);

        app(ShiftService::class)->buka($owner, $cabang, 0);
        $sesi = app(BillingService::class)->mulai(Unit::where('kode', 'TV2')->first(), $owner, ['mode' => 'durasi', 'durasi_menit' => 60]);

        // Belum diatur: tidak ada QR
        Livewire::test(Pembayaran::class)->call('bukaUntuk', $sesi->transaksi_id)
            ->set('baris.0.metode', 'qris')->assertDontSee('Scan dengan aplikasi bank');

        Pengaturan::simpan('qris.payload', $this->statis(), $cabang->id);
        Pengaturan::simpan('qris.aktif', true, $cabang->id);

        Livewire::test(Pembayaran::class)->call('bukaUntuk', $sesi->transaksi_id)
            ->set('baris.0.metode', 'qris')
            ->assertSee('Scan dengan aplikasi bank')
            ->assertSee('Delta Gaming');

        $qr = app(QrisService::class)->untukNominal($cabang->id, 8500);
        $this->assertSame('8500', app(QrisService::class)->parse($qr)['54']);
    }
}
