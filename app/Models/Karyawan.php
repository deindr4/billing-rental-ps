<?php

namespace App\Models;

use App\Casts\TerenkripsiAman;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\Gambar;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Karyawan rental (tingkat tenant, cabang utama opsional). Boleh tertaut ke akun login (kasir, supervisor)
 * atau berdiri sendiri (OB, cleaning). NIK & nomor rekening terenkripsi.
 */
class Karyawan extends Model
{
    use BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    /** Data pribadi tidak ikut ditulis ke log aktivitas */
    protected array $auditAbaikan = ['nik', 'no_rekening', 'pin'];

    public const PERIODE = [
        'bulanan' => 'Bulanan',
        'mingguan' => 'Mingguan',
        'harian' => 'Harian',
    ];

    public const BONUS_JENIS = [
        'nominal' => 'Nominal tetap per shift tercapai',
        'persen' => 'Persen dari kelebihan target',
    ];

    protected $table = 'karyawan';

    protected $fillable = [
        'tenant_id', 'cabang_id', 'user_id', 'nama', 'jabatan', 'nik', 'telepon', 'alamat', 'tanggal_lahir',
        'tanggal_masuk', 'tanggal_keluar', 'kontak_darurat_nama', 'kontak_darurat_telepon', 'bank', 'no_rekening',
        'atas_nama', 'foto', 'catatan', 'is_active',
        'periode_gaji', 'gaji_pokok', 'upah_shift', 'upah_jam', 'bonus_target', 'bonus_jenis', 'bonus_nilai',
    ];

    protected $hidden = ['pin', 'nik', 'no_rekening'];

    protected function casts(): array
    {
        return [
            'nik' => TerenkripsiAman::class,
            'no_rekening' => TerenkripsiAman::class,
            'tanggal_lahir' => 'date',
            'tanggal_masuk' => 'date',
            'tanggal_keluar' => 'date',
            'is_active' => 'boolean',
            'gaji_pokok' => 'integer',
            'upah_shift' => 'integer',
            'upah_jam' => 'integer',
            'bonus_target' => 'integer',
            'bonus_nilai' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Foto dikompres ke WebP, file lama dihapus (pola sama dengan wallpaper unit)
        static::saving(function (Karyawan $k) {
            if (! $k->isDirty('foto')) {
                return;
            }

            $disk = Storage::disk('public');
            $lama = $k->getOriginal('foto');

            if ($k->foto && ! str_ends_with($k->foto, '.webp') && $disk->exists($k->foto)) {
                $baru = Gambar::simpanWebp($disk->path($k->foto), dirname($k->foto), 600, 70);
                $disk->delete($k->foto);
                $k->foto = $baru;
            }

            if ($lama && $lama !== $k->foto) {
                $disk->delete($lama);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** PIN absen: PIN akun login bila tertaut, selain itu PIN karyawan sendiri */
    public function hashPin(): ?string
    {
        return $this->user_id ? User::query()->whereKey($this->user_id)->value('pin') : $this->pin;
    }

    public function cocokPin(string $pin): bool
    {
        $hash = $this->hashPin();

        return $hash !== null && Hash::check($pin, $hash);
    }

    public function aturPin(?string $pin): void
    {
        if (filled($pin)) {
            $this->forceFill(['pin' => Hash::make($pin)])->save();
        }
    }

    /** Masa kerja singkat, mis. "1 th 3 bln" */
    public function masaKerja(): ?string
    {
        if (! $this->tanggal_masuk) {
            return null;
        }

        $selisih = $this->tanggal_masuk->diff($this->tanggal_keluar ?? today());

        return trim(($selisih->y ? "{$selisih->y} th " : '').($selisih->m ? "{$selisih->m} bln" : ($selisih->y ? '' : "{$selisih->d} hr")));
    }
}
