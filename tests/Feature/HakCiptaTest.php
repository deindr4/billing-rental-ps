<?php

namespace Tests\Feature;

use App\Http\Middleware\JagaHakCipta;
use App\Models\Cabang;
use App\Models\Pengaturan;
use App\Support\HakCipta;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Syarat atribusi lisensi: "Copyright © deindr4" selalu tampil di halaman web, nama rental boleh ditambahkan */
class HakCiptaTest extends TestCase
{
    use RefreshDatabase;

    public function test_hak_cipta_tampil_dan_utuh(): void
    {
        $this->assertSame('Copyright © deindr4', HakCipta::asli());
        $this->assertTrue(JagaHakCipta::utuh());

        $this->get('/login')->assertOk()->assertSee('Copyright © deindr4');
    }

    public function test_teks_tambahan_rental_di_sebelahnya(): void
    {
        $this->seed(DatabaseSeeder::class);
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($cabang->tenant_id, $cabang->id);
        Pengaturan::simpan('tema.footer_tambahan', 'Delta Games Bali');

        $this->assertSame('Copyright © deindr4 · Delta Games Bali', HakCipta::baris());
    }

    public function test_kaki_dihapus_dari_tampilan_tetap_disisipkan(): void
    {
        Route::middleware('web')->get('/uji-tanpa-kaki', fn () => response('<html><body><h1>Halaman</h1></body></html>'));
        Route::middleware('web')->get('/uji-json', fn () => response()->json(['ok' => true]));

        $html = $this->get('/uji-tanpa-kaki')->assertOk()->getContent();
        $this->assertStringContainsString('Copyright © deindr4</div></body>', $html);
        $this->assertStringNotContainsString(JagaHakCipta::PESAN, $html);

        // Selain halaman HTML tidak disentuh
        $this->get('/uji-json')->assertExactJson(['ok' => true]);
    }
}
