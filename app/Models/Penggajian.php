<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rekap gaji satu karyawan untuk satu periode. Rincian (hadir, jam, bonus per shift, usulan potongan selisih kas,
 * penyesuaian manual) di JSON `rincian`; angka ringkas di kolom supaya mudah dijumlah.
 */
class Penggajian extends Model
{
    use BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    public const STATUS = [
        'draft' => 'Draft',
        'disetujui' => 'Disetujui',
        'dibayar' => 'Dibayar',
        'batal' => 'Batal',
    ];

    protected $table = 'penggajian';

    protected $fillable = [
        'tenant_id', 'karyawan_id', 'cabang_id', 'nomor', 'periode_mulai', 'periode_selesai', 'status',
        'gaji_pokok', 'upah_hadir', 'upah_jam', 'bonus', 'penyesuaian', 'potongan', 'total', 'rincian', 'catatan',
        'dibuat_oleh', 'disetujui_oleh', 'disetujui_pada', 'dibayar_pada', 'sumber_dana', 'pengeluaran_id',
    ];

    protected function casts(): array
    {
        return [
            'periode_mulai' => 'date',
            'periode_selesai' => 'date',
            'gaji_pokok' => 'integer',
            'upah_hadir' => 'integer',
            'upah_jam' => 'integer',
            'bonus' => 'integer',
            'penyesuaian' => 'integer',
            'potongan' => 'integer',
            'total' => 'integer',
            'rincian' => 'array',
            'disetujui_pada' => 'datetime',
            'dibayar_pada' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function pengeluaran(): BelongsTo
    {
        return $this->belongsTo(Pengeluaran::class);
    }

    public function bisaDiubah(): bool
    {
        return $this->status === 'draft';
    }

    public function labelPeriode(): string
    {
        return $this->periode_mulai->format('d/m/Y').' – '.$this->periode_selesai->format('d/m/Y');
    }
}
