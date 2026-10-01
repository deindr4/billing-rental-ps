<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Models\Cabang;
use App\Models\Produk;
use App\Models\ProdukStok;
use App\Models\Transaksi;
use App\Models\Turnamen;
use App\Models\TurnamenPertandingan;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\ShiftService;
use App\Services\Billing\StokService;
use App\Services\Publik\TurnamenService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProdukSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Format turnamen (gugur ganda, liga, fase grup + gugur), bundling F&B & keuangan */
class TurnamenFormatTest extends TestCase
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

    private function turnamen(array $isi): Turnamen
    {
        return $this->service->simpan($this->cabang, $isi + [
            'nama' => 'Turnamen Uji', 'game' => 'PES', 'mulai_pada' => now()->addWeek()->toDateTimeString(), 'kuota' => 64,
        ]);
    }

    private function daftarkan(Turnamen $t, int $n): void
    {
        foreach (range(1, $n) as $i) {
            $this->service->daftar($t, "Pemain {$i}", '0812'.str_pad((string) $i, 8, '0', STR_PAD_LEFT));
        }
    }

    /** Mainkan semua laga yang siap dengan skor acak sampai tidak ada lagi (seri boleh di liga/grup) */
    private function mainkanSemua(Turnamen $t): int
    {
        $jumlah = 0;

        for ($i = 0; $i < 500; $i++) {
            $m = TurnamenPertandingan::where('turnamen_id', $t->id)->where('status', '!=', 'selesai')
                ->whereNotNull('peserta_a_id')->whereNotNull('peserta_b_id')
                ->orderByRaw("FIELD(tahap, 'grup', 'liga', 'gugur', 'atas', 'bawah', 'final')")->orderBy('babak')->orderBy('nomor')->first();

            if (! $m) {
                break;
            }

            $boleSeri = in_array($m->tahap, ['liga', 'grup'], true);
            [$a, $b] = [random_int(0, 4), random_int(0, 4)];

            if (! $boleSeri && $a === $b) {
                $a++;
            }

            $this->service->hasil($m, $a, $b);
            $jumlah++;
        }

        return $jumlah;
    }

    public function test_gugur_ganda_berbagai_jumlah_peserta_tuntas(): void
    {
        foreach ([2, 3, 4, 5, 6, 8, 11, 16] as $n) {
            $t = $this->turnamen(['format' => 'gugur_ganda', 'nama' => "Ganda {$n}"]);
            $this->daftarkan($t, $n);
            $this->service->mulai($t);
            $this->mainkanSemua($t);

            $t->refresh();
            $this->assertSame('selesai', $t->status, "gugur ganda {$n} peserta tidak tuntas");
            $this->assertSame(0, $t->pertandingan()->where('status', '!=', 'selesai')->count());

            // Setiap peserta selain juara kalah tepat 2x (tersingkir); juara kalah 0 atau 1x
            // Juara = pemenang final ulang bila dimainkan, selain itu pemenang grand final
            $juara = $t->pertandingan()->where('tahap', 'final')->whereNotNull('pemenang_id')->reorder()->orderByDesc('babak')->first()->pemenang_id;
            $kalah = [];

            foreach ($t->pertandingan()->whereNotNull('skor_a')->get() as $m) {
                $kalahId = $m->pemenang_id === $m->peserta_a_id ? $m->peserta_b_id : $m->peserta_a_id;
                $kalah[$kalahId] = ($kalah[$kalahId] ?? 0) + 1;
            }

            foreach ($t->peserta()->where('status', 'lunas')->pluck('id') as $id) {
                if ($id === $juara) {
                    $this->assertLessThanOrEqual(1, $kalah[$id] ?? 0);
                } elseif ($n > 2) {
                    $this->assertSame(2, $kalah[$id] ?? 0, "gugur ganda {$n}: peserta harus kalah 2x untuk tersingkir");
                }
            }

            $this->assertArrayHasKey(1, $this->service->juara($t));
            $this->assertArrayHasKey(2, $this->service->juara($t));
        }
    }

    public function test_liga_semua_bertemu_klasemen_dan_juara(): void
    {
        foreach ([[5, 1], [4, 2]] as [$n, $putaran]) {
            $t = $this->turnamen(['format' => 'liga', 'putaran' => $putaran, 'nama' => "Liga {$n}"]);
            $this->daftarkan($t, $n);
            $this->service->mulai($t);

            $this->assertSame($n * ($n - 1) / 2 * $putaran, $t->pertandingan()->count());

            // Setiap pasangan bertemu tepat $putaran kali
            $pasangan = $t->pertandingan()->get()->map(fn ($m) => collect([$m->peserta_a_id, $m->peserta_b_id])->sort()->implode('|'))->countBy();
            $this->assertTrue($pasangan->every(fn ($c) => $c === $putaran));

            // Seri boleh di liga
            $m = $t->pertandingan()->first();
            $this->service->hasil($m, 1, 1);
            $this->assertNull($m->fresh()->pemenang_id);

            $this->mainkanSemua($t);
            $t->refresh();
            $this->assertSame('selesai', $t->status);

            $k = $this->service->klasemen($t);
            $this->assertCount($n, $k);
            $this->assertSame(array_sum(array_column($k, 'menang')), array_sum(array_column($k, 'kalah')));
            $this->assertSame($k[0]['peserta']->nama, $this->service->juara($t)[1]);
            $this->assertGreaterThanOrEqual($k[1]['poin'], $k[0]['poin']);
        }
    }

    public function test_grup_gugur_lolos_silang_antar_grup(): void
    {
        $t = $this->turnamen(['format' => 'grup_gugur', 'jumlah_grup' => 4, 'lolos_per_grup' => 2]);
        $this->daftarkan($t, 16);
        $this->service->mulai($t);

        $t->refresh();
        $this->assertSame(4, $t->peserta()->distinct()->count('grup'));
        $this->assertSame(4 * 6, $t->pertandingan()->where('tahap', 'grup')->count()); // 4 grup x 6 laga
        $this->assertFalse($t->pertandingan()->where('tahap', 'gugur')->exists());

        // Selesaikan fase grup -> babak gugur 8 peserta otomatis dibuat
        foreach ($t->pertandingan()->where('tahap', 'grup')->get() as $m) {
            $this->service->hasil($m, random_int(0, 3), random_int(0, 3));
        }

        $babak1 = $t->pertandingan()->where('tahap', 'gugur')->where('babak', 1)->with(['pesertaA', 'pesertaB'])->get();
        $this->assertCount(4, $babak1);

        foreach ($babak1 as $m) {
            $this->assertNotSame($m->pesertaA->grup, $m->pesertaB->grup, 'babak gugur pertama harus antar grup berbeda');
        }

        // Lolos = 2 teratas tiap grup
        $lolos = $babak1->flatMap(fn ($m) => [$m->peserta_a_id, $m->peserta_b_id])->sort()->values();
        $harus = collect(['A', 'B', 'C', 'D'])->flatMap(fn ($g) => array_map(fn ($b) => $b['peserta']->id, array_slice($this->service->klasemen($t, $g), 0, 2)))->sort()->values();
        $this->assertEquals($harus, $lolos);

        // Hasil grup terkunci setelah babak gugur dibuat
        $this->expectException(BillingException::class);
        $this->service->hasil($t->pertandingan()->where('tahap', 'grup')->first(), 9, 0);
    }

    public function test_grup_ganjil_tiga_grup_tuntas(): void
    {
        $t = $this->turnamen(['format' => 'grup_gugur', 'jumlah_grup' => 3, 'lolos_per_grup' => 2]);
        $this->daftarkan($t, 10);
        $this->service->mulai($t);
        $this->mainkanSemua($t);

        $t->refresh();
        $this->assertSame('selesai', $t->status);
        $this->assertCount(3, $this->service->juara($t) + [3 => 'x']);

        foreach ($t->pertandingan()->where('tahap', 'gugur')->where('babak', 1)->whereNotNull('peserta_a_id')->whereNotNull('peserta_b_id')->with(['pesertaA', 'pesertaB'])->get() as $m) {
            $this->assertNotSame($m->pesertaA->grup, $m->pesertaB->grup);
        }
    }

    public function test_bundling_fnb_stok_keluar_dan_keuangan(): void
    {
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
        $this->seed(ProdukSeeder::class);
        $produk = Produk::query()->where('lacak_stok', true)->firstOrFail();
        app(StokService::class)->catat($produk, $this->cabang->id, 50, 'masuk', $this->owner, null, 'uji', 4000);
        $stokAwal = ProdukStok::where('produk_id', $produk->id)->value('qty');

        $t = $this->turnamen(['format' => 'gugur', 'biaya_daftar' => 20000, 'kuota' => 16, 'bonus_produk_id' => $produk->id, 'bonus_qty' => 1, 'total_hadiah' => 150000]);
        $this->assertSame($produk->nama, $t->labelBonus());

        $this->daftarkan($t, 2);
        $trx = $this->service->bayar($t->peserta()->first(), $this->owner, 'tunai', 20000);

        $this->assertSame(20000, $trx->total);
        $hpp = (int) ProdukStok::where('produk_id', $produk->id)->value('hpp_rata'); // rata-rata tertimbang stok seeder + stok uji
        $this->assertTrue($trx->items->contains(fn ($i) => $i->referensi_id === $produk->id && $i->subtotal === 0 && $i->hpp_satuan === $hpp));
        $this->assertSame($stokAwal - 1, ProdukStok::where('produk_id', $produk->id)->value('qty'));

        $k = $this->service->keuangan($t->fresh());
        // Perkiraan kuota penuh: 16 x 20.000 = 320.000; modal bonus 16 x HPP; hadiah 150.000
        $this->assertSame(320000, $k['perkiraan']['masuk']);
        $this->assertSame(16 * $hpp, $k['perkiraan']['modal_bonus']);
        $this->assertSame(320000 - 16 * $hpp, $k['perkiraan']['bersih']);
        $this->assertSame(320000 - 16 * $hpp - 150000, $k['perkiraan']['sisa']);
        $this->assertSame(1, $k['realisasi']['peserta']);
        $this->assertSame(20000, $k['realisasi']['masuk']);
        $this->assertSame((int) (floor((320000 - 16 * $hpp) * 0.5 / 1000) * 1000), $k['saran'][50]);

        // Pembatalan transaksi mengembalikan stok bonus
        app(BillingService::class)->batalkan(Transaksi::findOrFail($trx->id), $this->owner, 'uji batal');
        $this->assertSame($stokAwal, ProdukStok::where('produk_id', $produk->id)->value('qty'));
    }
}
