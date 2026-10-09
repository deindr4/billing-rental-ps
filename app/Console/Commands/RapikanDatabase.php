<?php

namespace App\Console\Commands;

use App\Services\Sinkron\SinkronService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * php artisan db:rapikan              pangkas log lama, cache kedaluwarsa, antrean sinkron ganda (terjadwal tiap malam)
 * php artisan db:rapikan --analisa    + ANALYZE TABLE tabel besar (statistik index segar; terjadwal mingguan)
 *
 * Data transaksi, sesi, kas, stok & member TIDAK disentuh — hanya catatan teknis yang terus bertambah.
 */
class RapikanDatabase extends Command
{
    protected $signature = 'db:rapikan {--analisa : Perbarui statistik index tabel besar (ANALYZE TABLE)}';

    protected $description = 'Pangkas log lama & rapikan antrean supaya database tetap ringan';

    /** tabel => [kolom waktu, umur maksimal (hari)] */
    public const RETENSI = [
        'log_tv' => ['created_at', 120],          // riwayat perintah TV / bypass
        'notifikasi_log' => ['created_at', 90],    // riwayat kirim Telegram / WhatsApp
        'sync_log' => ['created_at', 30],          // riwayat proses sinkron
        'audit_log' => ['created_at', 400],        // log aktivitas & Analisa Pintar (lebih dari setahun)
        'failed_jobs' => ['failed_at', 30],
        'notifikasi' => ['created_at', 90],        // lonceng notifikasi (status baca ikut terhapus)
    ];

    /** Tabel besar yang statistik index-nya diperbarui (--analisa) */
    public const TABEL_BESAR = ['transaksi', 'transaksi_item', 'pembayaran', 'sesi', 'sesi_log', 'kas_mutasi', 'stok_mutasi',
        'member_mutasi', 'audit_log', 'log_tv', 'notifikasi_log', 'sync_antrean', 'absensi', 'booking', 'pengeluaran'];

    public function handle(SinkronService $sinkron): int
    {
        // Pemangkasan di tiap server sendiri: jangan ikut dikirim sebagai "hapus" ke server lain
        DB::statement('SET @sync_lewati = 1');

        try {
            foreach (self::RETENSI as $tabel => [$kolom, $hari]) {
                if (! Schema::hasTable($tabel)) {
                    continue;
                }

                $batas = now()->subDays($hari);
                $total = 0;

                // Bertahap supaya tabel tidak terkunci lama saat billing sedang dipakai
                do {
                    $n = DB::table($tabel)->where($kolom, '<', $batas)->limit(5000)->delete();
                    $total += $n;
                } while ($n === 5000);

                if ($total > 0) {
                    $this->line("{$tabel}: {$total} baris lebih dari {$hari} hari dihapus");
                }
            }

            // Cache database: baris kedaluwarsa tidak terhapus sendiri
            if (Schema::hasTable('cache')) {
                $n = DB::table('cache')->where('expiration', '<', now()->getTimestamp())->delete();
                $n > 0 && $this->line("cache: {$n} kedaluwarsa dihapus");
            }
        } finally {
            DB::statement('SET @sync_lewati = NULL');
        }

        $n = $sinkron->rapikanAntrean();
        $n > 0 && $this->line("sync_antrean: {$n} antrean ganda dirapikan");

        if ($this->option('analisa') && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $ada = array_values(array_filter(self::TABEL_BESAR, fn ($t) => Schema::hasTable($t)));
            DB::select('ANALYZE TABLE `'.implode('`, `', $ada).'`');
            $this->line('Statistik index diperbarui: '.count($ada).' tabel');
        }

        $this->info('Database dirapikan.');

        return self::SUCCESS;
    }
}
