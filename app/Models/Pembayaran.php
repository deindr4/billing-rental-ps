<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use App\Models\Concerns\TidakBisaDihapus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pembayaran extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids, TidakBisaDihapus;

    public const METODE = ['tunai', 'qris', 'transfer', 'saldo'];

    protected $table = 'pembayaran';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'transaksi_id', 'shift_id', 'user_id', 'metode', 'jumlah',
        'diterima', 'referensi', 'status', 'dibayar_pada',
    ];

    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'diterima' => 'integer',
            'dibayar_pada' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }
}
