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
        // Agen kiosk di PC Windows: memakai API & pairing TV Agent (perangkat_tv.jenis = pc)
        'windows_pc' => 'PC Windows Agent (kiosk)',
    ];

    public const POSISI_TIMER = [
        'kiri_atas' => 'Kiri Atas',
        'kanan_atas' => 'Kanan Atas',
        'kiri_bawah' => 'Kiri Bawah',
        'kanan_bawah' => 'Kanan Bawah',
    ];

    /** Palet warna penanda kartu unit (dipakai bergiliran menurut urutan bila warna unit kosong) */
    public const PALET = [
        '#38bdf8', '#f472b6', '#a78bfa', '#fbbf24', '#34d399', '#fb923c',
        '#60a5fa', '#e879f9', '#2dd4bf', '#f87171', '#a3e635', '#c084fc',
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
        'warna',
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

    /** Warna penanda kartu: pilihan admin, atau otomatis dari palet menurut urutan unit */
    public function warnaKartu(): string
    {
        if ($this->warna && preg_match('/^#[0-9a-fA-F]{6}$/', $this->warna)) {
            return $this->warna;
        }

        $indeks = $this->urutan > 0 ? $this->urutan - 1 : crc32((string) $this->kode);

        return self::PALET[$indeks % count(self::PALET)];
    }

    /** Unit rental PS atau PC (dari jenis tipe konsolnya; tanpa tipe = PS) */
    public function scopeJenis(Builder $query, string $jenis): Builder
    {
        $pc = fn (Builder $q) => $q->where('jenis', TipeKonsol::JENIS_PC);

        return $jenis === TipeKonsol::JENIS_PC
            ? $query->whereHas('tipeKonsol', $pc)
            : $query->whereDoesntHave('tipeKonsol', $pc);
    }

    public function isPc(): bool
    {
        return $this->tipeKonsol?->jenis === TipeKonsol::JENIS_PC;
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
