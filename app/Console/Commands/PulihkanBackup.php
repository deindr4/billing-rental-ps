<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan backup:pulihkan backup-20261001-020000.zip
 * MENIMPA seluruh database. Sebelum memulihkan, backup kondisi saat ini dibuat otomatis.
 */
class PulihkanBackup extends Command
{
    protected $signature = 'backup:pulihkan {file : Nama file backup (lihat Admin → Platform → Backup)} {--tanpa-upload : Jangan pulihkan file unggahan} {--force : Tanpa konfirmasi}';

    protected $description = 'Pulihkan database dari file backup (menimpa data saat ini)';

    public function handle(BackupService $backup): int
    {
        $file = $this->argument('file');

        try {
            $backup->path($file);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Seluruh data saat ini akan DITIMPA oleh {$file}. Lanjutkan?")) {
            return self::FAILURE;
        }

        $this->line('Membuat backup kondisi saat ini dulu...');
        $pengaman = $backup->buat('sebelum-pulihkan');
        $this->line("Tersimpan: {$pengaman}");

        try {
            $backup->pulihkan($file, ! $this->option('tanpa-upload'));
        } catch (Throwable $e) {
            report($e);
            $this->error('Pemulihan gagal: '.$e->getMessage());
            $this->warn("Kondisi sebelumnya bisa dikembalikan dengan: php artisan backup:pulihkan {$pengaman} --force");

            return self::FAILURE;
        }

        $this->info("Database dipulihkan dari {$file}.");

        return self::SUCCESS;
    }
}
