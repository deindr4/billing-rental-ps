<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use App\Models\Concerns\TidakBisaDihapus;
use App\Services\Member\MemberService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaksi extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids, TidakBisaDihapus;

    public const JENIS_BILLING = 'billing';

    public const JENIS_POS = 'pos';

    public const JENIS_TOP_UP = 'top_up';

    public const JENIS_TURNAMEN = 'turnamen';

    public const STATUS_BELUM_BAYAR = 'belum_bayar';

    public const STATUS_LUNAS = 'lunas';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'transaksi';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'shift_id', 'unit_id', 'user_id', 'nomor', 'jenis', 'status',
        'member_id', 'pelanggan_nama', 'subtotal', 'total_diskon', 'total', 'total_bayar', 'kembalian',
        'dibayar_pada', 'dibatalkan_pada', 'dibatalkan_oleh', 'alasan_batal', 'catatan', 'is_latihan', 'grup_bayar',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'total_diskon' => 'integer',
            'total' => 'integer',
            'total_bayar' => 'integer',
            'kembalian' => 'integer',
            'dibayar_pada' => 'datetime',
            'dibatalkan_pada' => 'datetime',
            'is_latihan' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransaksiItem::class);
    }

    public function diskon(): HasMany
    {
        return $this->hasMany(TransaksiDiskon::class);
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(Pembayaran::class);
    }

    public function sesi(): HasOne
    {
        return $this->hasOne(Sesi::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Hitung ulang subtotal, diskon, dan total dari item & diskon. */
    public function hitungUlang(): void
    {
        // Diskon tier member ikut menyesuaikan nilai sewa terbaru
        if ($this->member_id && $this->status === self::STATUS_BELUM_BAYAR) {
            app(MemberService::class)->sinkronDiskonTier($this);
        }

        $subtotal = (int) TransaksiItem::withoutGlobalScopes()->where('transaksi_id', $this->id)->sum('subtotal');
        $diskon = min((int) TransaksiDiskon::withoutGlobalScopes()->where('transaksi_id', $this->id)->sum('nilai'), $subtotal);

        $this->subtotal = $subtotal;
        $this->total_diskon = $diskon;
        $this->total = max(0, $subtotal - $diskon);
        $this->save();
    }

    public function totalDibayar(): int
    {
        return (int) Pembayaran::withoutGlobalScopes()
            ->where('transaksi_id', $this->id)
            ->where('status', 'sukses')
            ->sum('jumlah');
    }

    public function sisaTagihan(): int
    {
        return max(0, $this->total - $this->totalDibayar());
    }

    public function isLunas(): bool
    {
        return $this->status === self::STATUS_LUNAS;
    }

    public function isDibatalkan(): bool
    {
        return $this->status === self::STATUS_DIBATALKAN;
    }
}
