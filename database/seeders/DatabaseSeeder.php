<?php

namespace Database\Seeders;

use App\Models\Cabang;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Data awal development.
     */
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        // Super admin platform (tanpa tenant)
        User::firstOrCreate(
            ['email' => 'admin@billing.test'],
            [
                'tenant_id' => null,
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
            ]
        )->forceFill(['is_super_admin' => true])->save(); // is_super_admin tidak fillable

        // Tenant pertama
        $tenant = Tenant::firstOrCreate(
            ['kode' => 'DGH'],
            [
                'nama' => 'Delta Gaming Hub',
                'status' => 'aktif',
            ]
        );

        $cabang = Cabang::firstOrCreate(
            ['tenant_id' => $tenant->id, 'kode' => 'DGH1'],
            [
                'nama' => 'Cabang Utama',
                'zona_waktu' => 'Asia/Makassar',
            ]
        );

        // Role bawaan per tenant
        $registrar->setPermissionsTeamId($tenant->id);

        foreach (['Owner', 'Supervisor', 'Kasir', 'Teknisi'] as $name) {
            Role::firstOrCreate([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        // Owner tenant
        $owner = User::firstOrCreate(
            ['email' => 'owner@billing.test'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Owner',
                'username' => 'owner',
                'password' => Hash::make('password'),
            ]
        );

        $owner->cabang()->syncWithoutDetaching([$cabang->id]);
        $owner->syncRoles(['Owner']);

        $registrar->forgetCachedPermissions();

        // Master data: tipe konsol, kategori, unit, game, paket harga, pengaturan
        $this->call(MasterDataSeeder::class);
    }
}
