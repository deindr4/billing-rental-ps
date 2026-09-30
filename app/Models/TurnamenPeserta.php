<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TurnamenPeserta extends Model
{
    use BelongsToTenant, HasSyncMeta, HasUuids;

    protected $table = 'turnamen_peserta';

    protected $fillable = [
        'tenant_id', 'turnamen_id', 'member_id', 'nama', 'telepon', 'status', 'transaksi_id', 'unggulan', 'sumber',
    ];

    protected function casts(): array
    {
        return [
            'unggulan' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function turnamen(): BelongsTo
    {
        return $this->belongsTo(Turnamen::class);
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }
}
