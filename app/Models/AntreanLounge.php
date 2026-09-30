<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pelanggan yang menunggu unit kosong di lounge (dipanggil lewat billboard) */
class AntreanLounge extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids;

    public const STATUS_MENUNGGU = 'menunggu';

    public const STATUS_DIPANGGIL = 'dipanggil';

    public const STATUS_DILAYANI = 'dilayani';

    public const STATUS_BATAL = 'batal';

    protected $table = 'antrean_lounge';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'tanggal', 'nomor', 'nama', 'telepon', 'member_id', 'tipe_konsol_id',
        'jumlah_orang', 'unit_id', 'status', 'dipanggil_pada', 'jumlah_panggil', 'catatan', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'dipanggil_pada' => 'datetime',
            'nomor' => 'integer',
            'jumlah_orang' => 'integer',
            'jumlah_panggil' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tipeKonsol(): BelongsTo
    {
        return $this->belongsTo(TipeKonsol::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** Antrean aktif hari ini (menunggu & sudah dipanggil) urut nomor */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->whereDate('tanggal', today())
            ->whereIn('status', [self::STATUS_MENUNGGU, self::STATUS_DIPANGGIL])
            ->orderBy('nomor');
    }
}
