<?php

namespace App\Console\Commands;

use App\Models\ServerSinkron;
use App\Services\Sinkron\SinkronService;
use App\Support\Sinkron\DaftarTabel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * php artisan sync                      jalankan dorong + tarik (server lokal; dijadwalkan tiap menit)
 * php artisan sync kirim-ulang          masukkan semua data ke antrean (sinkron pertama)
 * php artisan sync tarik-ulang          ambil ulang semua perubahan dari cloud
 * php artisan sync pasang-trigger       pasang ulang trigger (setelah ada tabel baru)
 * php artisan sync token "Nama rental"  (cloud) buat token server lokal baru
 * php artisan sync bersihkan            (cloud) hapus antrean lebih dari 30 hari
 */
class Sinkron extends Command
{
    protected $signature = 'sync {aksi=jalankan : jalankan|kirim-ulang|tarik-ulang|pasang-trigger|token|bersihkan} {nama? : Nama server (untuk token)} {--tenant= : Tenant id (untuk token)} {--diam : Tanpa output jika sinkron belum diatur}';

    protected $description = 'Sinkronisasi data server lokal <-> cloud';

    public function handle(SinkronService $sinkron): int
    {
        try {
            return match ($this->argument('aksi')) {
                'jalankan' => $this->jalankan($sinkron),
                'kirim-ulang' => $this->hasil('Masuk antrean: '.number_format($sinkron->kirimUlangSemua(), 0, ',', '.').' data. Jalankan: php artisan sync'),
                'tarik-ulang' => $this->hasil($this->tarikUlang($sinkron)),
                'pasang-trigger' => $this->hasil(DaftarTabel::pasangTrigger().' trigger sinkron terpasang.'),
                'token' => $this->token(),
                'bersihkan' => $this->hasil(DB::table('sync_antrean')->where('created_at', '<', now()->subDays(30))->delete().' antrean lama dihapus.'),
                default => $this->gagal('Aksi tidak dikenal.'),
            };
        } catch (Throwable $e) {
            return $this->gagal($e->getMessage());
        }
    }

    private function jalankan(SinkronService $sinkron): int
    {
        if (! $sinkron->siap()) {
            return $this->option('diam') ? self::SUCCESS : $this->gagal('Sinkron belum diatur/diaktifkan (Admin → Pengaturan → Sinkronisasi).');
        }

        $h = $sinkron->jalankan();

        return $this->hasil("Terkirim {$h['dorong']}, diterima {$h['tarik']}".($h['ditolak'] ? ", ditolak {$h['ditolak']}" : '').'.');
    }

    private function tarikUlang(SinkronService $sinkron): string
    {
        $sinkron->tarikUlangSemua();

        return 'Kursor direset. Semua perubahan akan diambil ulang pada sinkron berikutnya.';
    }

    private function token(): int
    {
        $nama = $this->argument('nama') ?: 'Server lokal';
        [$server, $token] = ServerSinkron::buat($nama, $this->option('tenant'));

        $this->info("Server sinkron \"{$server->nama}\" dibuat.");
        $this->line("Token (simpan, hanya tampil sekali): {$token}");

        return self::SUCCESS;
    }

    private function hasil(string $pesan): int
    {
        $this->info($pesan);

        return self::SUCCESS;
    }

    private function gagal(string $pesan): int
    {
        $this->error($pesan);

        return self::FAILURE;
    }
}
