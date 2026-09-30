<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;

/**
 * php artisan backup:buat   -> backup database + unggahan, lalu hapus backup lama sesuai retensi
 * Dijadwalkan otomatis setiap hari (routes/console.php).
 */
class BuatBackup extends Command
{
    protected $signature = 'backup:buat {--label= : Tambahan nama file}';

    protected $description = 'Backup database & file unggahan ke storage/app/private/backup';

    public function handle(BackupService $backup): int
    {
        try {
            $nama = $backup->buat($this->option('label'));
        } catch (Throwable $e) {
            report($e);
            $this->error('Backup gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup dibuat: {$nama} (".Number::fileSize(filesize($backup->path($nama))).')');

        $terhapus = $backup->bersihkan();

        if ($terhapus > 0) {
            $this->line("{$terhapus} backup lama dihapus (retensi {$backup->retensiHari()} hari).");
        }

        return self::SUCCESS;
    }
}
