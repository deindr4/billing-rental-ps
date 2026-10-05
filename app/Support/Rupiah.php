<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Nominal rupiah yang bisa disembunyikan tombol mata (sama dengan <x-rupiah rahasia>), untuk widget Filament.
 */
class Rupiah
{
    public static function teks(int $nilai): string
    {
        return 'Rp '.number_format($nilai, 0, ',', '.');
    }

    /** Markup HTML satu nominal: angka asli + pengganti "Rp *******" */
    public static function rahasia(int $nilai): string
    {
        return '<span class="rp-rahasia"><span class="rp-nilai">'.self::teks($nilai).'</span>'
            .'<span class="rp-tutup" aria-hidden="true">Rp *******</span></span>';
    }

    /**
     * Gabungkan teks biasa & nominal jadi HtmlString. Bagian string di-escape, int = nominal rahasia.
     *
     * @param  array<int, string|int>  $bagian
     */
    public static function html(string|int ...$bagian): HtmlString
    {
        return new HtmlString(collect($bagian)->map(fn ($b) => is_int($b) ? self::rahasia($b) : e($b))->implode(''));
    }
}
