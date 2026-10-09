<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PlayStation yang disewakan bawa pulang (inventaris terpisah dari unit rental): kelengkapan + harga ganti,
 * tarif per jam / hari / minggu / bulan (0 = tidak tersedia), denda telat.
 */
class Playbox extends Model
{
    use BelongsToCabang, BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    public const SATUAN = ['jam' => 'Jam', 'hari' => 'Hari', 'minggu' => 'Minggu', 'bulan' => 'Bulan'];

    /** Lama satu satuan dalam menit */
    public const MENIT = ['jam' => 60, 'hari' => 1440, 'minggu' => 10080, 'bulan' => 43200];

    public const STATUS = ['tersedia' => 'Tersedia', 'disewa' => 'Disewa', 'servis' => 'Servis'];

    protected $table = 'playbox';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'kode', 'nama', 'nomor_seri', 'kelengkapan', 'harga_jam', 'harga_hari', 'harga_minggu',
        'harga_bulan', 'denda_jam', 'denda_hari', 'deposit_saran', 'status', 'catatan', 'urutan', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'kelengkapan' => 'array',
            'harga_jam' => 'integer',
            'harga_hari' => 'integer',
            'harga_minggu' => 'integer',
            'harga_bulan' => 'integer',
            'denda_jam' => 'integer',
            'denda_hari' => 'integer',
            'deposit_saran' => 'integer',
            'urutan' => 'integer',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function sewa(): HasMany
    {
        return $this->hasMany(SewaPlaybox::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function harga(string $satuan): int
    {
        return (int) ($this->{'harga_'.$satuan} ?? 0);
    }

    /** Satuan yang tersedia (harga > 0) => harga */
    public function tarif(): array
    {
        return collect(array_keys(self::SATUAN))->mapWithKeys(fn ($s) => [$s => $this->harga($s)])->filter()->all();
    }

    /** Kelengkapan bawaan untuk checklist keluar */
    public function daftarKelengkapan(): array
    {
        return array_values(array_filter((array) $this->kelengkapan, fn ($k) => filled($k['nama'] ?? null)));
    }
}
