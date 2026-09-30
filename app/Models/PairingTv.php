<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Permintaan pairing dari TV yang belum terdaftar. Tidak ber-tenant:
 * tenant baru diketahui saat admin memasukkan kode di panel.
 */
class PairingTv extends Model
{
    use HasUuids;

    protected $table = 'pairing_tv';

    protected $fillable = ['kode', 'kunci_hash', 'android_id', 'info', 'perangkat_id', 'kredensial', 'kedaluwarsa_pada'];

    protected $hidden = ['kunci_hash', 'kredensial'];

    protected function casts(): array
    {
        return [
            'info' => 'array',
            'kredensial' => 'encrypted:array',
            'kedaluwarsa_pada' => 'datetime',
        ];
    }

    public function scopeBerlaku(Builder $query): Builder
    {
        return $query->where('kedaluwarsa_pada', '>', now());
    }
}
