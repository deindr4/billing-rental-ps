<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotifikasiLog extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'notifikasi_log';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'saluran', 'jenis', 'tujuan', 'status',
        'percobaan', 'pesan_error', 'referensi_type', 'referensi_id', 'dikirim_pada',
    ];

    protected function casts(): array
    {
        return [
            'percobaan' => 'integer',
            'dikirim_pada' => 'datetime',
        ];
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }
}
