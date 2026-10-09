<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Filament\Resources\Penggajian\Pages\EditPenggajian;
use App\Filament\Resources\Penggajian\Pages\ListPenggajian;
use App\Models\Absensi;
use App\Models\Cabang;
use App\Models\Karyawan;
use App\Models\Pembayaran;
use App\Models\Penggajian;
use App\Models\Pengeluaran;
use App\Models\Shift;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\Karyawan\GajiService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Rekap gaji: pokok prorata + upah hadir + upah jam + bonus target omzet − potongan selisih yang disetujui owner */
class GajiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Cabang $cabang;

    private Karyawan $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->cabang->tenant_id);
        $this->actingAs($this->owner);

        // Karyawan memakai akun owner supaya shift yang dipegangnya ikut dihitung
        $this->kasir = Karyawan::create([
            'tenant_id' => $this->cabang->tenant_id, 'cabang_id' => $this->cabang->id, 'user_id' => $this->owner->id, 'nama' => 'Rina',
            'periode_gaji' => 'bulanan', 'gaji_pokok' => 3_100_000, 'upah_shift' => 20_000, 'upah_jam' => 5_000,
            'bonus_target' => 100_000, 'bonus_jenis' => 'persen', 'bonus_nilai' => 10,
        ]);
    }

    /** September 2026: 2 hari hadir (8 j + 6 j), 2 shift (omzet 150rb & 80rb), shift pertama kas kurang 20rb */
    private function dataSeptember(): void
    {
        foreach ([['2026-09-10 10:00', '2026-09-10 18:00'], ['2026-09-11 10:00', '2026-09-11 16:00']] as [$m, $p]) {
            Absensi::create(['tenant_id' => $this->cabang->tenant_id, 'cabang_id' => $this->cabang->id, 'karyawan_id' => $this->kasir->id,
                'tanggal' => substr($m, 0, 10), 'masuk_pada' => $m, 'pulang_pada' => $p, 'menit_kerja' => Carbon::parse($m)->diffInMinutes($p)]);
        }

        foreach ([['2026-09-10 10:00', 150_000, -20_000], ['2026-09-11 10:00', 80_000, 0]] as $i => [$buka, $omzet, $selisih]) {
            $s = Shift::create(['tenant_id' => $this->cabang->tenant_id, 'cabang_id' => $this->cabang->id, 'user_id' => $this->owner->id,
                'nomor' => "SFT-UJI-{$i}", 'status' => 'tutup', 'dibuka_pada' => $buka, 'ditutup_pada' => Carbon::parse($buka)->addHours(8),
                'kas_awal' => 0, 'selisih' => $selisih]);
            $t = Transaksi::create(['tenant_id' => $this->cabang->tenant_id, 'cabang_id' => $this->cabang->id, 'shift_id' => $s->id,
                'user_id' => $this->owner->id, 'nomor' => "TRX-UJI-{$i}", 'jenis' => Transaksi::JENIS_POS, 'status' => 'lunas', 'total' => $omzet]);
            Pembayaran::create(['tenant_id' => $this->cabang->tenant_id, 'cabang_id' => $this->cabang->id, 'transaksi_id' => $t->id,
                'shift_id' => $s->id, 'user_id' => $this->owner->id, 'metode' => 'tunai', 'jumlah' => $omzet, 'status' => 'sukses', 'dibayar_pada' => $buka]);
        }
    }

    public function test_hitung_komponen_dan_potongan_disetujui(): void
    {
        $this->dataSeptember();
        $layanan = app(GajiService::class);
        $h = $layanan->hitung($this->kasir, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        // Pokok penuh (sebulan) 3,1jt + hadir 2×20rb + 14 jam×5rb + bonus 10% × (150rb−100rb) = 5rb
        $this->assertSame([3_100_000, 40_000, 70_000, 5_000, 0], [$h['gaji_pokok'], $h['upah_hadir'], $h['upah_jam'], $h['bonus'], $h['potongan']]);
        $this->assertSame(3_215_000, $h['total']);
        $this->assertSame(20_000, $h['rincian']['usulan_potongan'][0]['nilai']);

        // Setengah bulan: pokok prorata 15/30
        $this->assertSame(1_550_000, $layanan->hitung($this->kasir, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-15'))['gaji_pokok']);

        // Draft → centang potongan + penyesuaian → setujui → bayar (pengeluaran gaji)
        $p = $layanan->buat($this->kasir, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $this->owner, $this->cabang);
        $kunci = $p->rincian['usulan_potongan'][0]['kunci'];

        Livewire::test(EditPenggajian::class, ['record' => $p->id])
            ->assertSee('Rina')->assertSee('Kas kurang')
            ->fillForm(['potongan_setuju' => [$kunci], 'penyesuaian_daftar' => [['keterangan' => 'Uang makan', 'nilai' => 50_000]]])
            ->call('save')->assertHasNoErrors();

        $p->refresh();
        $this->assertSame([20_000, 50_000, 3_245_000], [$p->potongan, $p->penyesuaian, $p->total]);

        try {
            $layanan->bayar($p, $this->owner, 'rekening', $this->cabang);
            $this->fail('Belum disetujui seharusnya ditolak');
        } catch (BillingException) {
        }

        Livewire::test(EditPenggajian::class, ['record' => $p->id])->callAction('setujui');
        Livewire::test(EditPenggajian::class, ['record' => $p->id])->callAction('bayar', ['sumber_dana' => 'rekening']);

        $p->refresh();
        $this->assertSame('dibayar', $p->status);
        $this->assertSame([3_245_000, 'gaji'], [Pengeluaran::find($p->pengeluaran_id)->jumlah, Pengeluaran::find($p->pengeluaran_id)->kategori]);

        // Slip gaji & tidak bisa dobel periode
        $this->get(route('gaji.slip', ['id' => $p->id]))->assertOk()->assertSee('Slip Gaji')->assertSee('Uang makan')->assertSee('Rp 3.245.000');
        $this->expectException(BillingException::class);
        $layanan->buat($this->kasir, Carbon::parse('2026-09-15'), Carbon::parse('2026-09-20'), $this->owner, $this->cabang);
    }

    public function test_buat_rekap_banyak_karyawan_dan_batal_membatalkan_pengeluaran(): void
    {
        $udin = Karyawan::create(['tenant_id' => $this->cabang->tenant_id, 'nama' => 'Udin', 'periode_gaji' => 'mingguan', 'upah_shift' => 80_000]);

        Livewire::test(ListPenggajian::class)
            ->callAction('buat', ['jenis' => 'bulanan', 'dari' => '2026-09-01', 'sampai' => '2026-09-30', 'karyawan' => [$this->kasir->id]]);
        $this->assertSame(1, Penggajian::count());
        $this->assertSame(3_100_000, Penggajian::first()->total);
        $this->get('/admin/penggajian')->assertOk()->assertSee('Rina');

        // Periode bawaan mingguan = Senin–Minggu lalu
        [$dari, $sampai] = GajiService::periodeBawaan('mingguan', Carbon::parse('2026-10-08'));
        $this->assertSame(['2026-09-28', '2026-10-04'], [$dari->toDateString(), $sampai->toDateString()]);

        $layanan = app(GajiService::class);
        $p = Penggajian::first();
        $layanan->setujui($p, $this->owner);
        $layanan->bayar($p, $this->owner, 'rekening', $this->cabang);
        $layanan->batal($p->fresh(), $this->owner, 'Salah periode');

        $this->assertSame('batal', $p->fresh()->status);
        $this->assertSame('dibatalkan', Pengeluaran::find($p->pengeluaran_id)->status);
        $this->assertNotNull($udin->id);
    }
}
