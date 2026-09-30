<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use App\Models\Concerns\TidakBisaDihapus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SesiLog extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids, TidakBisaDihapus;

    protected $table = 'sesi_log';

    protected $fillable = ['tenant_id', 'cabang_id', 'sesi_id', 'user_id', 'jenis', 'data'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function sesi(): BelongsTo
    {
        return $this->belongsTo(Sesi::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
