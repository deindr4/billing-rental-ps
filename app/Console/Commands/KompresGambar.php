<?php

namespace App\Console\Commands;

use App\Models\Pengaturan;
use App\Support\Gambar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Throwable;

/**
 * php artisan gambar:kompres            kompres gambar lama yang belum WebP / terlalu besar
 * php artisan gambar:kompres --yatim    sekaligus hapus file di tenants/ yang tidak dipakai lagi
 */
class KompresGambar extends Command
{
    protected $signature = 'gambar:kompres {--yatim : Hapus file gambar yang tidak dipakai data mana pun} {--batas=300 : Kompres ulang file WebP di atas ukuran ini (KB)}';

    protected $description = 'Kompres gambar unggahan lama ke WebP & bersihkan file yang tidak terpakai';

    private int $hemat = 0;

    public function handle(): int
    {
        $batas = (int) $this->option('batas') * 1024;

        // [tabel, kolom, preset]
        foreach ([['units', 'wallpaper', Gambar::WALLPAPER], ['aset', 'foto', Gambar::FOTO], ['pengeluaran', 'foto_nota', Gambar::FOTO]] as [$tabel, $kolom, $preset]) {
            DB::table($tabel)->whereNotNull($kolom)->where($kolom, '!=', '')->get(['id', $kolom])
                ->each(function ($row) use ($tabel, $kolom, $preset, $batas) {
                    $baru = $this->kompres($row->$kolom, $preset, $batas);

                    if ($baru !== $row->$kolom) {
                        DB::table($tabel)->where('id', $row->id)->update([$kolom => $baru, 'updated_at' => now()]);
                    }
                });
        }

        foreach (['tv.wallpaper' => Gambar::WALLPAPER] as $kunci => $preset) {
            Pengaturan::withoutGlobalScopes()->where('kunci', $kunci)->get()->each(function (Pengaturan $p) use ($preset, $batas) {
                $path = $p->nilai['v'] ?? null;
                $baru = $path ? $this->kompres($path, $preset, $batas) : null;

                if ($baru && $baru !== $path) {
                    $p->nilai = ['v' => $baru];
                    $p->save();
                }
            });
        }

        if ($this->option('yatim')) {
            $this->hapusYatim();
        }

        $this->info('Selesai. Hemat '.Number::fileSize($this->hemat, 1).'.');

        return self::SUCCESS;
    }

    private function kompres(string $path, array $preset, int $batas): string
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($path) || (str_ends_with($path, '.webp') && $disk->size($path) <= $batas)) {
            return $path;
        }

        try {
            $sebelum = $disk->size($path);
            $baru = Gambar::simpanWebp($disk->path($path), dirname($path), ...$preset);
            $sesudah = $disk->size($baru);
            $disk->delete($path);
        } catch (Throwable $e) {
            $this->warn("Lewati {$path}: {$e->getMessage()}");

            return $path;
        }

        $this->hemat += max(0, $sebelum - $sesudah);
        $this->line("{$path}: ".Number::fileSize($sebelum, 1).' → '.Number::fileSize($sesudah, 1));

        return $baru;
    }

    /** File di storage/app/public/tenants yang tidak dirujuk data mana pun */
    private function hapusYatim(): void
    {
        $dipakai = collect()
            ->merge(DB::table('units')->whereNotNull('wallpaper')->pluck('wallpaper'))
            ->merge(DB::table('aset')->whereNotNull('foto')->pluck('foto'))
            ->merge(DB::table('pengeluaran')->whereNotNull('foto_nota')->pluck('foto_nota'))
            ->merge(Pengaturan::withoutGlobalScopes()->whereIn('kunci', ['tv.wallpaper', 'tema.logo'])->get()->map(fn ($p) => $p->nilai['v'] ?? null))
            ->filter()->flip();

        $disk = Storage::disk('public');

        foreach ($disk->allFiles('tenants') as $f) {
            // File baru (<1 jam) mungkin sedang dalam proses simpan
            if (! isset($dipakai[$f]) && $disk->lastModified($f) < now()->subHour()->timestamp) {
                $this->hemat += $disk->size($f);
                $disk->delete($f);
                $this->line("Hapus file tidak terpakai: {$f}");
            }
        }
    }
}
