<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Produk F&B (tingkat tenant). Stok disimpan per cabang di produk_stok.
 */
class Produk extends Model
{
    use BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    protected $table = 'produk';

    protected $fillable = [
        'tenant_id',
        'kategori_produk_id',
        'kode',
        'barcode',
        'nama',
        'harga_jual',
        'satuan',
        'lacak_stok',
        'stok_minimum',
        'urutan',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'harga_jual' => 'integer',
            'lacak_stok' => 'boolean',
            'stok_minimum' => 'integer',
            'urutan' => 'integer',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(KategoriProduk::class, 'kategori_produk_id');
    }

    /** Stok di cabang aktif (ProdukStok difilter cabang otomatis) */
    public function stok(): HasOne
    {
        return $this->hasOne(ProdukStok::class);
    }

    public function semuaStok(): HasMany
    {
        return $this->hasMany(ProdukStok::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    public function sisaStok(): int
    {
        return (int) ($this->stok?->qty ?? 0);
    }

    public function stokMenipis(): bool
    {
        return $this->lacak_stok && $this->sisaStok() <= $this->stok_minimum;
    }
}
