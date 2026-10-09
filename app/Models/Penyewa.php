<?php

namespace App\Models;

use App\Casts\TerenkripsiAman;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\Diaudit;
use App\Models\Concerns\HasSyncMeta;
use App\Support\Koordinat;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Penyewa Playbox (tingkat tenant): identitas, alamat rumah/kost + koordinat, foto & KTP (disk privat), daftar hitam */
class Penyewa extends Model
{
    use BelongsToTenant, Diaudit, HasSyncMeta, HasUuids;

    /** Data pribadi tidak ditulis ke log aktivitas */
    protected array $auditAbaikan = ['nik', 'foto', 'foto_ktp'];

    public const JENIS_TEMPAT = ['rumah' => 'Rumah', 'kost' => 'Kost'];

    protected $table = 'penyewa';

    protected $fillable = [
        'tenant_id', 'member_id', 'nama', 'telepon', 'nik', 'alamat', 'jenis_tempat', 'lat', 'lng', 'foto', 'foto_ktp',
        'catatan', 'daftar_hitam', 'alasan_daftar_hitam',
    ];

    protected $hidden = ['nik'];

    protected function casts(): array
    {
        return [
            'nik' => TerenkripsiAman::class,
            'lat' => 'float',
            'lng' => 'float',
            'daftar_hitam' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function sewa(): HasMany
    {
        return $this->hasMany(SewaPlaybox::class);
    }

    public function urlMaps(): ?string
    {
        return Koordinat::urlMaps($this->lat, $this->lng);
    }

    /** Nomor HP dirapikan untuk pencarian & WhatsApp: 08xx / +62xx → 62xx */
    public static function rapikanTelepon(?string $hp): string
    {
        $angka = preg_replace('/\D/', '', (string) $hp);

        return str_starts_with($angka, '0') ? '62'.substr($angka, 1) : $angka;
    }

    /** Ringkasan riwayat: jumlah sewa, telat, kerusakan */
    public function riwayat(): array
    {
        $sewa = SewaPlaybox::withoutGlobalScopes()->where('penyewa_id', $this->id)->where('status', '!=', 'batal')->get();

        return [
            'sewa' => $sewa->count(),
            'berjalan' => $sewa->where('status', 'berjalan')->count(),
            'telat' => $sewa->filter(fn ($s) => $s->denda > 0)->count(),
            'kerusakan' => (int) $sewa->sum('biaya_kerusakan'),
        ];
    }
}
