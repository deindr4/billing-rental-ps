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

class KasMutasi extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids, TidakBisaDihapus;

    protected $table = 'kas_mutasi';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'shift_id', 'user_id', 'jenis', 'jumlah', 'sumber_type', 'sumber_id', 'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function sumber(): MorphTo
    {
        return $this->morphTo();
    }
}
