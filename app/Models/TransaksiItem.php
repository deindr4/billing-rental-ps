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

class TransaksiItem extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids, TidakBisaDihapus;

    public const JENIS_SEWA = 'sewa';

    public const JENIS_TAMBAH_WAKTU = 'tambah_waktu';

    public const JENIS_PRODUK = 'produk';

    public const JENIS_LAINNYA = 'lainnya';

    /** Sewa aksesori (stik tambahan, headset) — referensi = SesiAksesori */
    public const JENIS_AKSESORI = 'aksesori';

    protected $table = 'transaksi_item';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'transaksi_id', 'jenis', 'referensi_type', 'referensi_id',
        'nama', 'qty', 'harga_satuan', 'hpp_satuan', 'subtotal', 'catatan',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'harga_satuan' => 'integer',
            'hpp_satuan' => 'integer',
            'subtotal' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }

    public function referensi(): MorphTo
    {
        return $this->morphTo();
    }
}
