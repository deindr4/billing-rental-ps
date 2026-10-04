<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cek versi baru aplikasi di GitHub Releases (repo publik, tanpa token).
 * Versi = file VERSION di akar aplikasi, format YYYY.MM.DD[.N]; rilis GitHub bertag "v<versi>".
 * Hasil di-cache 6 jam (batas API GitHub tanpa token: 60 permintaan / jam / IP). Hanya memberi tahu & menautkan
 * file unduhan yang cocok: installer .exe (PC Windows), paket .tar.gz (server cloud), APK TV.
 */
final class UpdateAplikasi
{
    private const CACHE = 'update-aplikasi:rilis';

    public static function versiSekarang(): string
    {
        $file = base_path('VERSION');

        return is_file($file) ? trim((string) file_get_contents($file)) : '0';
    }

    public function repo(): ?string
    {
        $repo = trim((string) config('billing.update.repo'));

        return preg_match('#^[\w.-]+/[\w.-]+$#', $repo) ? $repo : null;
    }

    /** windows (installer) | cloud | lainnya (dev) — menentukan file unduhan yang disarankan */
    public static function jenisPemasangan(): string
    {
        return match (true) {
            config('app.mode') === 'cloud' => 'cloud',
            is_file(dirname(base_path()).DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'nssm.exe') => 'windows',
            default => 'lainnya',
        };
    }

    /**
     * @return array{versi:string, sekarang:string, baru:bool, tanggal:?string, catatan:string, halaman:string,
     *               unduh:?array{nama:string, url:string, ukuran:int}, aset:array<int,array{nama:string, url:string, ukuran:int}>}|array{error:string}|null
     */
    public function cek(bool $paksa = false): ?array
    {
        if (! $repo = $this->repo()) {
            return null; // pemeriksaan update dimatikan (UPDATE_REPO kosong)
        }

        if ($paksa) {
            Cache::forget(self::CACHE);
        }

        $rilis = Cache::get(self::CACHE);

        if ($rilis === null) {
            try {
                $res = Http::timeout(10)
                    ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'BillingPS-Update'])
                    ->get("https://api.github.com/repos/{$repo}/releases/latest");

                if ($res->status() === 404) {
                    $rilis = ['error' => 'Belum ada rilis di GitHub.'];
                } elseif (! $res->successful()) {
                    $rilis = ['error' => 'GitHub menjawab '.$res->status().'.'];
                } else {
                    $rilis = $this->rapikan($res->json());
                }
            } catch (Throwable) {
                $rilis = ['error' => 'Tidak bisa menghubungi GitHub (cek internet).'];
            }

            // Gagal: coba lagi 15 menit lagi; berhasil: 6 jam
            Cache::put(self::CACHE, $rilis, isset($rilis['error']) ? 900 : 21600);
        }

        if (isset($rilis['error'])) {
            return $rilis;
        }

        $sekarang = self::versiSekarang();

        return $rilis + [
            'sekarang' => $sekarang,
            'baru' => version_compare($rilis['versi'], $sekarang, '>'),
            'unduh' => $this->unduhanCocok($rilis['aset']),
        ];
    }

    /** Hasil cek terakhir dari cache saja (tanpa menghubungi GitHub) — untuk dasbor */
    public function terakhir(): ?array
    {
        $rilis = $this->repo() ? Cache::get(self::CACHE) : null;

        if (! is_array($rilis) || isset($rilis['error'])) {
            return $rilis;
        }

        return $rilis + [
            'sekarang' => self::versiSekarang(),
            'baru' => version_compare($rilis['versi'], self::versiSekarang(), '>'),
            'unduh' => $this->unduhanCocok($rilis['aset']),
        ];
    }

    private function rapikan(array $r): array
    {
        return [
            'versi' => ltrim((string) ($r['tag_name'] ?? '0'), 'vV'),
            'tanggal' => $r['published_at'] ?? null,
            'catatan' => Str::limit((string) ($r['body'] ?? ''), 4000),
            'halaman' => (string) ($r['html_url'] ?? ''),
            'aset' => collect($r['assets'] ?? [])->map(fn ($a) => [
                'nama' => (string) $a['name'],
                'url' => (string) $a['browser_download_url'],
                'ukuran' => (int) ($a['size'] ?? 0),
            ])->values()->all(),
        ];
    }

    private function unduhanCocok(array $aset): ?array
    {
        $akhiran = match (self::jenisPemasangan()) {
            'windows' => '.exe',
            'cloud' => '.tar.gz',
            default => null,
        };

        return $akhiran ? collect($aset)->first(fn ($a) => str_ends_with(strtolower($a['nama']), $akhiran)) : null;
    }
}
