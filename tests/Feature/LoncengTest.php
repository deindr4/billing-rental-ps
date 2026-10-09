<?php

namespace Tests\Feature;

use App\Jobs\KirimNotifikasi;
use App\Livewire\Operator\DaftarNotifikasi;
use App\Livewire\Operator\LoncengNotifikasi;
use App\Models\Cabang;
use App\Models\Notifikasi;
use App\Models\Playbox;
use App\Models\Produk;
use App\Models\SewaPlaybox;
use App\Models\User;
use App\Services\Billing\ShiftService;
use App\Services\Notifikasi\Lonceng;
use App\Services\Notifikasi\PengaturanNotifikasi;
use App\Services\Notifikasi\PeriksaLonceng;
use App\Services\Playbox\PlayboxService;
use App\Support\Audit;
use App\Support\HakAkses;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Lonceng notifikasi: penerima sesuai izin, status baca, teruskan ke Telegram/WA, pemeriksaan berkala */
class LoncengTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $kasir;

    private Cabang $cabang;

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
        HakAkses::siapkanPermission();
        HakAkses::siapkanTenant($this->cabang->tenant);

        $this->kasir = User::create(['tenant_id' => $this->cabang->tenant_id, 'name' => 'Sari', 'username' => 'sari',
            'email' => 'sari@billing.test', 'password' => 'password']);
        $this->kasir->assignRole('Kasir');
        $this->kasir->cabang()->attach($this->cabang->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function foto(string $nama = 'f.jpg'): string
    {
        $this->berkas[] = $f = UploadedFile::fake()->image($nama, 400, 300);

        return $f->getRealPath();
    }

    private function sewaPlaybox(): SewaPlaybox
    {
        $box = Playbox::create([
            'kode' => 'BOX-01', 'nama' => 'PS4 Slim',
            'kelengkapan' => [['nama' => 'Konsol', 'jumlah' => 1, 'harga_ganti' => 3_000_000]],
            'harga_jam' => 10_000, 'harga_hari' => 50_000, 'harga_minggu' => 300_000, 'denda_jam' => 5_000,
        ]);
        $layanan = app(PlayboxService::class);
        $p = $layanan->simpanPenyewa(['nama' => 'Budi', 'telepon' => '081234567890', 'nik' => '5171000000000001', 'alamat' => 'Kost Melati',
            'jenis_tempat' => 'kost', 'koordinat' => '-8.65, 115.22'], $this->cabang->tenant_id, $this->foto(), $this->foto());

        return $layanan->sewa($box, $p, $this->kasir, [
            'satuan' => 'hari', 'jumlah' => 1, 'deposit' => 0,
            'jaminan' => [['jenis' => 'identitas', 'keterangan' => 'KTP asli', 'nomor' => '5171000000000001']],
            'checklist' => [['nama' => 'Konsol', 'jumlah' => 1, 'kondisi' => 'baik']],
            'foto_kondisi' => [$this->foto()],
            'tanda_tangan' => 'data:image/png;base64,'.base64_encode(file_get_contents($this->foto('t.png'))),
        ]);
    }

    public function test_pembatalan_tampil_untuk_owner_bukan_kasir_dan_bukan_pelakunya(): void
    {
        $this->actingAs($this->kasir);
        Audit::catat('batal_transaksi', 'Batal TRX-001: salah input', userId: $this->kasir->id);

        $n = Notifikasi::firstOrFail();
        $this->assertSame(['batal_transaksi', 'pembatalan', 'penting'], [$n->jenis, $n->kelompok, $n->tingkat]);
        $this->assertStringContainsString('oleh Sari', $n->isi);

        $this->assertSame(1, Lonceng::jumlahBelumDibaca($this->owner, $this->cabang->id));
        $this->assertSame(0, Lonceng::jumlahBelumDibaca($this->kasir, $this->cabang->id), 'kasir tanpa izin batal & pelakunya sendiri');

        // Kejadian biasa tidak masuk lonceng
        Audit::catat('login', 'Login');
        $this->assertSame(1, Notifikasi::count());
    }

    public function test_status_baca_per_pengguna(): void
    {
        $a = Lonceng::kirim('selisih_kas', 'Selisih kas -Rp 5.000', userId: $this->kasir->id);
        Lonceng::kirim('stok_habis', 'Stok habis: Es Teh', userId: null);

        $this->assertSame(2, Lonceng::jumlahBelumDibaca($this->owner, $this->cabang->id));
        Lonceng::tandaiDibaca($this->owner, $a->id);
        $this->assertSame(1, Lonceng::jumlahBelumDibaca($this->owner, $this->cabang->id));
        $this->assertSame(1, Lonceng::jumlahBelumDibaca($this->kasir, $this->cabang->id), 'kasir hanya melihat stok');

        Lonceng::tandaiSemua($this->owner);
        $this->assertSame(0, Lonceng::jumlahBelumDibaca($this->owner, $this->cabang->id));

        Carbon::setTestNow(now()->addMinute());
        Lonceng::kirim('playbox_telat', 'BOX-01 telat', userId: null);
        $this->assertSame(1, Lonceng::jumlahBelumDibaca($this->owner, $this->cabang->id), 'yang baru setelah tandai semua tetap belum dibaca');
    }

    public function test_kunci_mencegah_notifikasi_ganda(): void
    {
        $this->assertNotNull(Lonceng::kirim('playbox_telat', kunci: 'x:1'));
        $this->assertNull(Lonceng::kirim('playbox_telat', kunci: 'x:1'));
        $this->assertNull(Lonceng::kirim('jenis_tidak_dikenal'));
        $this->assertSame(1, Notifikasi::count());
    }

    public function test_penting_diteruskan_ke_telegram_dan_wa_sesuai_pengaturan(): void
    {
        $setelan = PengaturanNotifikasi::untuk($this->cabang->id);
        $setelan->simpan('wa.aktif', true);
        $setelan->simpan('wa.tujuan', '6281100000000');

        Lonceng::kirim('selisih_kas', 'Selisih kas -Rp 5.000');
        Queue::assertPushed(KirimNotifikasi::class, 1);
        $this->assertDatabaseHas('notifikasi_log', ['saluran' => 'whatsapp', 'jenis' => 'lonceng']);

        // Peringatan / info tidak diteruskan
        Lonceng::kirim('batal_fnb', 'Batal F&B');
        Queue::assertPushed(KirimNotifikasi::class, 1);

        // Jenis penting yang tidak dicentang tidak diteruskan
        $setelan->simpan('lonceng.teruskan', ['playbox_telat']);
        Lonceng::kirim('selisih_kas', 'Selisih kas lagi');
        Queue::assertPushed(KirimNotifikasi::class, 1);
        Lonceng::kirim('playbox_telat', 'BOX telat');
        Queue::assertPushed(KirimNotifikasi::class, 2);
    }

    public function test_login_dan_pin_gagal_hanya_bila_berulang(): void
    {
        foreach (range(1, 4) as $i) {
            Audit::catat('login_gagal', "Login gagal ke-{$i}");
        }
        $this->assertSame(0, Notifikasi::count());

        Audit::catat('login_gagal', 'Login gagal ke-5');
        Audit::catat('login_gagal', 'Login gagal ke-6');
        $this->assertSame(1, Notifikasi::where('jenis', 'login_gagal')->count(), 'sekali per 15 menit');

        foreach (range(1, 3) as $i) {
            Audit::catat('pin_gagal', "PIN salah {$i}", userId: $this->kasir->id);
        }
        $this->assertSame(1, Notifikasi::where('jenis', 'pin_gagal')->count());
    }

    public function test_playbox_sewa_dan_telat_lewat_pemeriksaan_berkala(): void
    {
        Carbon::setTestNow(today()->setTime(9, 0));
        $this->actingAs($this->kasir);
        app(ShiftService::class)->buka($this->kasir, $this->cabang, 0);
        $sewa = $this->sewaPlaybox();

        $this->assertDatabaseHas('notifikasi', ['jenis' => 'playbox_baru', 'user_id' => $this->kasir->id]);

        // 2 jam sebelum jatuh tempo: peringatan; lewat jatuh tempo: penting, sekali per hari
        Carbon::setTestNow($sewa->jatuh_tempo->copy()->subHours(2));
        app(PeriksaLonceng::class)->jalankan();
        $this->assertDatabaseHas('notifikasi', ['jenis' => 'playbox_jatuh_tempo', 'subjek_id' => $sewa->id]);

        Carbon::setTestNow($sewa->jatuh_tempo->copy()->addHours(2));
        app(PeriksaLonceng::class)->jalankan();
        app(PeriksaLonceng::class)->jalankan();
        $this->assertSame(1, Notifikasi::where('jenis', 'playbox_telat')->count());

        Carbon::setTestNow($sewa->jatuh_tempo->copy()->addDay());
        app(PeriksaLonceng::class)->jalankan();
        $this->assertSame(2, Notifikasi::where('jenis', 'playbox_telat')->count(), 'diulang tiap hari selama belum kembali');

        // Kasir (playbox.kelola) melihat notifikasi telat
        $this->assertTrue(Lonceng::untuk($this->kasir, $this->cabang->id)->where('jenis', 'playbox_telat')->exists());
    }

    public function test_stok_habis_dan_shift_lupa_ditutup(): void
    {
        $produk = Produk::create(['nama' => 'Es Teh', 'harga_jual' => 4000, 'lacak_stok' => true, 'is_active' => true, 'stok_minimum' => 5]);
        DB::table('produk_stok')->updateOrInsert(['produk_id' => $produk->id, 'cabang_id' => $this->cabang->id],
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->cabang->tenant_id, 'qty' => 0]);
        $shift = app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
        $shift->update(['dibuka_pada' => now()->subHours(13)]);

        app(PeriksaLonceng::class)->jalankan();

        $this->assertDatabaseHas('notifikasi', ['jenis' => 'stok_habis', 'cabang_id' => $this->cabang->id]);
        $this->assertDatabaseHas('notifikasi', ['jenis' => 'shift_lama', 'subjek_id' => $shift->id]);
    }

    public function test_lonceng_dan_halaman_notifikasi(): void
    {
        $n = Lonceng::kirim('booking_baru', 'Booking BKABC123 · Andi', 'TV1 · Sab 10 Okt 19:00', userId: null);
        $this->actingAs($this->owner)->withSession(['cabang_id' => $this->cabang->id]);

        Livewire::test(LoncengNotifikasi::class)
            ->assertSee('Booking BKABC123 · Andi')->assertSee('Tandai semua dibaca')
            ->call('buka', $n->id)->assertRedirect(route('jadwal', absolute: false));
        $this->assertSame(0, Lonceng::jumlahBelumDibaca($this->owner, $this->cabang->id));

        $this->get(route('notifikasi'))->assertOk()->assertSee('Booking BKABC123 · Andi');
        Livewire::test(DaftarNotifikasi::class)->set('kelompok', 'stok')->assertDontSee('Booking BKABC123');

        // Bell ada di header kasir
        $this->get(route('laporan'))->assertSee('aria-label="Notifikasi"', false);
    }

    public function test_pengaturan_teruskan_di_admin(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(\App\Filament\Pages\PengaturanNotifikasi::class)
            ->assertSee('Lonceng notifikasi')->assertSee('Sewa Playbox telat belum kembali')
            ->set('data.lonceng_teruskan', ['playbox_telat'])
            ->call('simpan');

        $this->assertSame(['playbox_telat'], PengaturanNotifikasi::untuk($this->cabang->id)->loncengTeruskan());
        $this->get('/admin')->assertOk()->assertSee(route('notifikasi'));
    }
}
