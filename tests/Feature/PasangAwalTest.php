<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\Pengaturan;
use App\Models\RilisApk;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** php artisan pasang:awal — dipanggil installer pada PC rental yang masih kosong */
class PasangAwalTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_rental_owner_superadmin_dan_aman_diulang(): void
    {
        $opsi = [
            '--rental' => 'Delta Gaming Hub', '--cabang' => 'Cabang Kota', '--zona' => 'Asia/Jakarta',
            '--owner-nama' => 'Bos Delta', '--owner-email' => 'Bos@Delta.id', '--owner-password' => 'rahasia123', '--pin' => '2468',
            '--url-lokal' => '192.168.1.10',
        ];

        $this->artisan('pasang:awal', $opsi)->assertSuccessful();
        $this->artisan('pasang:awal', $opsi)->assertSuccessful(); // ulang: tidak dobel

        $this->assertSame(1, Tenant::count());
        $tenant = Tenant::first();
        $this->assertSame('DGH', $tenant->kode);

        $cabang = Cabang::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('Cabang Kota', $cabang->nama);
        $this->assertSame('Asia/Jakarta', $cabang->zona_waktu);
        app(Tenancy::class)->set($tenant->id, $cabang->id);
        $this->assertSame('http://192.168.1.10', Pengaturan::ambil('server.url_lokal', null, $cabang->id));

        $owner = User::withoutGlobalScopes()->where('email', 'bos@delta.id')->firstOrFail();
        $this->assertTrue(Hash::check('rahasia123', $owner->password));
        $this->assertTrue(Hash::check('2468', $owner->pin));
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->assertTrue($owner->hasRole('Owner'));
        $this->assertTrue($owner->cabang()->whereKey($cabang->id)->exists());

        $su = User::withoutGlobalScopes()->where('is_super_admin', true)->firstOrFail();
        $this->assertSame('superadmin@billing.lokal', $su->email);
        $this->assertNull($su->tenant_id);
        $this->assertSame(1, User::withoutGlobalScopes()->where('is_super_admin', true)->count());

        // Owner bisa login & membuka pilih cabang
        $this->post('/login', [])->assertStatus(405); // login lewat Livewire; cukup pastikan akun aktif
        $this->assertTrue((bool) $owner->is_active);
    }

    public function test_validasi_dan_rilis_apk(): void
    {
        $this->artisan('pasang:awal', ['--rental' => 'X', '--owner-email' => 'salah', '--owner-password' => '123'])->assertFailed();
        $this->artisan('pasang:awal', ['--rental' => 'Rental OK', '--owner-email' => 'a@b.id', '--owner-password' => 'rahasia123', '--pin' => '12'])->assertFailed();

        Storage::fake('local');
        $dir = sys_get_temp_dir().'/apk-uji-'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/tv-agent.apk", 'PK-isi-apk');
        file_put_contents("{$dir}/versi.txt", "0.5.1 9\n");

        $this->artisan('pasang:awal', ['--apk' => "{$dir}/tv-agent.apk"])->assertSuccessful();
        $this->artisan('pasang:awal', ['--apk' => "{$dir}/tv-agent.apk"])->assertSuccessful(); // tidak dobel

        $this->assertSame(1, RilisApk::count());
        $r = RilisApk::first();
        $this->assertSame([9, '0.5.1', true], [$r->versi_kode, $r->versi_nama, (bool) $r->aktif]);
        $this->assertSame(hash('sha256', 'PK-isi-apk'), $r->sha256);
    }
}
