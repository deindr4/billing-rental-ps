<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Filament\Resources\Iklan\Pages\CreateIklan;
use App\Livewire\Operator\JadwalBooking;
use App\Livewire\Operator\Lounge;
use App\Livewire\Operator\MulaiSesi;
use App\Livewire\Publik\BookingPortal;
use App\Models\Booking;
use App\Models\Cabang;
use App\Models\Iklan;
use App\Models\Pengaturan;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\ShiftService;
use App\Services\Publik\BillboardService;
use App\Services\Publik\BookingService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PublikTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $cabang;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);

        Pengaturan::simpan('booking.aktif', true, $this->cabang->id);
        Pengaturan::simpan('booking.jam_buka', '10:00', $this->cabang->id);
        Pengaturan::simpan('booking.jam_tutup', '23:00', $this->cabang->id);
        Pengaturan::simpan('booking.jeda_menit', 0, $this->cabang->id);
    }

    private function besok(string $jam): Carbon
    {
        return Carbon::parse(today()->addDay()->toDateString().' '.$jam);
    }

    public function test_billboard_butuh_kunci_dan_menampilkan_unit(): void
    {
        $url = BillboardService::url($this->cabang);

        // Kas belum dibuka -> rental tutup, jam operasional tampil
        $this->get($url)->assertOk()
            ->assertSee('TV 1 - PS5')
            ->assertSee('Rental sedang tutup')
            ->assertSee('10:00–23:00')
            ->assertDontSee('Siap dimainkan')
            ->assertSee('width=device-width', false)      // tampilan HP
            ->assertSee('Booking online sekarang')
            ->assertDontSee('Menu F&amp;B', false);

        // Kas dibuka -> buka
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
        $this->get($url)->assertSee('Siap dimainkan')->assertDontSee('Rental sedang tutup');

        $this->get('/billboard/DGH1?k=salah')->assertNotFound();
        $this->get('/billboard/DGH1')->assertNotFound();
    }

    public function test_iklan_tayang_sesuai_periode_lalu_terhapus_otomatis(): void
    {
        $this->actingAs($this->owner);
        Storage::fake('local');

        Livewire::test(CreateIklan::class)
            ->fillForm([
                'berkas' => UploadedFile::fake()->image('promo.jpg', 1600, 800),
                'judul' => 'Promo Warung Sebelah',
                'tautan' => 'https://wa.me/6281200001111',
                'mulai_pada' => now()->subHour(),
                'selesai_pada' => now()->addDays(3),
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $iklan = Iklan::firstOrFail();
        $this->assertSame(1200, $iklan->lebar); // dikompres ke maks 1200px
        $this->get($iklan->urlGambar())->assertOk()->assertHeader('Content-Type', 'image/webp');

        $url = BillboardService::url($this->cabang);
        $this->get($url)->assertSee('Promo Warung Sebelah');

        // Lewat masa tayang: tidak tampil, lalu dihapus jadwal per jam
        $this->travel(4)->days();
        $this->get($url)->assertDontSee('Promo Warung Sebelah');

        $this->assertSame(1, Iklan::hapusKedaluwarsa());
        $this->assertSame(0, Iklan::withoutGlobalScopes()->count());
        $this->get($iklan->urlGambar())->assertNotFound();
    }

    public function test_sesi_berjalan_tampil_dengan_sisa_waktu(): void
    {
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
        app(BillingService::class)->mulai(Unit::where('kode', 'TV2')->first(), $this->owner, ['mode' => 'durasi', 'durasi_menit' => 60]);

        $data = app(BillboardService::class)->data($this->cabang->load('tenant'));
        $this->assertSame(1, $data['terisi']);
        $this->assertSame(2, $data['kosong']);
        $this->assertNotNull(collect($data['matriks'])->firstWhere('kode', 'TV2')['berakhir_ms']);

        $this->get(BillboardService::url($this->cabang))->assertSee('Sisa waktu');
    }

    public function test_halaman_kasir_billboard(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Lounge::class)
            ->assertSee(BillboardService::url($this->cabang))
            ->set('pengumuman', 'Promo begadang 6 jam Rp50.000')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->get(BillboardService::url($this->cabang))->assertSee('Promo begadang 6 jam Rp50.000');
    }

    public function test_booking_slot_bentrok_dan_unit_otomatis(): void
    {
        $service = app(BookingService::class);
        $mulai = $this->besok('19:00');

        $b1 = $service->buat($this->cabang, ['nama' => 'Andi', 'telepon' => '081211112222', 'mulai' => $mulai->toDateTimeString(), 'durasi' => 120]);
        $b2 = $service->buat($this->cabang, ['nama' => 'Budi', 'telepon' => '081233334444', 'mulai' => $mulai->toDateTimeString(), 'durasi' => 120]);
        $b3 = $service->buat($this->cabang, ['nama' => 'Cici', 'telepon' => '081255556666', 'mulai' => $mulai->toDateTimeString(), 'durasi' => 60]);

        $this->assertCount(3, collect([$b1, $b2, $b3])->pluck('unit_id')->unique());
        $this->assertSame('menunggu', $b1->status);
        $this->assertMatchesRegularExpression('/^BK[A-Z2-9]{6}$/', $b1->kode);
        $this->assertGreaterThan(0, $b1->perkiraan_harga);

        // Semua unit penuh jam 19:00-20:00
        $slot = collect($service->slot($this->cabang, $mulai->copy()->startOfDay(), 60))->firstWhere('jam', '19:00');
        $this->assertSame(0, $slot['unit']->count());

        $this->expectException(BillingException::class);
        $service->buat($this->cabang, ['nama' => 'Dedi', 'telepon' => '081277778888', 'mulai' => $mulai->toDateTimeString(), 'durasi' => 60]);
    }

    public function test_booking_ditolak_jika_nonaktif_terlalu_dekat_atau_terlalu_banyak(): void
    {
        $service = app(BookingService::class);

        // Batas booking aktif per nomor (default 2)
        $service->buat($this->cabang, ['nama' => 'Andi', 'telepon' => '0812-1111-2222', 'mulai' => $this->besok('12:00')->toDateTimeString(), 'durasi' => 60]);
        $service->buat($this->cabang, ['nama' => 'Andi', 'telepon' => '6281211112222', 'mulai' => $this->besok('14:00')->toDateTimeString(), 'durasi' => 60]);

        try {
            $service->buat($this->cabang, ['nama' => 'Andi', 'telepon' => '081211112222', 'mulai' => $this->besok('16:00')->toDateTimeString(), 'durasi' => 60]);
            $this->fail('Seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('booking aktif', $e->getMessage());
        }

        // Kasir tetap boleh
        $kasir = $service->buat($this->cabang, ['nama' => 'Andi', 'telepon' => '081211112222', 'mulai' => $this->besok('16:00')->toDateTimeString(), 'durasi' => 90], $this->owner);
        $this->assertSame('dikonfirmasi', $kasir->status);

        Pengaturan::simpan('booking.aktif', false, $this->cabang->id);
        $this->expectException(BillingException::class);
        $service->buat($this->cabang, ['nama' => 'Eko', 'telepon' => '081299990000', 'mulai' => $this->besok('18:00')->toDateTimeString(), 'durasi' => 60]);
    }

    public function test_sesi_berjalan_membuat_unit_tidak_tersedia(): void
    {
        $this->travelTo(today()->setTime(15, 0));
        Pengaturan::simpan('booking.min_menit_sebelum', 0, $this->cabang->id);

        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);
        app(BillingService::class)->mulai(Unit::where('kode', 'TV2')->first(), $this->owner, ['mode' => 'durasi', 'durasi_menit' => 120]);

        $slot = collect(app(BookingService::class)->slot($this->cabang, today(), 60));
        $this->assertFalse($slot->firstWhere('jam', '16:00')['unit']->contains('kode', 'TV2'));
        $this->assertTrue($slot->firstWhere('jam', '17:00')['unit']->contains('kode', 'TV2'));
    }

    public function test_portal_booking_publik(): void
    {
        Livewire::test(BookingPortal::class, ['kode' => 'DGH1'])
            ->set('tanggal', today()->addDay()->toDateString())
            ->set('durasi', 120)
            ->call('pilihJam', '20:00')
            ->set('nama', 'Raka')
            ->set('telepon', '081288921102')
            ->call('pesan')
            ->assertHasNoErrors()
            ->assertSee('Kode booking')
            ->assertSee('Menunggu konfirmasi');

        $b = Booking::firstOrFail();
        $this->assertSame('online', $b->sumber);
        $this->assertSame('20:00', $b->mulai_pada->format('H:i'));

        // Cek booking dengan kode + nomor, lalu batalkan
        Livewire::test(BookingPortal::class, ['kode' => 'DGH1'])
            ->set('modeCek', true)->set('cekKode', strtolower($b->kode))->set('cekTelepon', '6281288921102')
            ->call('cek')->assertHasNoErrors()->assertSee($b->kode)
            ->call('batalkan');

        $this->assertSame('batal', $b->fresh()->status);

        $this->get('/booking/DGH1')->assertOk()->assertSee('Booking online');
        $this->get('/booking/TIDAKADA')->assertNotFound();
    }

    public function test_kasir_konfirmasi_checkin_dan_kedaluwarsa(): void
    {
        $this->actingAs($this->owner);
        app(ShiftService::class)->buka($this->owner, $this->cabang, 0);

        $service = app(BookingService::class);
        $b = $service->buat($this->cabang, ['nama' => 'Andi', 'telepon' => '081211112222', 'mulai' => $this->besok('19:00')->toDateTimeString(), 'durasi' => 120]);

        $lw = Livewire::test(JadwalBooking::class)
            ->set('tanggal', $b->mulai_pada->toDateString())
            ->assertSee('Andi')
            ->call('konfirmasi', $b->id);

        $this->assertSame('dikonfirmasi', $b->fresh()->status);

        // Hari H: pelanggan datang -> Mulai Rental terisi dari booking -> booking jadi checkin
        $this->travelTo($b->mulai_pada->copy()->subMinutes(5));
        $lw->call('checkin', $b->id)->assertDispatched('buka-mulai-sesi');

        Livewire::test(MulaiSesi::class)
            ->call('bukaUntuk', $b->unit_id, 'Andi', null, 120, $b->id)
            ->assertSet('durasiMenit', 120)
            ->assertSet('pelanggan', 'Andi')
            ->call('simpan')
            ->assertHasNoErrors();

        $b->refresh();
        $this->assertSame('checkin', $b->status);
        $this->assertNotNull($b->sesi_id);

        // Booking lain yang tidak datang melewati toleransi
        $telat = $service->buat($this->cabang, ['nama' => 'Budi', 'telepon' => '081233334444', 'mulai' => now()->addHour()->startOfHour()->toDateTimeString(), 'durasi' => 60], $this->owner);
        $this->travelTo($telat->mulai_pada->copy()->addMinutes(20));
        $this->assertSame(1, $service->tandaiKedaluwarsa());
        $this->assertSame('tidak_datang', $telat->fresh()->status);
    }
}
