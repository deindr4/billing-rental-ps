<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use App\Models\Concerns\TidakBisaDihapus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StokMutasi extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids, TidakBisaDihapus;

    public const JENIS = [
        'masuk' => 'Masuk',
        'penjualan' => 'Penjualan',
        'pembatalan' => 'Pembatalan',
        'opname' => 'Opname',
        'koreksi' => 'Koreksi',
    ];

    protected $table = 'stok_mutasi';

    protected $fillable = [
        'tenant_id',
        'cabang_id',
        'produk_id',
        'user_id',
        'jenis',
        'qty',
        'harga_pokok',
        'sumber_type',
        'sumber_id',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'harga_pokok' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function produk(): BelongsTo
    {
        return $this->belongsTo(Produk::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sumber(): MorphTo
    {
        return $this->morphTo();
    }
}
