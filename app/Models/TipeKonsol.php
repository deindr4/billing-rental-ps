<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipeKonsol extends Model
{
    use BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    protected $table = 'tipe_konsol';

    public const JENIS_PS = 'ps';

    public const JENIS_PC = 'pc';

    /** Jenis rental: menu kasir terpisah (Rental PS / Rental PC), laporan tetap satu */
    public const JENIS = [
        self::JENIS_PS => 'PlayStation / konsol',
        self::JENIS_PC => 'PC',
    ];

    protected $fillable = [
        'tenant_id',
        'kode',
        'jenis',
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
        return $this->hasMany(Unit::class);
    }

    public function paketHarga(): HasMany
    {
        return $this->hasMany(PaketHarga::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
