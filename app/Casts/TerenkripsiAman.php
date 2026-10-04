<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Seperti cast "encrypted" / "encrypted:array", tapi nilai yang dienkripsi dengan APP_KEY lain (data hasil pulihkan
 * backup di PC baru, atau dari server lain lewat sinkron) dibaca sebagai null — bukan error 500 di seluruh halaman.
 * Pemakaian: 'kolom' => TerenkripsiAman::class  atau  TerenkripsiAman::class.':array'
 */
class TerenkripsiAman implements CastsAttributes
{
    public function __construct(private ?string $jenis = null) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $teks = Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }

        return $this->jenis === 'array' ? json_decode($teks, true) : $teks;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString($this->jenis === 'array' ? json_encode($value) : (string) $value);
    }
}
