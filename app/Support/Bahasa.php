<?php

namespace App\Support;

use App\Models\Pengaturan;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Bahasa aplikasi. Sumber teks = Bahasa Indonesia (kunci terjemahan), terjemahan di lang/app/{kode}.json.
 *
 * Urutan pilihan: bahasa pengguna (menu akun) → bahasa pilihan tamu di sesi (halaman login / publik)
 * → bahasa bawaan cabang (Admin → Pengaturan Operasional) → APP_LOCALE (bawaan id).
 * Cabang juga menentukan bahasa struk, TV, halaman publik & pesan WA ke pelanggan.
 */
final class Bahasa
{
    /** kode => nama dalam bahasanya sendiri */
    public const DAFTAR = [
        'id' => 'Bahasa Indonesia',
        'en' => 'English',
        'ms' => 'Bahasa Melayu',
        'th' => 'ไทย',
        'vi' => 'Tiếng Việt',
        'fil' => 'Filipino',
    ];

    public const SUMBER = 'id';

    /** Pemilih bahasa di aplikasi disembunyikan sampai file terjemahan (lang/app) selesai */
    public const PEMILIH_AKTIF = false;

    /** Kalimat yang dipakai JavaScript (resources/js), dikirim lewat partials/teks-js */
    public const TEKS_JS = [
        'Yakin?', 'Ya, lanjutkan', 'Batal', 'Alasan (wajib)', 'PIN', 'Alasan wajib diisi', 'PIN wajib diisi',
        'Memproses...', 'OK', 'Tutup', ':unit memanggil kasir', 'Pelanggan meminta bantuan di unit tersebut.',
        'Lihat struk', 'Sesi berakhir', 'Silakan muat ulang halaman.', 'Akses ditolak',
        'Anda tidak punya izin untuk aksi ini.', 'Terjadi kesalahan', 'Kode :kode. Coba lagi.',
    ];

    public const KUNCI_CABANG = 'umum.bahasa';

    public static function valid(?string $kode): bool
    {
        return $kode !== null && array_key_exists($kode, self::DAFTAR);
    }

    /** Bahasa bawaan cabang (struk, TV, publik, WA pelanggan) */
    public static function cabang(?string $cabangId): string
    {
        $kode = $cabangId ? Pengaturan::ambil(self::KUNCI_CABANG, null, $cabangId) : null;

        return self::valid($kode) ? $kode : self::bawaan();
    }

    public static function bawaan(): string
    {
        $kode = config('app.locale');

        return self::valid($kode) ? $kode : self::SUMBER;
    }

    public static function untukPengguna(?User $user, ?string $cabangId, ?string $sesi = null): string
    {
        return match (true) {
            self::valid($user?->locale) => $user->locale,
            self::valid($sesi) => $sesi,
            default => self::cabang($cabangId),
        };
    }

    /**
     * Pasang bahasa untuk request / job ini. Kalimat yang belum diterjemahkan jatuh ke English (bukan ke Indonesia)
     * supaya pengguna asing tetap bisa membaca; untuk Indonesia cadangannya teks sumber.
     */
    public static function terapkan(string $kode): void
    {
        $kode = self::valid($kode) ? $kode : self::SUMBER;

        app()->setLocale($kode);
        app('translator')->setFallback($kode === self::SUMBER ? self::SUMBER : 'en');
        Carbon::setLocale($kode);
    }

    /** Jalankan $fn dalam bahasa tertentu (struk/WA/TV untuk pelanggan), lalu kembalikan bahasa semula */
    public static function dengan(string $kode, callable $fn): mixed
    {
        $semula = app()->getLocale();
        self::terapkan($kode);

        try {
            return $fn();
        } finally {
            self::terapkan($semula);
        }
    }

    /** Atribut lang HTML (fil → fil, lainnya sama) */
    public static function html(): string
    {
        return str_replace('_', '-', app()->getLocale());
    }
}
