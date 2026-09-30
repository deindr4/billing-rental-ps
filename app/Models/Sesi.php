<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use App\Models\Concerns\TidakBisaDihapus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sesi extends Model
{
    use BelongsToCabang, BelongsToTenant, HasSyncMeta, HasUuids, TidakBisaDihapus;

    public const MODE_PAKET = 'paket';

    public const MODE_OPEN = 'open';

    public const STATUS_BERJALAN = 'berjalan';

    public const STATUS_DIJEDA = 'dijeda';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'sesi';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'unit_id', 'transaksi_id', 'shift_id', 'user_id', 'paket_harga_id',
        'mode', 'tarif_per_jam', 'durasi_menit', 'mulai_pada', 'berakhir_pada', 'selesai_pada',
        'dijeda_pada', 'total_jeda_detik', 'jumlah_jeda', 'status', 'bayar_di_awal', 'versi_tagihan',
    ];

    protected function casts(): array
    {
        return [
            'tarif_per_jam' => 'integer',
            'durasi_menit' => 'integer',
            'mulai_pada' => 'datetime',
            'berakhir_pada' => 'datetime',
            'selesai_pada' => 'datetime',
            'dijeda_pada' => 'datetime',
            'total_jeda_detik' => 'integer',
            'jumlah_jeda' => 'integer',
            'bayar_di_awal' => 'boolean',
            'versi_tagihan' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }

    public function paketHarga(): BelongsTo
    {
        return $this->belongsTo(PaketHarga::class);
    }

    public function log(): HasMany
    {
        return $this->hasMany(SesiLog::class)->orderBy('created_at');
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_BERJALAN, self::STATUS_DIJEDA]);
    }

    public function isPaket(): bool
    {
        return $this->mode === self::MODE_PAKET;
    }

    public function isAktif(): bool
    {
        return in_array($this->status, [self::STATUS_BERJALAN, self::STATUS_DIJEDA], true);
    }

    /** Masih dalam waktu pilih game: TV terbuka, waktu sewa belum berjalan */
    public function sedangPilihGame(?CarbonInterface $now = null): bool
    {
        return $this->isAktif() && $this->mulai_pada->gt($now ?? now());
    }

    /** Sisa waktu paket (detik); null untuk open billing. Saat dijeda, sisa waktu berhenti. */
    public function sisaDetik(?CarbonInterface $now = null): ?int
    {
        if (! $this->isPaket() || $this->berakhir_pada === null) {
            return null;
        }

        $acuan = $this->dijeda_pada ?? $this->selesai_pada ?? $now ?? now();

        return max(0, (int) $acuan->diffInSeconds($this->berakhir_pada, false));
    }

    /** Lama bermain bersih (tanpa jeda) dalam detik. */
    public function durasiBerjalanDetik(?CarbonInterface $now = null): int
    {
        $akhir = $this->selesai_pada ?? $this->dijeda_pada ?? $now ?? now();

        return max(0, (int) $this->mulai_pada->diffInSeconds($akhir, false) - $this->total_jeda_detik);
    }

    public static function formatDurasi(int $detik): string
    {
        $menit = intdiv(max(0, $detik) + 59, 60);
        $jam = intdiv($menit, 60);
        $sisa = $menit % 60;

        if ($jam === 0) {
            return "{$sisa} Menit";
        }

        return $sisa === 0 ? "{$jam} Jam" : "{$jam} Jam {$sisa} Menit";
    }
}
