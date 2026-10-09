<?php

namespace App\Console\Commands;

use App\Support\Bahasa;
use App\Support\KunciTerjemahan;
use Illuminate\Console\Command;

/**
 * php artisan bahasa:cek                 jumlah kalimat yang belum diterjemahkan per bahasa
 * php artisan bahasa:cek --area=kasir --kode=en --json   daftar kalimat yang belum ada (JSON, untuk diterjemahkan)
 * php artisan bahasa:cek --yatim         terjemahan yang kalimatnya sudah tidak dipakai di kode
 */
class CekBahasa extends Command
{
    protected $signature = 'bahasa:cek {--area= : kasir | admin | umum} {--kode= : en | ms | th | vi | fil} {--json} {--yatim}';

    protected $description = 'Periksa kelengkapan terjemahan (lang/app/{area}/{kode}.json)';

    public function handle(): int
    {
        $perArea = KunciTerjemahan::perArea();
        $kodeDaftar = $this->option('kode') ? [$this->option('kode')] : array_diff(array_keys(Bahasa::DAFTAR), [Bahasa::SUMBER]);
        $areaDaftar = $this->option('area') ? [$this->option('area')] : array_keys($perArea);
        $kurang = 0;

        foreach ($kodeDaftar as $kode) {
            $ada = KunciTerjemahan::terjemahan($kode);

            if ($this->option('yatim')) {
                $yatim = array_diff(array_keys($ada), KunciTerjemahan::semua());
                $this->line("{$kode}: ".count($yatim).' tidak terpakai');
                foreach ($yatim as $k) {
                    $this->line('  - '.$k);
                }

                continue;
            }

            foreach ($areaDaftar as $area) {
                $belum = array_values(array_filter($perArea[$area] ?? [], fn ($k) => ! array_key_exists($k, $ada)));
                $kurang += count($belum);

                if ($this->option('json')) {
                    $this->line(json_encode(array_fill_keys($belum, ''), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                } else {
                    $this->line(sprintf('%-4s %-6s %4d kalimat, %4d belum', $kode, $area, count($perArea[$area] ?? []), count($belum)));
                }
            }
        }

        return $kurang === 0 ? self::SUCCESS : self::FAILURE;
    }
}
