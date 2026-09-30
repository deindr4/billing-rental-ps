<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Aset rental. Penyusutan garis lurus: (harga perolehan - nilai sisa) / umur ekonomis (bulan).
 */
class Aset extends Model
{
    use BelongsToCabang, BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    public const KATEGORI = [
        'konsol' => 'Konsol PS',
        'tv' => 'TV / Monitor',
        'stik' => 'Stik & aksesori',
        'ac' => 'AC & pendingin',
        'furnitur' => 'Furnitur',
        'elektronik' => 'Elektronik lain',
        'lainnya' => 'Lainnya',
    ];

    /** Umur ekonomis bawaan (bulan) per kategori */
    public const UMUR_BAWAAN = [
        'konsol' => 48,
        'tv' => 60,
        'stik' => 24,
        'ac' => 60,
        'furnitur' => 60,
        'elektronik' => 48,
        'lainnya' => 48,
    ];

    public const STATUS = [
        'aktif' => 'Aktif',
        'servis' => 'Dalam servis',
        'rusak' => 'Rusak',
        'dilepas' => 'Dilepas / dijual',
    ];

    protected $table = 'aset';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'unit_id', 'kategori', 'nama', 'merek', 'serial', 'tanggal_beli',
        'harga_perolehan', 'nilai_sisa', 'umur_bulan', 'garansi_sampai', 'status', 'dilepas_pada', 'nilai_lepas',
        'interval_servis_hari', 'servis_terakhir', 'foto', 'catatan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_beli' => 'date',
            'garansi_sampai' => 'date',
            'dilepas_pada' => 'date',
            'servis_terakhir' => 'date',
            'harga_perolehan' => 'integer',
            'nilai_sisa' => 'integer',
            'nilai_lepas' => 'integer',
            'umur_bulan' => 'integer',
            'interval_servis_hari' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function maintenance(): HasMany
    {
        return $this->hasMany(Maintenance::class);
    }

    public function scopeDimiliki(Builder $query): Builder
    {
        return $query->where('status', '!=', 'dilepas');
    }

    /* ---------------- Penyusutan ---------------- */

    public function penyusutanPerBulan(): int
    {
        return $this->umur_bulan > 0
            ? intdiv(max(0, $this->harga_perolehan - $this->nilai_sisa), $this->umur_bulan)
            : 0;
    }

    /** Bulan penuh sejak dibeli sampai tanggal (atau tanggal dilepas) */
    public function bulanBerjalan(?CarbonInterface $per = null): int
    {
        $akhir = $this->dilepas_pada ?? $per ?? now();

        return max(0, (int) $this->tanggal_beli->diffInMonths($akhir, false));
    }

    public function akumulasiPenyusutan(?CarbonInterface $per = null): int
    {
        $dapatDisusutkan = max(0, $this->harga_perolehan - $this->nilai_sisa);

        if ($this->bulanBerjalan($per) >= $this->umur_bulan) {
            return $dapatDisusutkan;
        }

        return min($dapatDisusutkan, $this->bulanBerjalan($per) * $this->penyusutanPerBulan());
    }

    public function nilaiBuku(?CarbonInterface $per = null): int
    {
        return $this->harga_perolehan - $this->akumulasiPenyusutan($per);
    }

    public function persenPenyusutan(): int
    {
        return $this->harga_perolehan > 0
            ? (int) round($this->akumulasiPenyusutan() * 100 / $this->harga_perolehan)
            : 0;
    }

    /* ---------------- Garansi & servis ---------------- */

    /** Sisa garansi dalam bulan; null = tidak ada data garansi; 0 = kedaluwarsa */
    public function sisaGaransiBulan(): ?int
    {
        if (! $this->garansi_sampai) {
            return null;
        }

        return $this->garansi_sampai->isPast() ? 0 : max(1, (int) ceil(now()->diffInMonths($this->garansi_sampai)));
    }

    public function servisBerikutnya(): ?CarbonInterface
    {
        if (! $this->interval_servis_hari) {
            return null;
        }

        return ($this->servis_terakhir ?? $this->tanggal_beli)->copy()->addDays($this->interval_servis_hari);
    }

    public function jatuhTempoServis(): bool
    {
        $berikut = $this->servisBerikutnya();

        return $berikut !== null && $this->status !== 'dilepas' && $berikut->lte(now()->addDays(3));
    }

    public function fotoUrl(): ?string
    {
        return $this->foto && Storage::disk('public')->exists($this->foto)
            ? Storage::disk('public')->url($this->foto)
            : null;
    }
}
