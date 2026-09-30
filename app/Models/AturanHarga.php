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
 * Penyesuaian harga berdasarkan waktu (happy hour, weekend, malam, libur, promo).
 */
class AturanHarga extends Model
{
    use BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    public const JENIS = [
        'happy_hour' => 'Happy hour',
        'weekend' => 'Weekend',
        'malam' => 'Malam',
        'libur' => 'Hari libur',
        'promo' => 'Promo',
    ];

    public const TIPE_PENYESUAIAN = [
        'persen' => 'Persen',
        'potongan' => 'Potongan (Rp)',
        'harga_tetap' => 'Harga tetap',
    ];

    protected $table = 'aturan_harga';

    protected $fillable = [
        'tenant_id',
        'cabang_id',
        'tipe_konsol_id',
        'kategori_unit_id',
        'nama',
        'jenis',
        'hari',
        'jam_mulai',
        'jam_selesai',
        'tanggal_mulai',
        'tanggal_selesai',
        'tipe_penyesuaian',
        'nilai',
        'prioritas',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'hari' => 'array',
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'nilai' => 'decimal:2',
            'prioritas' => 'integer',
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
        return $query->where('is_active', true);
    }
}
