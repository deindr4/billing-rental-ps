<?php

namespace App\Services;

use App\Exceptions\BillingException;
use App\Services\Sinkron\SinkronService;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Backup seluruh database (semua tenant) + file unggahan ke satu file .zip:
 *   database.sql   dump SQL (struktur, data, trigger) — bisa dipulihkan lewat `php artisan backup:pulihkan`
 *                  atau `mysql nama_db < database.sql`
 *   uploads/...    isi storage/app/public (logo, wallpaper, foto nota, foto aset)
 *
 * Dibuat murni PHP (tanpa mysqldump) supaya jalan sama di Windows (Laragon) dan VPS.
 */
final class BackupService
{
    public const DISK = 'local';

    public const FOLDER = 'backup';

    /** Tabel yang hanya dicadangkan strukturnya */
    public const TANPA_DATA = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'sync_antrean'];

    /** Penanda akhir pernyataan SQL (baris komentar, aman untuk klien mysql) */
    private const PEMISAH = "\n-- ;;\n";

    public function retensiHari(): int
    {
        return max(1, (int) $this->pengaturanGlobal('backup.retensi_hari', 14));
    }

    public function sertakanUpload(): bool
    {
        return (bool) $this->pengaturanGlobal('backup.sertakan_upload', true);
    }

    /** Buat backup baru. Return nama file. */
    public function buat(?string $label = null): string
    {
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(self::FOLDER);

        $nama = 'backup-'.now()->format('Ymd-His').($label ? '-'.preg_replace('/[^a-z0-9]+/i', '-', $label) : '').'.zip';
        $zipPath = $disk->path(self::FOLDER.'/'.$nama);
        $sqlPath = $disk->path(self::FOLDER.'/.sementara-'.uniqid().'.sql');

        try {
            $this->dumpDatabase($sqlPath);

            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Tidak bisa membuat file zip backup.');
            }

            $zip->addFile($sqlPath, 'database.sql');
            $zip->addFromString('info.json', json_encode([
                'aplikasi' => config('app.name'),
                'mode' => config('app.mode'),
                'database' => DB::connection()->getDatabaseName(),
                'dibuat' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT));

            if ($this->sertakanUpload()) {
                $publik = Storage::disk('public')->path('');

                foreach (File::allFiles($publik) as $f) {
                    $zip->addFile($f->getPathname(), 'uploads/'.str_replace('\\', '/', $f->getRelativePathname()));
                }
            }

            $zip->close();
        } finally {
            @unlink($sqlPath);
        }

        Audit::catat('backup', "Backup dibuat: {$nama}", null, ['ukuran' => filesize($zipPath)], anomali: false, tenantId: null, cabangId: null);

        return $nama;
    }

    /** @return Collection<int, array{nama:string, ukuran:int, waktu:Carbon}> terbaru dulu */
    public function daftar(): Collection
    {
        $disk = Storage::disk(self::DISK);

        return collect($disk->files(self::FOLDER))
            ->filter(fn ($p) => str_ends_with($p, '.zip'))
            ->map(fn ($p) => [
                'nama' => basename($p),
                'ukuran' => $disk->size($p),
                'waktu' => Carbon::createFromTimestamp($disk->lastModified($p), config('app.timezone')),
            ])
            ->sortByDesc('waktu')
            ->values();
    }

    public function path(string $nama): string
    {
        $nama = basename($nama);

        if (! preg_match('/^backup-[\w-]+\.zip$/', $nama) || ! Storage::disk(self::DISK)->exists(self::FOLDER.'/'.$nama)) {
            throw new BillingException('File backup tidak ditemukan.');
        }

        return Storage::disk(self::DISK)->path(self::FOLDER.'/'.$nama);
    }

    public function hapus(string $nama): void
    {
        unlink($this->path($nama));
    }

    /** Hapus backup lebih tua dari retensi, tapi selalu sisakan minimal 3 terbaru */
    public function bersihkan(): int
    {
        $batas = now()->subDays($this->retensiHari());
        $terhapus = 0;

        foreach ($this->daftar()->slice(3) as $b) {
            if ($b['waktu']->lt($batas)) {
                $this->hapus($b['nama']);
                $terhapus++;
            }
        }

        return $terhapus;
    }

    /** Waktu backup terakhir (untuk peringatan di admin) */
    public function terakhir(): ?Carbon
    {
        return $this->daftar()->first()['waktu'] ?? null;
    }

    /**
     * Pulihkan database dari file backup. MENIMPA seluruh data. Hanya lewat artisan.
     * File unggahan ikut dipulihkan bila ada di dalam zip.
     */
    public function pulihkan(string $nama, bool $denganUpload = true): void
    {
        $zip = new ZipArchive;

        if ($zip->open($this->path($nama)) !== true) {
            throw new RuntimeException('File backup rusak.');
        }

        $sql = $zip->getFromName('database.sql');

        if ($sql === false) {
            throw new RuntimeException('database.sql tidak ada di dalam backup.');
        }

        $pdo = DB::connection()->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach (explode(self::PEMISAH, $sql) as $pernyataan) {
                $pernyataan = trim($pernyataan);

                if ($pernyataan !== '' && ! str_starts_with($pernyataan, '--')) {
                    $pdo->exec($pernyataan);
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        if ($denganUpload) {
            $publik = Storage::disk('public');

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);

                // Hanya gambar unggahan (logo, wallpaper, foto nota/aset) di jalur wajar: file lain (mis. .php)
                // di folder publik bisa dijalankan web server -> ditolak.
                if (str_starts_with($n, 'uploads/') && self::unggahanAman(substr($n, 8))) {
                    $publik->put(substr($n, 8), $zip->getFromIndex($i));
                }
            }
        }

        $zip->close();
    }

    /** Jalur file unggahan dari backup yang boleh dipulihkan ke disk publik */
    public static function unggahanAman(string $jalur): bool
    {
        // folder/folder/nama.webp — tanpa "..", tanpa titik di awal nama, ekstensi gambar saja
        return (bool) preg_match('#^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*\.(?:webp|png|jpe?g|gif)$#i', $jalur)
            && ! str_contains($jalur, '..');
    }

    /**
     * Pulihkan dari aplikasi web: backup kondisi sekarang dulu, pulihkan, lalu minta sinkron
     * mengambil ulang data cloud (yang lebih baru dari backup). Return nama backup pengaman.
     */
    public function pulihkanAman(string $nama, bool $denganUpload = true): string
    {
        $this->path($nama); // pastikan ada sebelum membuat backup pengaman
        @set_time_limit(0);

        $pengaman = $this->buat('sebelum-pulihkan');
        $this->pulihkan($nama, $denganUpload);

        $sinkron = app(SinkronService::class);

        if ($sinkron->siap()) {
            $sinkron->tarikUlangSemua();
        }

        return $pengaman;
    }

    /** Terima file backup unggahan (dari komputer lain / flashdisk). Return nama file tersimpan. */
    public function terimaUnggahan(string $pathSementara): string
    {
        $zip = new ZipArchive;

        if ($zip->open($pathSementara) !== true || $zip->locateName('database.sql') === false) {
            throw new BillingException('File bukan backup aplikasi ini (database.sql tidak ditemukan).');
        }

        $zip->close();

        $nama = 'backup-'.now()->format('Ymd-His').'-unggahan.zip';
        Storage::disk(self::DISK)->makeDirectory(self::FOLDER);
        copy($pathSementara, Storage::disk(self::DISK)->path(self::FOLDER.'/'.$nama));

        return $nama;
    }

    /* ---------------- Dump ---------------- */

    private function dumpDatabase(string $path): void
    {
        $pdo = DB::connection()->getPdo();
        $out = fopen($path, 'w');

        if (! $out) {
            throw new RuntimeException('Tidak bisa menulis file sementara backup.');
        }

        $tulis = fn (string $sql) => fwrite($out, $sql.';'.self::PEMISAH);

        fwrite($out, '-- Backup '.config('app.name').' '.now()->toDateTimeString()."\n");
        fwrite($out, "-- Pulihkan: php artisan backup:pulihkan <file>  atau  mysql <db> < database.sql\n\n");
        $tulis('SET NAMES utf8mb4');
        $tulis('SET FOREIGN_KEY_CHECKS=0');

        $tabel = collect($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM))->pluck(0);

        foreach ($tabel as $t) {
            $buat = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_NUM)[1];
            $tulis("DROP TABLE IF EXISTS `{$t}`");
            $tulis($buat);

            // Data sementara tidak perlu dipulihkan (sesi login, cache, antrean tugas & sinkron)
            if (in_array($t, self::TANPA_DATA, true)) {
                continue;
            }

            $kolom = null;
            $nilai = [];
            $stmt = $pdo->query("SELECT * FROM `{$t}`", PDO::FETCH_ASSOC);

            foreach ($stmt as $row) {
                $kolom ??= '`'.implode('`,`', array_keys($row)).'`';
                $nilai[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)).')';

                if (count($nilai) >= 200) {
                    $tulis("INSERT INTO `{$t}` ({$kolom}) VALUES ".implode(',', $nilai));
                    $nilai = [];
                }
            }

            if ($nilai !== []) {
                $tulis("INSERT INTO `{$t}` ({$kolom}) VALUES ".implode(',', $nilai));
            }
        }

        // Trigger (larangan hapus data transaksi, dll.)
        foreach ($pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $tr) {
            $tulis("DROP TRIGGER IF EXISTS `{$tr['Trigger']}`");
            $tulis("CREATE TRIGGER `{$tr['Trigger']}` {$tr['Timing']} {$tr['Event']} ON `{$tr['Table']}` FOR EACH ROW {$tr['Statement']}");
        }

        $tulis('SET FOREIGN_KEY_CHECKS=1');
        fclose($out);
    }

    /** Pengaturan tingkat platform (bukan per tenant): disimpan di backup/pengaturan.json */
    private function pengaturanGlobal(string $kunci, mixed $default): mixed
    {
        $disk = Storage::disk(self::DISK);
        $isi = $disk->exists(self::FOLDER.'/pengaturan.json')
            ? (json_decode($disk->get(self::FOLDER.'/pengaturan.json'), true) ?: [])
            : [];

        return $isi[$kunci] ?? $default;
    }

    public function simpanPengaturan(int $retensiHari, bool $sertakanUpload): void
    {
        Storage::disk(self::DISK)->put(self::FOLDER.'/pengaturan.json', json_encode([
            'backup.retensi_hari' => max(1, $retensiHari),
            'backup.sertakan_upload' => $sertakanUpload,
        ], JSON_PRETTY_PRINT));
    }
}
