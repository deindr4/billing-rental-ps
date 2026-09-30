<?php

namespace App\Services\Tv;

use App\Events\TvSegarkan;
use App\Models\PerangkatTv;

/**
 * Kirim sinyal "segarkan" ke TV yang terpasang di unit tertentu.
 */
final class NotifikasiTv
{
    public static function unit(?string $unitId, string $alasan): void
    {
        if (! $unitId) {
            return;
        }

        PerangkatTv::withoutGlobalScopes()
            ->where('unit_id', $unitId)
            ->aktif()
            ->pluck('id')
            ->each(fn (string $id) => TvSegarkan::dispatch($id, $alasan));
    }

    /** Semua TV aktif di satu cabang (mis. setelah pengaturan cabang disimpan) */
    public static function cabang(string $cabangId, string $alasan): void
    {
        PerangkatTv::withoutGlobalScopes()
            ->where('cabang_id', $cabangId)
            ->aktif()
            ->pluck('id')
            ->each(fn (string $id) => TvSegarkan::dispatch($id, $alasan));
    }

    /** Semua TV aktif milik tenant (mis. setelah tema/wallpaper diganti) */
    public static function tenant(string $tenantId, string $alasan): void
    {
        PerangkatTv::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->aktif()
            ->pluck('id')
            ->each(fn (string $id) => TvSegarkan::dispatch($id, $alasan));
    }

    public static function perangkat(PerangkatTv $perangkat, string $alasan): void
    {
        TvSegarkan::dispatch($perangkat->id, $alasan);
    }
}
