<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use App\Models\Concerns\TidakBisaDihapus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Suntikan modal & prive owner. Tidak dihapus; salah catat -> dibatalkan. */
class ModalMutasi extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids, TidakBisaDihapus;

    public const JENIS = [
        'modal' => 'Suntikan modal',
        'prive' => 'Prive owner',
    ];

    public const SUMBER = [
        'rekening' => 'Rekening / transfer',
        'kas_laci' => 'Kas laci',
    ];

    protected $table = 'modal_mutasi';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'jenis', 'jumlah', 'tanggal', 'sumber', 'keterangan', 'shift_id', 'user_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'tanggal' => 'date',
            'synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
