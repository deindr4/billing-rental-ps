<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aksesori yang disewa sebuah sesi. selesai_pada null = masih dipakai (mengurangi stok tersedia).
 * Tagihannya satu baris transaksi_item (jenis aksesori); harga per jam dihitung saat dikembalikan / sesi selesai.
 */
class SesiAksesori extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids;

    protected $table = 'sesi_aksesori';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'sesi_id', 'aksesori_id', 'transaksi_item_id', 'user_id',
        'qty', 'harga', 'satuan', 'mulai_pada', 'selesai_pada', 'dibatalkan',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'harga' => 'integer',
            'mulai_pada' => 'datetime',
            'selesai_pada' => 'datetime',
            'dibatalkan' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function aksesori(): BelongsTo
    {
        return $this->belongsTo(Aksesori::class);
    }

    public function sesi(): BelongsTo
    {
        return $this->belongsTo(Sesi::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TransaksiItem::class, 'transaksi_item_id');
    }

    public function scopeDipakai(Builder $query): Builder
    {
        return $query->whereNull('selesai_pada');
    }

    public function perJam(): bool
    {
        return $this->satuan === Aksesori::SATUAN_JAM;
    }
}
