<?php

namespace App\Services\Tv;

use App\Events\TvSegarkan;
use App\Models\PerangkatTv;
use App\Models\RilisApk;
use Illuminate\Support\Facades\Storage;

final class RilisApkService
{
    public const DISK = 'local';

    public const FOLDER = 'apk';

    /** Hitung ukuran & checksum file yang baru diunggah */
    public function metaFile(string $path): array
    {
        $absolut = Storage::disk(self::DISK)->path($path);

        return [
            'ukuran' => filesize($absolut),
            'sha256' => hash_file('sha256', $absolut),
        ];
    }

    /** Beri tahu semua TV aktif (semua tenant) untuk memeriksa update */
    public function beriTahuSemuaTv(): int
    {
        $jumlah = 0;

        PerangkatTv::withoutGlobalScopes()
            ->aktif()
            ->select('id')
            ->chunkById(200, function ($daftar) use (&$jumlah) {
                foreach ($daftar as $perangkat) {
                    TvSegarkan::dispatch($perangkat->id, 'update');
                    $jumlah++;
                }
            });

        return $jumlah;
    }

    public function hapusFile(RilisApk $rilis): void
    {
        Storage::disk(self::DISK)->delete($rilis->file);
    }
}
