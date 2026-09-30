<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pembayaran mandiri lewat payment gateway (QRIS di TV) */
class PembayaranOnline extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids;

    public const STATUS = [
        'menunggu' => 'Menunggu dibayar',
        'dibayar' => 'Dibayar, diproses',
        'selesai' => 'Selesai',
        'kedaluwarsa' => 'Kedaluwarsa',
        'gagal' => 'Gagal',
        'perlu_tindakan' => 'Perlu tindakan kasir',
    ];

    protected $table = 'pembayaran_online';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'unit_id', 'sesi_id', 'transaksi_id', 'provider', 'jenis', 'merchant_ref',
        'referensi', 'nominal', 'biaya', 'menit', 'paket_harga_id', 'qr_string', 'kedaluwarsa_pada', 'status',
        'dibayar_pada', 'diproses_pada', 'catatan', 'data',
    ];

    protected function casts(): array
    {
        return [
            'nominal' => 'integer',
            'biaya' => 'integer',
            'menit' => 'integer',
            'kedaluwarsa_pada' => 'datetime',
            'dibayar_pada' => 'datetime',
            'diproses_pada' => 'datetime',
            'data' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function sesi(): BelongsTo
    {
        return $this->belongsTo(Sesi::class);
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }

    /** QR yang masih bisa dibayar */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('status', 'menunggu')->where('kedaluwarsa_pada', '>', now());
    }

    public function masihBisaDibayar(): bool
    {
        return $this->status === 'menunggu' && $this->kedaluwarsa_pada->isFuture();
    }

    /** Tautan halaman bayar gateway (DOKU Checkout); null = tagihan berupa string QRIS */
    public function urlBayar(): ?string
    {
        return $this->data['url_bayar'] ?? null;
    }
}
