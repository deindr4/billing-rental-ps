<?php

namespace Tests\Feature;

use App\Filament\Resources\Karyawan\Pages\CreateKaryawan;
use App\Filament\Resources\Karyawan\Pages\ListKaryawan;
use App\Models\Cabang;
use App\Models\Karyawan;
use App\Models\User;
use App\Support\HakAkses;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Data karyawan: profil, NIK & rekening terenkripsi, gaji, PIN absen, ambil dari pengguna */
class KaryawanTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Cabang $cabang;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@billing.test')->firstOrFail();
        $this->cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($this->cabang->tenant_id, $this->cabang->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->cabang->tenant_id);
        $this->actingAs($this->owner);
    }

    public function test_tambah_karyawan_tanpa_akun_dengan_data_terenkripsi_dan_pin(): void
    {
        Livewire::test(CreateKaryawan::class)
            ->fillForm([
                'nama' => 'Udin OB', 'jabatan' => 'OB', 'cabang_id' => $this->cabang->id,
                'nik' => '5171010101900001', 'no_rekening' => '1234567890', 'bank' => 'BCA', 'atas_nama' => 'Udin',
                'tanggal_masuk' => '2025-07-01', 'periode_gaji' => 'mingguan', 'upah_shift' => 80_000,
                'bonus_jenis' => 'nominal', 'pin_baru' => '4321',
            ])
            ->call('create')
            ->assertHasNoErrors();

        $k = Karyawan::where('nama', 'Udin OB')->firstOrFail();
        $this->assertSame(['5171010101900001', '1234567890', 'mingguan', 80_000], [$k->nik, $k->no_rekening, $k->periode_gaji, $k->upah_shift]);

        // Di database tidak tersimpan apa adanya
        $mentah = DB::table('karyawan')->where('id', $k->id)->first();
        $this->assertStringNotContainsString('5171010101900001', $mentah->nik);
        $this->assertTrue($k->cocokPin('4321'));
        $this->assertFalse($k->cocokPin('0000'));
        $this->assertNotNull($k->masaKerja());

        $this->get('/admin/karyawan')->assertOk()->assertSee('Udin OB')->assertDontSee('5171010101900001');
    }

    public function test_ambil_dari_pengguna_dan_pin_akun_login(): void
    {
        HakAkses::siapkanPermission();
        HakAkses::siapkanTenant($this->cabang->tenant);
        $kasir = User::create(['tenant_id' => $this->cabang->tenant_id, 'name' => 'Sari', 'username' => 'sari',
            'email' => 'sari@billing.test', 'password' => 'password', 'phone' => '0812']);
        $kasir->forceFill(['pin' => Hash::make('7777')])->save();
        $kasir->assignRole('Kasir');
        $kasir->cabang()->attach($this->cabang->id);

        Livewire::test(ListKaryawan::class)->callAction('impor');

        $k = Karyawan::where('user_id', $kasir->id)->firstOrFail();
        $this->assertSame(['Sari', 'Kasir', '0812', $this->cabang->id], [$k->nama, $k->jabatan, $k->telepon, $k->cabang_id]);
        $this->assertTrue($k->cocokPin('7777')); // memakai PIN akun login
        $this->assertTrue(Karyawan::where('user_id', $this->owner->id)->exists());

        // Diulang: tidak dobel
        $this->assertSame(0, ListKaryawan::imporDariPengguna());

        // Kasir tidak bisa membuka data karyawan
        $this->actingAs($kasir)->get('/admin/karyawan')->assertForbidden();
    }
}
