<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use App\Models\Concerns\TidakBisaDihapus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaksiDiskon extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids, TidakBisaDihapus;

    protected $table = 'transaksi_diskon';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'transaksi_id', 'user_id', 'jenis', 'nama', 'nilai', 'referensi_type', 'referensi_id',
    ];

    protected function casts(): array
    {
        return [
            'nilai' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }
}
