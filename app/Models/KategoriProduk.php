<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KategoriProduk extends Model
{
    use BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    protected $table = 'kategori_produk';

    protected $fillable = [
        'tenant_id',
        'nama',
        'urutan',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function produk(): HasMany
    {
        return $this->hasMany(Produk::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
