<?php

namespace Tests\Feature;

use App\Models\Cabang;
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
}
