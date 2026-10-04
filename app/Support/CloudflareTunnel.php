<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Cloudflare Tunnel di PC rental (pemasangan Windows): akses aplikasi dari internet lewat domain sendiri
 * tanpa membuka port router. cloudflared.exe & layanan "BillingPS-Tunnel" dipasang installer; di sini hanya
 * menyimpan token & menyalakan / mematikan layanannya (Apache berjalan sebagai LocalSystem, boleh memakai nssm).
 *
 * Struktur: <root>\app (base_path), <root>\runtime\nssm.exe, <root>\data\cloudflared\token.txt
 */
class CloudflareTunnel
{
    public const LAYANAN = 'BillingPS-Tunnel';

    public function __construct(private ?string $root = null)
    {
        $this->root ??= dirname(base_path());
    }

    /** Hanya di pemasangan Windows hasil installer (ada nssm & cloudflared) */
    public function tersedia(): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            && File::exists($this->jalur('runtime/nssm.exe'))
            && File::exists($this->jalur('runtime/cloudflared/cloudflared.exe'));
    }

    public function fileToken(): string
    {
        return $this->jalur('data/cloudflared/token.txt');
    }

    public function adaToken(): bool
    {
        return File::exists($this->fileToken()) && trim(File::get($this->fileToken())) !== '';
    }

    /**
     * Ambil token dari isian: token saja, atau seluruh perintah dari dashboard Cloudflare
     * ("cloudflared.exe service install eyJ..." / "cloudflared tunnel run --token eyJ...").
     * Token = base64 JSON {a: akun, t: id tunnel, s: rahasia}. Return null bila tidak valid.
     */
    public static function ambilToken(string $isian): ?string
    {
        foreach (preg_split('/\s+/', trim($isian)) as $bagian) {
            $bagian = trim($bagian, "\"'");

            if (strlen($bagian) < 50 || ! preg_match('/^[A-Za-z0-9+\/=_-]+$/', $bagian)) {
                continue;
            }

            $json = json_decode((string) base64_decode(strtr($bagian, '-_', '+/'), true), true);

            if (is_array($json) && filled($json['a'] ?? null) && filled($json['t'] ?? null) && filled($json['s'] ?? null)) {
                return $bagian;
            }
        }

        return null;
    }

    /** ID tunnel dari token tersimpan (untuk ditampilkan; rahasia tidak pernah ditampilkan) */
    public function idTunnel(): ?string
    {
        if (! $this->adaToken()) {
            return null;
        }

        $json = json_decode((string) base64_decode(strtr(trim(File::get($this->fileToken())), '-_', '+/')), true);

        return is_array($json) ? ($json['t'] ?? null) : null;
    }

    /** running | stopped | starting | stopping | tidak_terpasang | tidak_diketahui */
    public function status(): string
    {
        $hasil = Process::timeout(15)->run(['sc.exe', 'query', self::LAYANAN]);

        if (! $hasil->successful()) {
            return str_contains($hasil->output().$hasil->errorOutput(), '1060') ? 'tidak_terpasang' : 'tidak_diketahui';
        }

        return match (true) {
            str_contains($hasil->output(), 'RUNNING') => 'running',
            str_contains($hasil->output(), 'START_PENDING') => 'starting',
            str_contains($hasil->output(), 'STOP_PENDING') => 'stopping',
            str_contains($hasil->output(), 'STOPPED') => 'stopped',
            default => 'tidak_diketahui',
        };
    }

    /** Simpan token lalu nyalakan layanan (otomatis menyala saat PC dinyalakan). Return pesan error / null. */
    public function aktifkan(string $token): ?string
    {
        File::ensureDirectoryExists(dirname($this->fileToken()));
        File::put($this->fileToken(), $token);

        foreach ([['set', self::LAYANAN, 'Start', 'SERVICE_AUTO_START'], ['restart', self::LAYANAN]] as $argumen) {
            $hasil = $this->nssm($argumen);

            if (! $hasil->successful()) {
                return trim($this->bersihkan($hasil->output().' '.$hasil->errorOutput())) ?: 'Layanan tunnel gagal dinyalakan.';
            }
        }

        return null;
    }

    /** Hentikan & jangan menyala otomatis lagi; token dihapus bila diminta */
    public function matikan(bool $hapusToken = false): void
    {
        $this->nssm(['stop', self::LAYANAN]);
        $this->nssm(['set', self::LAYANAN, 'Start', 'SERVICE_DEMAND_START']);

        if ($hapusToken) {
            File::delete($this->fileToken());
        }
    }

    /** Baris log terakhir cloudflared (membantu bila tunnel tidak tersambung) */
    public function logTerakhir(int $baris = 12): array
    {
        $file = $this->jalur('logs/'.self::LAYANAN.'.log');

        if (! File::exists($file)) {
            return [];
        }

        return array_slice(array_values(array_filter(preg_split('/\R/', $this->bersihkan(File::get($file))))), -$baris);
    }

    private function nssm(array $argumen)
    {
        return Process::timeout(60)->run(array_merge([$this->jalur('runtime/nssm.exe')], $argumen));
    }

    /** nssm menulis UTF-16; buang byte NUL supaya terbaca */
    private function bersihkan(string $teks): string
    {
        return str_replace("\0", '', $teks);
    }

    private function jalur(string $relatif): string
    {
        return $this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relatif);
    }
}
