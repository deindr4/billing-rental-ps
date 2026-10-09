<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\PerangkatTv;
use App\Services\Sinkron\SinkronService;
use App\Support\Tenancy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Heartbeat TV tidak membanjiri antrean sinkron, antrean ganda dirapikan, log lama dipangkas, index tersedia */
class OptimasiDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private function tv(): PerangkatTv
    {
        $this->seed(DatabaseSeeder::class);
        $cabang = Cabang::where('kode', 'DGH1')->firstOrFail();
        // Trigger sudah dipasang migrasi (tanpa DDL di sini: DDL memutus transaksi RefreshDatabase)
        app(Tenancy::class)->set($cabang->tenant_id, $cabang->id);

        return PerangkatTv::create(['android_id' => 'tv-uji', 'merek' => 'Uji', 'model' => 'X', 'status' => 'aktif']);
    }

    private function antreanTv(PerangkatTv $tv): int
    {
        return DB::table('sync_antrean')->where('tabel', 'perangkat_tv')->where('row_id', $tv->id)->count();
    }

    public function test_heartbeat_tidak_masuk_antrean_perubahan_konfigurasi_tetap_masuk(): void
    {
        $tv = $this->tv();
        $awal = $this->antreanTv($tv);

        for ($i = 0; $i < 5; $i++) {
            PerangkatTv::withoutGlobalScopes()->whereKey($tv->id)->update(['terakhir_online' => now()->addSeconds($i), 'ip' => "10.0.0.{$i}", 'volume' => 20 + $i]);
        }
        $this->assertSame($awal, $this->antreanTv($tv), 'heartbeat tidak dicatat');

        PerangkatTv::withoutGlobalScopes()->whereKey($tv->id)->update(['merek' => 'Sony']);
        $this->assertSame($awal + 1, $this->antreanTv($tv), 'perubahan konfigurasi dicatat');
    }

    public function test_antrean_ganda_dirapikan_dan_log_lama_dipangkas_tanpa_ikut_antrean(): void
    {
        $tv = $this->tv();

        foreach (range(1, 6) as $i) {
            PerangkatTv::withoutGlobalScopes()->whereKey($tv->id)->update(['merek' => "Merek {$i}"]);
        }
        $this->assertGreaterThan(1, $this->antreanTv($tv));
        app(SinkronService::class)->rapikanAntrean();
        $this->assertSame(1, $this->antreanTv($tv), 'tersisa satu (terbaru)');

        $lama = (string) Str::uuid7();
        DB::table('notifikasi_log')->insert([
            ['id' => $lama, 'tenant_id' => $tv->tenant_id, 'saluran' => 'whatsapp', 'jenis' => 'uji', 'status' => 'terkirim', 'created_at' => now()->subDays(200), 'updated_at' => now()],
            ['id' => (string) Str::uuid7(), 'tenant_id' => $tv->tenant_id, 'saluran' => 'whatsapp', 'jenis' => 'uji', 'status' => 'terkirim', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $antrean = DB::table('sync_antrean')->count();

        $this->artisan('db:rapikan')->assertSuccessful();

        $this->assertDatabaseMissing('notifikasi_log', ['id' => $lama]);
        $this->assertSame(1, DB::table('notifikasi_log')->count());
        $this->assertSame(0, DB::table('sync_antrean')->where('aksi', 'hapus')->where('tabel', 'notifikasi_log')->count(), 'pemangkasan tidak dikirim ke server lain');
        $this->assertLessThanOrEqual($antrean, DB::table('sync_antrean')->count());
    }

    public function test_index_tabel_besar_tersedia(): void
    {
        $this->assertTrue(Schema::hasIndex('sync_antrean', 'sync_antrean_tabel_row_aksi_index'));
        $this->assertTrue(Schema::hasIndex('sesi', 'sesi_cabang_mulai_index'));
        $this->assertTrue(Schema::hasIndex('audit_log', 'audit_log_cabang_aksi_waktu_index'));
        $this->assertTrue(Schema::hasIndex('transaksi_item', 'transaksi_item_transaksi_jenis_index'));
    }
}
