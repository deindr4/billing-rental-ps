<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Support\HakAkses;
use Illuminate\Database\Seeder;

/**
 * Buat permission + izin bawaan role untuk semua tenant.
 * php artisan db:seed --class=HakAksesSeeder
 * Aman dijalankan ulang: izin baru ditambahkan ke role bawaan, ubahan owner tidak ditimpa.
 */
class HakAksesSeeder extends Seeder
{
    public function run(): void
    {
        $izinBaru = HakAkses::siapkanPermission();

        Tenant::all()->each(fn (Tenant $tenant) => HakAkses::siapkanTenant($tenant, $izinBaru));
    }
}
