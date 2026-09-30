<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Harga sewa: tarif per jam atau paket durasi tetap.
 * cabang / tipe konsol / kategori kosong = berlaku untuk semua.
 */
class PaketHarga extends Model
{
    use BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    public const JENIS_PER_JAM = 'per_jam';

    public const JENIS_PAKET = 'paket';

    protected $table = 'paket_harga';

    protected $fillable = [
        'tenant_id',
        'cabang_id',
        'tipe_konsol_id',
        'kategori_unit_id',
        'nama',
        'jenis',
        'durasi_menit',
        'harga',
        'keterangan',
        'urutan',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'durasi_menit' => 'integer',
            'harga' => 'integer',
            'urutan' => 'integer',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function tipeKonsol(): BelongsTo
    {
        return $this->belongsTo(TipeKonsol::class);
    }

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(KategoriUnit::class, 'kategori_unit_id');
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /** Paket yang berlaku untuk unit: cocok cabang, tipe konsol & kategori (atau berlaku umum) */
    public function scopeUntukUnit(Builder $query, Unit $unit): Builder
    {
        return $query
            ->where(fn ($q) => $q->whereNull('cabang_id')->orWhere('cabang_id', $unit->cabang_id))
            ->where(fn ($q) => $q->whereNull('tipe_konsol_id')->orWhere('tipe_konsol_id', $unit->tipe_konsol_id))
            ->where(fn ($q) => $q->whereNull('kategori_unit_id')->orWhere('kategori_unit_id', $unit->kategori_unit_id));
    }

    public function isPaket(): bool
    {
        return $this->jenis === self::JENIS_PAKET;
    }
}
