<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\Unit;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GambarTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallpaper_unit_dikompres_webp_dan_file_lama_dihapus(): void
    {
        $this->seed(DatabaseSeeder::class);
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        app(Tenancy::class)->set($cabang->tenant_id, $cabang->id);
        Storage::fake('public');

        $disk = Storage::disk('public');
        $png = UploadedFile::fake()->image('besar.png', 3840, 2160)->storeAs('tenants/x/wallpaper', 'besar.png', 'public');
        $ukuranAsli = $disk->size($png);

        $unit = Unit::where('kode', 'TV1')->firstOrFail();
        $unit->update(['wallpaper' => $png]);

        $this->assertStringEndsWith('.webp', $unit->wallpaper);
        $this->assertFalse($disk->exists($png));
        $this->assertLessThan($ukuranAsli, $disk->size($unit->wallpaper));
        $this->assertSame(1920, getimagesize($disk->path($unit->wallpaper))[0]);

        // Ganti wallpaper: file lama ikut terhapus
        $lama = $unit->wallpaper;
        $baru = UploadedFile::fake()->image('baru.jpg', 1280, 720)->storeAs('tenants/x/wallpaper', 'baru.jpg', 'public');
        $unit->update(['wallpaper' => $baru]);

        $this->assertFalse($disk->exists($lama));
        $this->assertTrue($disk->exists($unit->wallpaper));

        // Hapus wallpaper: file ikut terhapus
        $akhir = $unit->wallpaper;
        $unit->update(['wallpaper' => null]);
        $this->assertFalse($disk->exists($akhir));
    }
}
