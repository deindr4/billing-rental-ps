<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasSyncMeta;
use App\Support\Gambar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Iklan bergambar di billboard publik. Tayang antara mulai_pada & selesai_pada,
 * lalu dihapus otomatis (jadwal per jam). Gambar WebP disimpan base64 di kolom `gambar`.
 */
class Iklan extends Model
{
    use BelongsToTenant, HasSyncMeta, HasUuids;

    protected $table = 'iklan';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'judul', 'pengiklan', 'tautan', 'gambar', 'lebar', 'tinggi',
        'mulai_pada', 'selesai_pada', 'urutan', 'is_active',
    ];

    protected $hidden = ['gambar'];

    protected function casts(): array
    {
        return [
            'mulai_pada' => 'datetime',
            'selesai_pada' => 'datetime',
            'is_active' => 'boolean',
            'urutan' => 'integer',
            'dilihat' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    /** Sedang tayang di cabang tertentu (atau semua cabang) */
    public function scopeTayang(Builder $query, ?string $cabangId = null): Builder
    {
        return $query->where('is_active', true)
            ->where('mulai_pada', '<=', now())
            ->where('selesai_pada', '>', now())
            ->when($cabangId, fn ($q) => $q->where(fn ($w) => $w->whereNull('cabang_id')->orWhere('cabang_id', $cabangId)))
            ->orderBy('urutan')->orderBy('mulai_pada');
    }

    /** Hapus iklan yang masa tayangnya habis (dijadwalkan per jam). Return jumlah. */
    public static function hapusKedaluwarsa(): int
    {
        $lama = static::withoutGlobalScopes()->where('selesai_pada', '<', now())->get(['id', 'tenant_id']);
        $lama->each->delete();

        return $lama->count();
    }

    /** tayang | terjadwal | berakhir | nonaktif */
    public function status(): string
    {
        return match (true) {
            ! $this->is_active => 'nonaktif',
            $this->selesai_pada->isPast() => 'berakhir',
            $this->mulai_pada->isFuture() => 'terjadwal',
            default => 'tayang',
        };
    }

    /**
     * Kolom gambar dari file unggahan (dikompres ke WebP maks 1200px).
     *
     * @return array{gambar:string, lebar:int, tinggi:int}
     */
    public static function dataGambar(string $pathFile): array
    {
        $webp = Gambar::keWebp($pathFile, ...Gambar::IKLAN);
        $ukuran = @getimagesizefromstring($webp) ?: [0, 0];

        return ['gambar' => base64_encode($webp), 'lebar' => (int) $ukuran[0], 'tinggi' => (int) $ukuran[1]];
    }

    /** URL gambar (versi = updated_at supaya cache browser diperbarui saat gambar diganti) */
    public function urlGambar(): string
    {
        return route('iklan.gambar', ['iklan' => $this->id, 'v' => $this->updated_at?->timestamp]);
    }
}
