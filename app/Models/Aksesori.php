<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aksesori yang disewakan di lokasi (stik tambahan, headset, setir), ditagih ke sesi unit.
 */
class Aksesori extends Model
{
    use BelongsToCabang, BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    public const SATUAN_SESI = 'sesi';

    public const SATUAN_JAM = 'jam';

    public const SATUAN = [
        self::SATUAN_SESI => 'Flat per sesi',
        self::SATUAN_JAM => 'Per jam (mengikuti lama sewa)',
    ];

    protected $table = 'aksesori';

    protected $fillable = ['tenant_id', 'cabang_id', 'nama', 'harga', 'satuan', 'stok', 'urutan', 'is_active'];

    protected function casts(): array
    {
        return [
            'harga' => 'integer',
            'stok' => 'integer',
            'urutan' => 'integer',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function sewa(): HasMany
    {
        return $this->hasMany(SesiAksesori::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeUrut(Builder $query): Builder
    {
        return $query->orderBy('urutan')->orderBy('nama');
    }

    /** Jumlah yang sedang disewa (belum dikembalikan) */
    public function dipakai(): int
    {
        return (int) SesiAksesori::withoutGlobalScopes()->where('aksesori_id', $this->id)->whereNull('selesai_pada')->sum('qty');
    }

    public function tersedia(): int
    {
        return max(0, $this->stok - $this->dipakai());
    }

    /** "Rp5.000 / sesi" */
    public function labelHarga(): string
    {
        return 'Rp'.number_format($this->harga, 0, ',', '.').' / '.$this->satuan;
    }
}
