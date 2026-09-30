<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use App\Models\Concerns\TidakBisaDihapus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids, TidakBisaDihapus;

    public const STATUS_BUKA = 'buka';

    public const STATUS_TUTUP = 'tutup';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'user_id', 'nomor', 'status', 'dibuka_pada', 'ditutup_pada',
        'kas_awal', 'kas_seharusnya', 'kas_fisik', 'selisih', 'rincian_pecahan', 'catatan_tutup', 'ditutup_oleh',
    ];

    protected function casts(): array
    {
        return [
            'dibuka_pada' => 'datetime',
            'ditutup_pada' => 'datetime',
            'kas_awal' => 'integer',
            'kas_seharusnya' => 'integer',
            'kas_fisik' => 'integer',
            'selisih' => 'integer',
            'rincian_pecahan' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kasMutasi(): HasMany
    {
        return $this->hasMany(KasMutasi::class);
    }

    public function isBuka(): bool
    {
        return $this->status === self::STATUS_BUKA;
    }
}
