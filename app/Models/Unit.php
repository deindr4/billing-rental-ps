<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabang;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use App\Support\Gambar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Unit extends Model
{
    use BelongsToCabang, BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    /** Status berubah setiap sesi berjalan (sudah tercatat di sesi_log) */
    protected array $auditAbaikan = ['status'];

    public const STATUS_KOSONG = 'kosong';

    public const STATUS_MAIN = 'main';

    public const STATUS_PAUSE = 'pause';

    public const STATUS_MENUNGGU_BAYAR = 'menunggu_bayar';

    public const STATUS_SERVIS = 'servis';

    public const MODE_TV_AGENT = 'tv_agent';

    public const MODE_MANUAL = 'manual';

    public const PERANGKAT = [
        'tanpa_kontrol' => 'Tanpa Kontrol',
        'android_tv' => 'Android TV Agent',
        'vidaa' => 'Smart TV VIDAA (Hisense/Toshiba)',
        'tizen' => 'Smart TV Tizen (Samsung)',
        'smart_plug' => 'Smart Plug / Relay',
    ];

    public const POSISI_TIMER = [
        'kiri_atas' => 'Kiri Atas',
        'kanan_atas' => 'Kanan Atas',
        'kiri_bawah' => 'Kiri Bawah',
        'kanan_bawah' => 'Kanan Bawah',
    ];

    protected $fillable = [
        'tenant_id',
        'cabang_id',
        'tipe_konsol_id',
        'kategori_unit_id',
        'kode',
        'nama',
        'lokasi',
        'urutan',
        'status',
        'mode_kontrol',
        'tipe_perangkat',
        'posisi_timer',
        'durasi_bypass_menit',
        'wallpaper',
        'izinkan_booking',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
            'durasi_bypass_menit' => 'integer',
            'izinkan_booking' => 'boolean',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Wallpaper unit: dikompres ke WebP (hemat penyimpanan & cepat dimuat TV), file lama dihapus
        static::saving(function (Unit $unit) {
            if (! $unit->isDirty('wallpaper')) {
                return;
            }

            $disk = Storage::disk('public');
            $lama = $unit->getOriginal('wallpaper');
            $baru = $unit->wallpaper;

            if ($baru && ! str_ends_with($baru, '.webp') && $disk->exists($baru)) {
                $unit->wallpaper = Gambar::simpanWebp($disk->path($baru), dirname($baru), ...Gambar::WALLPAPER);
                $disk->delete($baru);
            }

            if ($lama && $lama !== $unit->wallpaper) {
                $disk->delete($lama);
            }
        });
    }

    public function tipeKonsol(): BelongsTo
    {
        return $this->belongsTo(TipeKonsol::class);
    }

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(KategoriUnit::class, 'kategori_unit_id');
    }

    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'game_unit')->withTimestamps();
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeUrut(Builder $query): Builder
    {
        return $query->orderBy('urutan')->orderBy('kode');
    }

    public function isKosong(): bool
    {
        return $this->status === self::STATUS_KOSONG;
    }

    public function pakaiTvAgent(): bool
    {
        return $this->mode_kontrol === self::MODE_TV_AGENT;
    }
}
