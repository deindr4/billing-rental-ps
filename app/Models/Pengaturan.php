<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan key-value per tenant, bisa di-override per cabang.
 *
 * Pengaturan::ambil('open_billing.blok_menit', 15);
 * Pengaturan::simpan('tv.posisi_timer', 'kanan_atas');            // global tenant
 * Pengaturan::simpan('tv.posisi_timer', 'kiri_atas', $cabangId);  // khusus cabang
 */
class Pengaturan extends Model
{
    use BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    protected $table = 'pengaturan';

    protected $fillable = [
        'tenant_id',
        'cabang_id',
        'kunci',
        'nilai',
    ];

    protected function casts(): array
    {
        return [
            'nilai' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /** Ambil nilai: cabang aktif dulu, lalu global tenant, lalu default. */
    public static function ambil(string $kunci, mixed $default = null, ?string $cabangId = null): mixed
    {
        $cabangId ??= app(Tenancy::class)->cabangId();

        $rows = static::query()
            ->where('kunci', $kunci)
            ->where(function ($q) use ($cabangId) {
                $q->whereNull('cabang_id');
                if ($cabangId) {
                    $q->orWhere('cabang_id', $cabangId);
                }
            })
            ->get();

        $row = $rows->firstWhere('cabang_id', $cabangId) ?? $rows->firstWhere('cabang_id', null);

        return $row ? ($row->nilai['v'] ?? $default) : $default;
    }

    public static function simpan(string $kunci, mixed $nilai, ?string $cabangId = null): self
    {
        $row = static::query()
            ->where('kunci', $kunci)
            ->where('cabang_id', $cabangId)
            ->first() ?? new static(['kunci' => $kunci, 'cabang_id' => $cabangId]);

        $row->nilai = ['v' => $nilai];
        $row->save();

        return $row;
    }
}
