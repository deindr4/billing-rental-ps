<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Jam kerja shift karyawan (mis. Pagi 10:00–17:00, Malam 17:00–02:00) + toleransi terlambat */
class TemplateShift extends Model
{
    use BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    protected $table = 'template_shift';

    protected $fillable = ['tenant_id', 'nama', 'jam_mulai', 'jam_selesai', 'toleransi_menit', 'urutan', 'is_active'];

    protected function casts(): array
    {
        return [
            'toleransi_menit' => 'integer',
            'urutan' => 'integer',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** "10:00–17:00" */
    public function label(): string
    {
        return substr((string) $this->jam_mulai, 0, 5).'–'.substr((string) $this->jam_selesai, 0, 5);
    }

    /**
     * Jam mulai & selesai pada tanggal tertentu (selesai lewat tengah malam = esok hari).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function rentang(CarbonInterface $tanggal): array
    {
        $mulai = Carbon::parse($tanggal->format('Y-m-d').' '.$this->jam_mulai);
        $selesai = Carbon::parse($tanggal->format('Y-m-d').' '.$this->jam_selesai);

        if ($selesai->lte($mulai)) {
            $selesai->addDay();
        }

        return [$mulai, $selesai];
    }

    /** Lama shift dalam menit */
    public function menit(): int
    {
        [$mulai, $selesai] = $this->rentang(today());

        return (int) $mulai->diffInMinutes($selesai);
    }
}
