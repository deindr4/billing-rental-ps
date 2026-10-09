<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Filament\Pages\JadwalKaryawanPage;
use App\Filament\Resources\Absensi\Pages\EditAbsensi;
use App\Livewire\Operator\Absen;
use App\Livewire\Operator\BukaShift;
use App\Models\Absensi;
use App\Models\Cabang;
use App\Models\JadwalKaryawan;
use App\Models\Karyawan;
use App\Models\TemplateShift;
use App\Models\User;
use App\Services\Billing\ShiftService;
use App\Services\Karyawan\AbsensiService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Jadwal mingguan + absen PIN & foto selfie: terlambat, pulang cepat, lama kerja, koreksi owner */
class AbsensiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Cabang $cabang;

    private Karyawan $udin;

    private TemplateShift $pagi;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->cabang->tenant_id);
        $this->actingAs($this->owner);

        // Hari ini (bukan tanggal jauh): unggahan sementara Livewire kosong bila waktu dimajukan berhari-hari
        Carbon::setTestNow(today()->setTime(8, 0));
        $this->udin = Karyawan::create(['tenant_id' => $this->cabang->tenant_id, 'cabang_id' => $this->cabang->id, 'nama' => 'Udin', 'jabatan' => 'OB']);
        $this->udin->aturPin('4321');
        $this->pagi = TemplateShift::create(['tenant_id' => $this->cabang->tenant_id, 'nama' => 'Pagi', 'jam_mulai' => '10:00', 'jam_selesai' => '17:00', 'toleransi_menit' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function selfie(): UploadedFile
    {
        return UploadedFile::fake()->image('selfie.jpg', 480, 640);
    }

    public function test_jadwal_mingguan_disimpan_dari_admin(): void
    {
        Livewire::test(JadwalKaryawanPage::class)
            ->assertSee('Udin')
            ->set("jadwal.{$this->udin->id}.1", $this->pagi->id)
            ->call('samakan', $this->udin->id)
            ->set("jadwal.{$this->udin->id}.0", '') // Minggu libur
            ->call('simpan');

        $this->assertSame(6, JadwalKaryawan::where('karyawan_id', $this->udin->id)->count());
        $minggu = now()->copy()->next(Carbon::SUNDAY);
        $this->assertNull(app(AbsensiService::class)->jadwalHari($this->udin, $minggu));
        $this->assertSame('Pagi', app(AbsensiService::class)->jadwalHari($this->udin, $minggu->copy()->addDay())?->nama); // Senin
    }

    public function test_absen_masuk_terlambat_pulang_cepat_dan_lama_kerja(): void
    {
        JadwalKaryawan::create(['tenant_id' => $this->cabang->tenant_id, 'karyawan_id' => $this->udin->id, 'hari' => now()->dayOfWeek, 'template_shift_id' => $this->pagi->id]);
        $hari = today()->toDateString();

        Carbon::setTestNow(today()->setTime(10, 25));
        Livewire::test(Absen::class)
            ->assertSee('Udin')
            ->call('pilih', $this->udin->id)->assertSee('Pagi 10:00–17:00')
            ->set('pin', '0000')->set('foto', $this->selfie())->call('masuk'); // PIN salah
        $this->assertSame(0, Absensi::count());

        Livewire::test(Absen::class)->call('pilih', $this->udin->id)
            ->set('pin', '4321')->set('foto', $this->selfie())->call('masuk')->assertHasNoErrors();

        $a = Absensi::firstOrFail();
        $this->assertSame(25, $a->terlambat_menit);
        Storage::disk('public')->assertExists($a->foto_masuk);

        // Masuk dua kali ditolak
        try {
            app(AbsensiService::class)->masuk($this->udin, '4321', $this->selfie()->getRealPath(), $this->cabang->id);
            $this->fail('Masuk dua kali seharusnya ditolak');
        } catch (BillingException $e) {
            $this->assertStringContainsString('sudah absen masuk', $e->getMessage());
        }

        Carbon::setTestNow(today()->setTime(16, 30));
        Livewire::test(Absen::class)->call('pilih', $this->udin->id)->assertSee('Absen pulang')
            ->set('pin', '4321')->set('foto', $this->selfie())->call('pulang')->assertHasNoErrors();

        $a->refresh();
        $this->assertSame([365, 30], [$a->menit_kerja, $a->pulang_cepat_menit]);
        $this->assertSame(['hari_hadir' => 1, 'menit_kerja' => 365, 'terlambat_kali' => 1, 'terlambat_menit' => 25, 'belum_pulang' => 0],
            app(AbsensiService::class)->rekap($this->udin, now()->startOfMonth(), now()));

        // Owner mengoreksi jam masuk (mis. salah absen) → dihitung ulang
        Livewire::test(EditAbsensi::class, ['record' => $a->id])
            ->fillForm(['masuk_pada' => "{$hari} 10:05:00", 'pulang_pada' => "{$hari} 17:00:00", 'catatan' => 'Lupa absen, cek CCTV'])
            ->call('save')->assertHasNoErrors();
        $this->assertSame([0, 415, 0], [$a->fresh()->terlambat_menit, $a->fresh()->menit_kerja, $a->fresh()->pulang_cepat_menit]);
        $this->get('/admin/absensi')->assertOk()->assertSee('Udin');
    }

    public function test_kasir_buka_shift_diarahkan_absen(): void
    {
        $k = Karyawan::create(['tenant_id' => $this->cabang->tenant_id, 'user_id' => $this->owner->id, 'nama' => 'Owner']);

        Livewire::test(BukaShift::class)->set('kasAwal', 100_000)->call('simpan')
            ->assertRedirect(route('absen', ['k' => $k->id]));
        $this->assertNotNull(app(ShiftService::class)->terbukaDiCabang($this->cabang->id));
    }
}
