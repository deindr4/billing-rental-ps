<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Absensi karyawan: masuk & pulang dengan PIN + foto selfie. Terlambat / pulang cepat dihitung dari jadwal
 * (template shift hari itu). Koreksi jam oleh owner tercatat di log aktivitas.
 */
class Absensi extends Model
{
    use BelongsToCabang, BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    protected $table = 'absensi';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'karyawan_id', 'tanggal', 'masuk_pada', 'pulang_pada', 'foto_masuk', 'foto_pulang',
        'template_shift_id', 'jadwal_mulai', 'jadwal_selesai', 'terlambat_menit', 'pulang_cepat_menit', 'menit_kerja', 'catatan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'masuk_pada' => 'datetime',
            'pulang_pada' => 'datetime',
            'jadwal_mulai' => 'datetime',
            'jadwal_selesai' => 'datetime',
            'terlambat_menit' => 'integer',
            'pulang_cepat_menit' => 'integer',
            'menit_kerja' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TemplateShift::class, 'template_shift_id');
    }

    /** "7 j 45 m" */
    public static function formatMenit(?int $menit): string
    {
        if ($menit === null) {
            return '-';
        }

        return intdiv($menit, 60).' j '.($menit % 60).' m';
    }
}
