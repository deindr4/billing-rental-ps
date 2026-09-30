<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KategoriUnit extends Model
{
    use BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    protected $table = 'kategori_unit';

    protected $fillable = [
        'tenant_id',
        'kode',
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

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'kategori_unit_id');
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
