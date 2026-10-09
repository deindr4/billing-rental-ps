<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jadwal mingguan berulang: karyawan × hari (0 = Minggu … 6 = Sabtu) → template shift. Tanpa baris = libur. */
class JadwalKaryawan extends Model
{
    use BelongsToTenant, HasSyncMeta, HasUuids;

    public const HARI = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 0 => 'Minggu'];

    protected $table = 'jadwal_karyawan';

    protected $fillable = ['tenant_id', 'karyawan_id', 'hari', 'template_shift_id'];

    protected function casts(): array
    {
        return ['hari' => 'integer', 'synced_at' => 'datetime'];
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TemplateShift::class, 'template_shift_id');
    }
}
