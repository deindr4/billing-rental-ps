<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Livewire\Operator\DaftarTurnamen;
use App\Livewire\Publik\TurnamenPublik;
use App\Models\Cabang;
use App\Models\Transaksi;
use App\Models\Turnamen;
use App\Models\TurnamenPertandingan;
use App\Models\User;
use App\Services\Billing\ShiftService;
use App\Services\LaporanService;
use App\Services\Publik\BillboardService;
use App\Services\Publik\TurnamenService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TurnamenTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $cabang;

    private User $owner;

    private TurnamenService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        $this->service = app(TurnamenService::class);
        $this->actingAs($this->owner);
    }

    private function turnamen(int $biaya = 0, int $kuota = 16): Turnamen
    {
        return $this->service->simpan($this->cabang, [
            'nama' => 'Turnamen FC 25', 'game' => 'EA FC 25', 'mulai_pada' => now()->addWeek()->toDateTimeString(),
            'biaya_daftar' => $biaya, 'kuota' => $kuota,
        ]);
    }

    private function daftarkan(Turnamen $t, int $n): void
    {
        foreach (range(1, $n) as $i) {
            $this->service->daftar($t, "Pemain {$i}", '08120000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }
    }

    public function test_bagan_lima_peserta_dengan_bye_sampai_juara(): void
    {
        $t = $this->turnamen();
        $this->daftarkan($t, 5);
        $this->service->mulai($t);

        $t->refresh();
        $this->assertSame('berjalan', $t->status);
        $this->assertSame(4 + 2 + 1, $t->pertandingan()->count()); // bagan 8

        // 3 bye di babak 1 sudah otomatis selesai, 1 pertandingan sungguhan
        $babak1 = $t->pertandingan()->where('babak', 1)->get();
        $this->assertSame(3, $babak1->where('status', 'selesai')->count());

        // Mainkan sampai final: pemain A selalu menang
        for ($i = 0; $i < 10; $i++) {
            $m = TurnamenPertandingan::where('turnamen_id', $t->id)->where('status', '!=', 'selesai')
                ->whereNotNull('peserta_a_id')->whereNotNull('peserta_b_id')->orderBy('babak')->orderBy('nomor')->first();

            if (! $m) {
                break;
            }

            $this->service->hasil($m, 3, 1);
        }

        $t->refresh();
        $this->assertSame('selesai', $t->status);
        $juara = $this->service->juara($t);
        $this->assertArrayHasKey(1, $juara);
        $this->assertArrayHasKey(2, $juara);
    }

    public function test_skor_seri_ditolak_dan_minimal_dua_peserta(): void
    {
        $t = $this->turnamen();
        $this->daftarkan($t, 1);

        try {
            $this->service->mulai($t);
            $this->fail('Seharusnya ditolak');
        } catch (BillingException) {
        }

        $this->service->daftar($t, 'Lawan', '081299998888');
        $this->service->mulai($t);

        $final = $t->pertandingan()->first();
        $this->expectException(BillingException::class);
        $this->service->hasil($final, 2, 2);
    }

    public function test_pendaftaran_berbayar_masuk_omzet_dan_kas(): void
    {
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
        $t = $this->turnamen(50_000);
        $p = $this->service->daftar($t, 'Raka', '081211112222');

        $this->assertSame('terdaftar', $p->status);

        $trx = $this->service->bayar($p, $this->owner, 'tunai', 100_000);

        $this->assertSame(Transaksi::JENIS_TURNAMEN, $trx->jenis);
        $this->assertSame(Transaksi::STATUS_LUNAS, $trx->status);
        $this->assertSame(50_000, $trx->kembalian);
        $this->assertSame('lunas', $p->fresh()->status);

        $r = app(LaporanService::class)->ringkasan(today(), now());
        $this->assertSame(50_000, $r['pendapatan_lainnya']);

        // Duplikat nomor ditolak; peserta belum bayar tidak masuk bagan
        $this->service->daftar($t, 'Budi', '081233334444');
        $this->service->daftar($t, 'Cici', '081255556666');
        $this->service->bayar($t->peserta()->where('nama', 'Cici')->first(), $this->owner, 'qris');

        $this->service->mulai($t);
        $ids = $t->pertandingan()->get()->flatMap(fn ($m) => [$m->peserta_a_id, $m->peserta_b_id])->filter()->unique();
        $this->assertCount(2, $ids); // Raka & Cici saja
    }

    public function test_halaman_kasir_publik_dan_billboard(): void
    {
        $t = $this->turnamen(0, 4);

        Livewire::test(TurnamenPublik::class, ['slug' => $t->slug])
            ->set('nama', 'ProGamer')->set('telepon', '081277776666')
            ->call('daftar')->assertHasNoErrors()->assertSee('ProGamer');

        $this->assertSame('online', $t->peserta()->first()->sumber);

        Livewire::test(DaftarTurnamen::class)
            ->call('pilih', $t->id)
            ->set('pesertaNama', 'Kasir Player')->set('pesertaTelepon', '081255554444')
            ->call('tambahPeserta')->assertHasNoErrors()
            ->call('mulaiTurnamen')
            ->assertSet('tab', 'bagan')
            ->assertSee('Final');

        $data = app(BillboardService::class)->data($this->cabang->load('tenant'));
        $this->assertSame('Turnamen FC 25', $data['turnamen']['nama']);
        $this->assertSame('Final', $data['turnamen']['bagian'][0]['kolom'][0]['nama']);
        $this->assertSame('Sistem gugur', $data['turnamen']['format']);

        $this->get(route('turnamen.publik', $t->slug))->assertOk()->assertSee('Turnamen FC 25');
        $this->get(route('turnamen'))->assertOk();
    }
}
