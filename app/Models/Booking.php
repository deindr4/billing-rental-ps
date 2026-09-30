<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids;

    public const STATUS = [
        'menunggu' => 'Menunggu konfirmasi',
        'dikonfirmasi' => 'Dikonfirmasi',
        'checkin' => 'Sudah datang',
        'batal' => 'Dibatalkan',
        'tidak_datang' => 'Tidak datang',
    ];

    /** Status yang masih memegang slot unit */
    public const AKTIF = ['menunggu', 'dikonfirmasi'];

    protected $table = 'booking';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'unit_id', 'member_id', 'kode', 'nama', 'telepon', 'mulai_pada', 'selesai_pada',
        'durasi_menit', 'perkiraan_harga', 'catatan', 'status', 'sumber', 'sesi_id', 'alasan_batal', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'mulai_pada' => 'datetime',
            'selesai_pada' => 'datetime',
            'durasi_menit' => 'integer',
            'perkiraan_harga' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->whereIn('status', self::AKTIF);
    }

    /** Booking yang bertabrakan dengan rentang waktu */
    public function scopeBentrok(Builder $query, string $unitId, $mulai, $selesai): Builder
    {
        return $query->aktif()->where('unit_id', $unitId)
            ->where('mulai_pada', '<', $selesai)
            ->where('selesai_pada', '>', $mulai);
    }

    public function isAktif(): bool
    {
        return in_array($this->status, self::AKTIF, true);
    }
}
