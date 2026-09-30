<?php

namespace Database\Seeders;

use App\Models\Cabang;
use App\Models\Game;
use App\Models\KategoriUnit;
use App\Models\PaketHarga;
use App\Models\Pengaturan;
use App\Models\Tenant;
use App\Models\TipeKonsol;
use App\Models\Unit;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('kode', 'DGH')->firstOrFail();
        $cabang = Cabang::where('tenant_id', $tenant->id)->where('kode', 'DGH1')->firstOrFail();

        $tenancy = app(Tenancy::class);
        $tenancy->set($tenant->id, $cabang->id);

        // Tipe konsol
        $ps4 = TipeKonsol::firstOrCreate(['kode' => 'PS4'], ['nama' => 'PlayStation 4', 'urutan' => 1]);
        $ps5 = TipeKonsol::firstOrCreate(['kode' => 'PS5'], ['nama' => 'PlayStation 5', 'urutan' => 2]);

        // Kategori unit
        $reg = KategoriUnit::firstOrCreate(['kode' => 'REG'], ['nama' => 'Reguler', 'urutan' => 1]);
        $vip = KategoriUnit::firstOrCreate(['kode' => 'VIP'], ['nama' => 'VIP', 'urutan' => 2]);

        // Unit contoh
        $units = [
            ['kode' => 'TV1', 'nama' => 'TV 1 - PS5', 'tipe' => $ps5, 'kategori' => $vip, 'urutan' => 1],
            ['kode' => 'TV2', 'nama' => 'TV 2 - PS4', 'tipe' => $ps4, 'kategori' => $reg, 'urutan' => 2],
            ['kode' => 'TV3', 'nama' => 'TV 3 - PS4', 'tipe' => $ps4, 'kategori' => $reg, 'urutan' => 3],
        ];

        foreach ($units as $data) {
            Unit::firstOrCreate(
                ['kode' => $data['kode']],
                [
                    'nama' => $data['nama'],
                    'tipe_konsol_id' => $data['tipe']->id,
                    'kategori_unit_id' => $data['kategori']->id,
                    'lokasi' => 'Lantai 1',
                    'urutan' => $data['urutan'],
                ]
            );
        }

        // Katalog game
        $games = collect(['EA Sports FC 25', 'eFootball 2025', 'Tekken 8', 'GTA V', 'God of War Ragnarok'])
            ->map(fn (string $nama) => Game::firstOrCreate(['nama' => $nama]));

        Unit::all()->each(fn (Unit $unit) => $unit->games()->syncWithoutDetaching($games->pluck('id')));

        // Paket harga
        $paket = [
            ['nama' => 'Per Jam PS4', 'jenis' => PaketHarga::JENIS_PER_JAM, 'tipe' => $ps4, 'durasi' => null, 'harga' => 8000, 'urutan' => 1],
            ['nama' => 'Per Jam PS5', 'jenis' => PaketHarga::JENIS_PER_JAM, 'tipe' => $ps5, 'durasi' => null, 'harga' => 16000, 'urutan' => 2],
            ['nama' => 'Paket 3 Jam PS4', 'jenis' => PaketHarga::JENIS_PAKET, 'tipe' => $ps4, 'durasi' => 180, 'harga' => 20000, 'urutan' => 3],
            ['nama' => 'Paket 3 Jam PS5', 'jenis' => PaketHarga::JENIS_PAKET, 'tipe' => $ps5, 'durasi' => 180, 'harga' => 42000, 'urutan' => 4],
        ];

        foreach ($paket as $p) {
            PaketHarga::firstOrCreate(
                ['nama' => $p['nama']],
                [
                    'jenis' => $p['jenis'],
                    'tipe_konsol_id' => $p['tipe']->id,
                    'durasi_menit' => $p['durasi'],
                    'harga' => $p['harga'],
                    'urutan' => $p['urutan'],
                ]
            );
        }

        // Pengaturan default tenant
        $default = [
            'open_billing.blok_menit' => 15,
            'open_billing.toleransi_menit' => 5,
            'open_billing.minimal_menit' => 60,
            'tv.posisi_timer' => 'kanan_atas',
            'tv.durasi_bypass_menit' => 15,
            'tv.peringatan_menit' => 5,
            'tv.transparansi_lock' => 85,
            'pause.maksimal_menit' => 30,
            'pause.maksimal_kali' => 2,
        ];

        foreach ($default as $kunci => $nilai) {
            if (Pengaturan::ambil($kunci) === null) {
                Pengaturan::simpan($kunci, $nilai);
            }
        }

        $tenancy->clear();
    }
}
