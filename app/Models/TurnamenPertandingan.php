<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TurnamenPertandingan extends Model
{
    use BelongsToTenant, HasSyncMeta, HasUuids;

    protected $table = 'turnamen_pertandingan';

    protected $fillable = [
        'tenant_id', 'turnamen_id', 'tahap', 'grup', 'babak', 'nomor', 'peserta_a_id', 'peserta_b_id', 'skor_a', 'skor_b',
        'pemenang_id', 'unit_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            'babak' => 'integer',
            'nomor' => 'integer',
            'skor_a' => 'integer',
            'skor_b' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function pesertaA(): BelongsTo
    {
        return $this->belongsTo(TurnamenPeserta::class, 'peserta_a_id');
    }

    public function pesertaB(): BelongsTo
    {
        return $this->belongsTo(TurnamenPeserta::class, 'peserta_b_id');
    }

    public function pemenang(): BelongsTo
    {
        return $this->belongsTo(TurnamenPeserta::class, 'pemenang_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
