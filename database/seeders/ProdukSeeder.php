<?php

namespace Database\Seeders;

use App\Models\Cabang;
use App\Models\KategoriProduk;
use App\Models\Produk;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\StokService;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

/**
 * Contoh produk F&B + stok awal.
 * php artisan db:seed --class=ProdukSeeder
 */
class ProdukSeeder extends Seeder
{
    public function run(StokService $stok): void
    {
        $tenant = Tenant::where('kode', 'DGH')->firstOrFail();
        $cabang = Cabang::where('tenant_id', $tenant->id)->where('kode', 'DGH1')->firstOrFail();
        $owner = User::where('email', 'owner@billing.test')->first();

        $tenancy = app(Tenancy::class);
        $tenancy->set($tenant->id, $cabang->id);

        $kategori = [
            'Makanan' => KategoriProduk::firstOrCreate(['nama' => 'Makanan'], ['urutan' => 1]),
            'Minuman' => KategoriProduk::firstOrCreate(['nama' => 'Minuman'], ['urutan' => 2]),
            'Snack' => KategoriProduk::firstOrCreate(['nama' => 'Snack'], ['urutan' => 3]),
        ];

        // [kategori, nama, harga jual, harga pokok, stok awal, kode]
        $data = [
            ['Makanan', 'Indomie Goreng', 7000, 3500, 40, 'MI01'],
            ['Makanan', 'Indomie Goreng + Telur', 9000, 5000, 40, 'MI02'],
            ['Makanan', 'Indomie Kuah', 7000, 3500, 30, 'MI03'],
            ['Minuman', 'Es Teh', 4000, 1000, 100, 'MN01'],
            ['Minuman', 'Kopi Hitam', 5000, 1500, 60, 'MN02'],
            ['Minuman', 'Air Mineral 600ml', 4000, 2500, 48, 'MN03'],
            ['Snack', 'Chips', 1000, 600, 100, 'SN01'],
            ['Snack', 'Kacang', 3000, 1800, 30, 'SN02'],
        ];

        foreach ($data as $i => [$kat, $nama, $jual, $pokok, $awal, $kode]) {
            $produk = Produk::firstOrCreate(
                ['kode' => $kode],
                [
                    'kategori_produk_id' => $kategori[$kat]->id,
                    'nama' => $nama,
                    'harga_jual' => $jual,
                    'stok_minimum' => 5,
                    'urutan' => $i + 1,
                ]
            );

            if ($produk->wasRecentlyCreated) {
                $stok->catat($produk, $cabang->id, $awal, 'masuk', $owner, null, 'Stok awal', $pokok);
            }
        }

        $tenancy->clear();
    }
}
