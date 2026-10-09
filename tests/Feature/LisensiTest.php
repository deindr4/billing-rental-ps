<?php

namespace Tests\Feature;

use App\Livewire\Operator\SambutanLisensi;
use App\Models\Cabang;
use App\Models\Pengaturan;
use App\Models\User;
use App\Support\HakAkses;
use App\Support\Pengembang;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Menu Lisensi MIT terbuka untuk semua pengguna, berisi kontak Telegram & grup WA pengembangan */
class LisensiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_dan_kasir_bisa_membuka_lisensi_dengan_kontak_pengembang(): void
    {
        $this->seed(DatabaseSeeder::class);
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($cabang->tenant_id, $cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($cabang->tenant_id);

        HakAkses::siapkanPermission();
        HakAkses::siapkanTenant($cabang->tenant);
        $kasir = User::create(['tenant_id' => $cabang->tenant_id, 'name' => 'Sari', 'username' => 'sari',
            'email' => 'sari@billing.test', 'password' => 'password']);
        $kasir->assignRole('Kasir');
        $kasir->cabang()->attach($cabang->id);

        foreach ([User::where('email', 'owner@billing.test')->firstOrFail(), $kasir->refresh()] as $user) {
            $this->actingAs($user)->withSession(['cabang_id' => $cabang->id])
                ->get(route('lisensi'))->assertOk()
                ->assertSee('Lisensi MIT')->assertSee('Copyright © deindr4')
                ->assertSee(Pengembang::TELEGRAM)->assertSee(Pengembang::GRUP_WA)
                ->assertSee('Grup bertujuan untuk info pengembangan');

            // Menu sidebar tampil (di bawah Analisa Pintar untuk owner)
            $this->get(route('lisensi'))->assertSee('href="'.route('lisensi').'"', false);
        }
    }

    public function test_popup_lisensi_tampil_sekali_setelah_pasang_baru_di_windows(): void
    {
        $this->seed(DatabaseSeeder::class);
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        $owner = User::where('email', 'owner@billing.test')->firstOrFail();
        config(['app.mode' => 'local']);
        $buka = fn () => $this->actingAs($owner)->withSession(['cabang_id' => $cabang->id])->get(route('laporan'))->assertOk();

        // Tanpa penanda dari installer (pemasangan lama / update): tidak tampil
        $buka()->assertDontSee('Selamat datang di Delta Billing HuB');

        // Installer pasang baru menyalakan penanda → tampil sekali dengan Telegram & grup WhatsApp
        app(Tenancy::class)->clear(); // seperti konsol installer: belum ada tenant aktif
        $this->artisan('pasang:awal', ['--sambut-lisensi' => true])->assertSuccessful();
        $this->assertTrue(Pengaturan::ambil(SambutanLisensi::KUNCI, false, $cabang->id));
        $buka()->assertSee('Selamat datang di Delta Billing HuB')
            ->assertSee(Pengembang::TELEGRAM)->assertSee(Pengembang::GRUP_WA);
        $this->assertFalse(Pengaturan::ambil(SambutanLisensi::KUNCI, false, $cabang->id));

        // Dibuka lagi: tidak muncul
        $buka()->assertDontSee('Selamat datang di Delta Billing HuB');

        // Server cloud tidak pernah menampilkan popup
        config(['app.mode' => 'cloud']);
        Pengaturan::simpan(SambutanLisensi::KUNCI, true);
        $buka()->assertDontSee('Selamat datang di Delta Billing HuB');
    }
}
