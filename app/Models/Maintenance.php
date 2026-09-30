<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Maintenance extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids;

    public const JENIS = [
        'perbaikan' => 'Perbaikan',
        'servis_rutin' => 'Servis rutin',
        'pembersihan' => 'Pembersihan',
        'ganti_part' => 'Ganti part',
        'upgrade' => 'Upgrade',
    ];

    public const STATUS = [
        'dijadwalkan' => 'Dijadwalkan',
        'dikerjakan' => 'Dikerjakan',
        'selesai' => 'Selesai',
        'batal' => 'Batal',
    ];

    public const TERBUKA = ['dijadwalkan', 'dikerjakan'];

    protected $table = 'maintenance';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'aset_id', 'unit_id', 'jenis', 'judul', 'deskripsi', 'status',
        'dijadwalkan_pada', 'mulai_pada', 'selesai_pada', 'biaya', 'vendor', 'hasil', 'pengeluaran_id',
        'user_id', 'diselesaikan_oleh',
    ];

    protected function casts(): array
    {
        return [
            'dijadwalkan_pada' => 'date',
            'mulai_pada' => 'datetime',
            'selesai_pada' => 'datetime',
            'biaya' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function aset(): BelongsTo
    {
        return $this->belongsTo(Aset::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function penyelesai(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diselesaikan_oleh');
    }

    public function pengeluaran(): BelongsTo
    {
        return $this->belongsTo(Pengeluaran::class);
    }

    public function scopeTerbuka(Builder $query): Builder
    {
        return $query->whereIn('status', self::TERBUKA);
    }

    public function isTerbuka(): bool
    {
        return in_array($this->status, self::TERBUKA, true);
    }

    /** Lama pengerjaan (mulai -> selesai) */
    public function durasiJam(): ?float
    {
        return $this->mulai_pada && $this->selesai_pada
            ? round($this->mulai_pada->diffInMinutes($this->selesai_pada) / 60, 1)
            : null;
    }
}
