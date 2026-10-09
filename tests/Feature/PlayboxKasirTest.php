<?php

namespace Tests\Feature;

use App\Jobs\KirimNotifikasi;
use App\Livewire\Operator\KembaliPlaybox;
use App\Livewire\Operator\PlayboxSewa;
use App\Livewire\Operator\SewaPlayboxBaru;
use App\Models\Cabang;
use App\Models\NotifikasiLog;
use App\Models\Pengaturan;
use App\Models\Playbox;
use App\Models\SewaPlaybox;
use App\Models\User;
use App\Services\Billing\ShiftService;
use App\Services\Notifikasi\PengaturanNotifikasi;
use App\Services\Playbox\PlayboxService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Layar kasir Sewa Playbox: formulir 4 langkah, daftar (perpanjang/batal), pengembalian, surat sewa, berkas privat, pengingat WA */
class PlayboxKasirTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Cabang $cabang;

    private Playbox $box;

    /** @var array<int, UploadedFile> */
    private array $berkas = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->cabang->tenant_id);
        $this->actingAs($this->owner)->withSession(['cabang_id' => $this->cabang->id]);
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);

        $this->box = Playbox::create([
            'kode' => 'BOX-01', 'nama' => 'PS4 Slim', 'deposit_saran' => 150_000,
            'kelengkapan' => [['nama' => 'Konsol', 'jumlah' => 1, 'harga_ganti' => 3_000_000], ['nama' => 'Stik', 'jumlah' => 2, 'harga_ganti' => 300_000]],
            'harga_jam' => 10_000, 'harga_hari' => 50_000, 'harga_minggu' => 300_000, 'denda_jam' => 5_000,
        ]);
    }

    private function gambar(string $nama = 'f.jpg'): UploadedFile
    {
        return $this->berkas[] = UploadedFile::fake()->image($nama, 400, 300);
    }

    private function aktifkanWa(): void
    {
        $wa = PengaturanNotifikasi::untuk($this->cabang->id);
        $wa->simpan('wa.aktif', true);
        $wa->simpan('wa.tujuan', '6281100000000');
    }

    private function sewaLewatForm(): SewaPlaybox
    {
        $ttd = 'data:image/png;base64,'.base64_encode(file_get_contents($this->gambar('t.png')->getRealPath()));

        Livewire::test(SewaPlayboxBaru::class)
            ->set('cariHp', '081234567890')->call('cari')
            ->set('penyewa.nama', 'Budi')->set('penyewa.alamat', 'Kost Melati no 3')->set('penyewa.jenis_tempat', 'kost')->set('penyewa.nik', '5171000000000001')
            ->set('penyewa.koordinat', '-8.65, 115.22')
            ->set('fotoPenyewa', $this->gambar())->set('fotoKtp', $this->gambar())
            ->call('lanjut')->assertHasNoErrors()->assertSet('langkah', 2)
            ->call('pilihUnit', $this->box->id)->set('satuan', 'hari')->set('jumlah', 3)
            ->call('lanjut')->assertSet('langkah', 3)->assertSet('deposit', 150_000)
            ->set('jaminan.deposit', true)->set('fotoKondisi', [$this->gambar()])
            ->call('lanjut')->assertSet('langkah', 4)
            ->call('simpan')->assertHasErrors('tandaTangan')
            ->set('setujuSyarat', true)->set('tandaTangan', $ttd)
            ->call('simpan')->assertHasNoErrors()->assertRedirect();

        return SewaPlaybox::query()->latest('mulai_pada')->firstOrFail();
    }

    public function test_formulir_sewa_empat_langkah_dan_ringkasan_wa(): void
    {
        $this->aktifkanWa();

        Livewire::test(SewaPlayboxBaru::class)->call('lanjut')
            ->assertHasErrors(['penyewa.nama', 'penyewa.alamat', 'fotoPenyewa', 'fotoKtp']);

        $s = $this->sewaLewatForm();

        $this->assertSame(['berjalan', 'hari', 3, 150_000, 150_000], [$s->status, $s->satuan, $s->jumlah, $s->deposit, $s->transaksi->total]);
        $this->assertSame(['6281234567890', 'kost'], [$s->penyewa->telepon, $s->penyewa->jenis_tempat]);
        $this->assertCount(1, $s->foto_keluar);
        $this->assertSame(['identitas', 'deposit'], array_column($s->jaminan, 'jenis'));
        $this->assertSame('6281234567890', NotifikasiLog::withoutGlobalScopes()->where('jenis', 'sewa_playbox')->value('tujuan'));

        // Penyewa lama: cari HP → data terisi, foto tidak wajib lagi
        Livewire::test(SewaPlayboxBaru::class)->set('cariHp', '0812 3456 7890')->call('cari')
            ->assertSet('penyewa.nama', 'Budi')->assertSet('penyewaId', $s->penyewa_id)
            ->call('lanjut')->assertHasNoErrors()->assertSet('langkah', 2);
    }

    public function test_daftar_perpanjang_batal_dan_buka_pembayaran(): void
    {
        $s = $this->sewaLewatForm();
        $tempo = $s->jatuh_tempo;

        Livewire::withQueryParams(['bayar' => $s->transaksi_id])->test(PlayboxSewa::class)
            ->assertDispatched('buka-pembayaran')
            ->assertSee('BOX-01')->assertSee('Budi')
            ->call('bukaPerpanjang', $s->id)->set('satuan', 'hari')->set('jumlah', 1)->call('perpanjang')
            ->assertHasNoErrors();

        $s->refresh();
        $this->assertTrue($s->jatuh_tempo->equalTo($tempo->copy()->addDay()));

        Livewire::test(PlayboxSewa::class)->call('batal', $s->id, ['alasan' => 'Salah input']);
        $this->assertSame('batal', $s->fresh()->status);
        $this->assertSame('tersedia', $this->box->fresh()->status);
    }

    public function test_kembali_telat_dengan_stik_hilang_dipotong_deposit(): void
    {
        $s = $this->sewaLewatForm();
        Carbon::setTestNow($s->jatuh_tempo->copy()->addHours(2));

        $lw = Livewire::test(KembaliPlaybox::class, ['id' => $s->id])
            ->assertSet('denda', 10_000)
            ->set('checklist.1.jumlah', 1)
            ->assertSet('checklist.1.kondisi', 'hilang')->assertSet('checklist.1.biaya', 300_000)
            ->call('simpan')->assertHasNoErrors();

        $hasil = $lw->get('hasil');
        // Tagihan 310rb: 150rb dari deposit, kekurangan 160rb langsung dibayar tunai → lunas
        $this->assertSame([310_000, 0, 0], [$hasil['tagihan'], $hasil['deposit_kembali'], $hasil['sisa']]);
        $this->assertSame('lunas', \App\Models\Transaksi::findOrFail($hasil['transaksi_id'])->status);
        $this->assertSame(['selesai', 'servis'], [$s->fresh()->status, $this->box->fresh()->status]);
    }

    public function test_surat_sewa_dan_berkas_privat_hanya_tenant_sendiri(): void
    {
        $s = $this->sewaLewatForm();

        $this->get(route('playbox.surat', $s->id))->assertOk()->assertSee($s->nomor)->assertSee('5171', false)->assertSee('Budi');
        $this->get(route('playbox'))->assertOk();
        $this->get(route('playbox.baru'))->assertOk();
        $this->get(route('playbox.kembali', $s->id))->assertOk();
        $this->get(route('berkas.privat', $s->penyewa->foto_ktp))->assertOk();
        $this->get(route('berkas.privat', 'tenants/lain/penyewa/x.webp'))->assertNotFound();
        $this->get(route('berkas.privat', 'tenants/'.$this->cabang->tenant_id.'/../../.env'))->assertNotFound();
        $this->get('/admin/playbox')->assertOk();
        $this->get('/admin/penyewa')->assertOk();
    }

    public function test_pengingat_wa_sekali_sebelum_jatuh_tempo(): void
    {
        $s = $this->sewaLewatForm();
        $layanan = app(PlayboxService::class);

        // WA belum aktif → tidak ada pengingat
        Carbon::setTestNow($s->jatuh_tempo->copy()->subHours(2));
        $this->assertSame(0, $layanan->kirimPengingat());

        $this->aktifkanWa();
        Carbon::setTestNow($s->jatuh_tempo->copy()->subHours(5));
        $this->assertSame(0, $layanan->kirimPengingat(), 'di luar jendela 3 jam');

        Carbon::setTestNow($s->jatuh_tempo->copy()->subHours(2));
        $this->assertSame(1, $layanan->kirimPengingat());
        $this->assertSame(0, $layanan->kirimPengingat(), 'tidak dobel');
        $this->assertNotNull($s->fresh()->diingatkan_pada);
        Queue::assertPushed(KirimNotifikasi::class);

        // 0 jam = pengingat mati
        Pengaturan::simpan('playbox.pengingat_jam', 0, $this->cabang->id);
        SewaPlaybox::whereKey($s->id)->update(['diingatkan_pada' => null]);
        $this->assertSame(0, $layanan->kirimPengingat());
    }
}
