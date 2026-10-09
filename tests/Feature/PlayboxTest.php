<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Models\Cabang;
use App\Models\KasMutasi;
use App\Models\Penyewa;
use App\Models\Playbox;
use App\Models\SewaPlaybox;
use App\Models\Shift;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\ShiftService;
use App\Services\LaporanService;
use App\Services\Playbox\PlayboxService;
use App\Support\Koordinat;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Sewa Playbox bawa pulang: sewa (bayar di muka + deposit), perpanjang, kembali telat + kerusakan, daftar hitam, batal */
class PlayboxTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Cabang $cabang;

    private Playbox $box;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->cabang->tenant_id);
        $this->actingAs($this->owner);
        Carbon::setTestNow(today()->setTime(9, 0));
        $this->shift = app(ShiftService::class)->buka($this->owner, $this->cabang, 0);

        $this->box = Playbox::create([
            'kode' => 'BOX-01', 'nama' => 'PS4 Slim', 'nomor_seri' => 'SN123',
            'kelengkapan' => [['nama' => 'Konsol', 'jumlah' => 1, 'harga_ganti' => 3_000_000], ['nama' => 'Stik', 'jumlah' => 2, 'harga_ganti' => 300_000]],
            'harga_jam' => 10_000, 'harga_hari' => 50_000, 'harga_minggu' => 300_000, 'denda_jam' => 5_000,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @var array<int, UploadedFile> file palsu terhapus bila objeknya dibuang — simpan referensinya */
    private array $berkas = [];

    private function foto(string $nama = 'f.jpg', int $w = 400, int $h = 300): string
    {
        $this->berkas[] = $f = UploadedFile::fake()->image($nama, $w, $h);

        return $f->getRealPath();
    }

    private function penyewa(): Penyewa
    {
        return app(PlayboxService::class)->simpanPenyewa([
            'nama' => 'Budi', 'telepon' => '0812-3456-7890', 'nik' => '5171000000000001', 'alamat' => 'Kost Melati no 3',
            'jenis_tempat' => 'kost', 'koordinat' => 'https://www.google.com/maps/place/Kost/@-8.6705,115.2126,17z/data=!3d-8.6704!4d115.2125',
        ], $this->cabang->tenant_id, $this->foto(), $this->foto());
    }

    private function sewa(Penyewa $p, array $tambah = []): SewaPlaybox
    {
        return app(PlayboxService::class)->sewa($this->box, $p, $this->owner, $tambah + [
            'satuan' => 'hari', 'jumlah' => 2, 'deposit' => 200_000,
            'jaminan' => [['jenis' => 'identitas', 'keterangan' => 'KTP asli', 'nomor' => '5171000000000001']],
            'checklist' => [['nama' => 'Konsol', 'jumlah' => 1, 'kondisi' => 'baik'], ['nama' => 'Stik', 'jumlah' => 2, 'kondisi' => 'baik']],
            'foto_kondisi' => [$this->foto()],
            'tanda_tangan' => 'data:image/png;base64,'.base64_encode(file_get_contents($this->foto('t.png', 50, 20))),
        ]);
    }

    public function test_koordinat_dari_berbagai_bentuk_link(): void
    {
        $this->assertSame([-8.6704, 115.2125], Koordinat::urai('https://www.google.com/maps/place/X/@-8.67,115.21,17z/data=!3d-8.6704!4d115.2125'));
        $this->assertSame([-8.65, 115.22], Koordinat::urai('https://maps.google.com/?q=-8.65,115.22'));
        $this->assertSame([-6.2, 106.816666], Koordinat::urai(' -6.2, 106.816666 '));
        $this->assertSame([-8.1, 115.3], Koordinat::urai('https://www.google.com/maps/@-8.1,115.3,15z'));
        $this->assertNull(Koordinat::urai('Jl. Melati no 3'));
        $this->assertSame('https://www.google.com/maps?q=-8.65,115.22', Koordinat::urlMaps(-8.65, 115.22));
    }

    public function test_sewa_bayar_di_muka_deposit_titipan_dan_foto_privat(): void
    {
        $p = $this->penyewa();
        $this->assertSame(['6281234567890', -8.6704, 115.2125, 'kost'], [$p->telepon, $p->lat, $p->lng, $p->jenis_tempat]);
        Storage::disk('local')->assertExists($p->foto_ktp);
        Storage::disk('public')->assertMissing($p->foto_ktp);

        $s = $this->sewa($p);
        $trx = Transaksi::findOrFail($s->transaksi_id);

        $this->assertSame([100_000, 'sewa_luar'], [$trx->total, $trx->jenis]);
        $this->assertTrue($s->jatuh_tempo->equalTo(now()->addDays(2)));
        $this->assertSame('disewa', $this->box->fresh()->status);
        $this->assertSame(300_000, $s->checklist_keluar[1]['harga_ganti']);
        Storage::disk('local')->assertExists($s->tanda_tangan);

        app(BillingService::class)->bayar($trx, $this->owner, [['metode' => 'tunai', 'jumlah' => 100_000]]);
        $r = app(ShiftService::class)->ringkasan($this->shift);
        $this->assertSame([200_000, 300_000], [$r['deposit'], $r['seharusnya']]); // deposit di laci, bukan omzet
        $this->assertSame(100_000, app(LaporanService::class)->ringkasan(today(), now()->endOfDay())['pendapatan_sewa_luar']);

        // Unit yang sedang disewa tidak bisa disewakan lagi
        $this->expectException(BillingException::class);
        $this->sewa($p);
    }

    public function test_perpanjang_lalu_kembali_telat_dengan_kerusakan_dipotong_deposit(): void
    {
        $s = $this->sewa($this->penyewa());
        $layanan = app(PlayboxService::class);

        $trxPanjang = $layanan->perpanjang($s, $this->owner, 'hari', 1);
        $this->assertSame(50_000, $trxPanjang->total);
        $this->assertTrue($s->fresh()->jatuh_tempo->equalTo(now()->addDays(3)));

        // Kembali 2 jam setelah jatuh tempo (toleransi 30 menit): denda 2 × Rp5.000; 1 stik hilang Rp300.000
        Carbon::setTestNow($s->fresh()->jatuh_tempo->copy()->addHours(2));
        $this->assertSame(10_000, $layanan->hitungDenda($s->fresh())['denda']);

        $data = [
            'checklist' => [['nama' => 'Konsol', 'jumlah' => 1, 'kondisi' => 'baik', 'biaya' => 0], ['nama' => 'Stik', 'jumlah' => 1, 'kondisi' => 'hilang', 'biaya' => 300_000]],
            'foto_kondisi' => [$this->foto()],
        ];

        // Tagihan melebihi deposit tanpa cara bayar sisa: ditolak, tidak ada yang berubah
        try {
            $layanan->kembalikan($s->fresh(), $this->owner, $data);
            $this->fail('Sisa tagihan tanpa metode seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('110.000', $e->getMessage());
        }
        $this->assertSame('berjalan', $s->fresh()->status);

        $hasil = $layanan->kembalikan($s->fresh(), $this->owner, $data + ['bayar_sisa' => ['metode' => 'qris']]);

        // Tagihan Rp310.000: Rp200.000 dari deposit (deposit kembali Rp0) + Rp110.000 QRIS → lunas
        $this->assertSame([310_000, 0, 0], [$hasil['transaksi']->total, $hasil['deposit_kembali'], $hasil['sisa']]);
        $this->assertSame(['selesai', 10_000, 300_000, 200_000], [$hasil['sewa']->status, $hasil['sewa']->denda, $hasil['sewa']->biaya_kerusakan, $hasil['sewa']->deposit_dipotong]);
        $this->assertSame('servis', $this->box->fresh()->status); // ada kelengkapan hilang → diperiksa dulu
        $this->assertSame(0, (int) KasMutasi::where('shift_id', $this->shift->id)->whereIn('jenis', ['deposit_masuk', 'deposit_keluar'])->sum('jumlah'));
        $this->assertSame(2, (int) $this->penyewa()->riwayat()['sewa'] + 1); // 1 sewa tercatat
    }

    public function test_telat_dalam_toleransi_tanpa_denda_dan_batal_kembalikan_deposit(): void
    {
        $layanan = app(PlayboxService::class);
        $s = $this->sewa($this->penyewa());
        Carbon::setTestNow($s->jatuh_tempo->copy()->addMinutes(20));
        $this->assertSame(0, $layanan->hitungDenda($s)['denda']);

        $layanan->batal($s, $this->owner, 'Salah unit');
        $this->assertSame(['batal', 'tersedia'], [$s->fresh()->status, $this->box->fresh()->status]);
        $this->assertSame('dibatalkan', Transaksi::find($s->transaksi_id)->status);
        $this->assertSame(0, app(ShiftService::class)->ringkasan($this->shift)['deposit']);
    }

    public function test_daftar_hitam_butuh_supervisor_owner(): void
    {
        $p = $this->penyewa();
        $p->update(['daftar_hitam' => true, 'alasan_daftar_hitam' => 'Stik hilang tidak diganti']);

        try {
            $this->sewa($p);
            $this->fail('Daftar hitam tanpa persetujuan seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('DAFTAR HITAM', $e->getMessage());
        }

        $s = $this->sewa($p, ['setuju_daftar_hitam' => true]); // owner boleh
        $this->assertSame('berjalan', $s->status);
    }
}
