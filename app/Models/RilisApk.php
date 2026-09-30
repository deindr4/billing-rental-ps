<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Rilis APK TV Agent (global, tidak ber-tenant).
 */
class RilisApk extends Model
{
    use HasUuids;

    protected $table = 'rilis_apk';

    protected $fillable = ['versi_nama', 'versi_kode', 'file', 'ukuran', 'sha256', 'catatan', 'wajib', 'aktif', 'dibuat_oleh'];

    protected function casts(): array
    {
        return [
            'versi_kode' => 'integer',
            'ukuran' => 'integer',
            'wajib' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('aktif', true);
    }

    /** Rilis terbaru yang lebih baru dari versi TV */
    public static function terbaruSetelah(int $versiKode): ?self
    {
        return static::aktif()->where('versi_kode', '>', $versiKode)->orderByDesc('versi_kode')->first();
    }

    public function namaFileUnduh(): string
    {
        return 'tv-agent-'.$this->versi_nama.'.apk';
    }
}
