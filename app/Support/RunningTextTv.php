<?php

namespace App\Support;

use App\Models\Pengaturan;
use App\Services\Tv\NotifikasiTv;
use Illuminate\Support\Carbon;

/**
 * Running text (teks berjalan) di TV per cabang — untuk promo/iklan.
 * Dinyalakan kasir dengan durasi; bisa disembunyikan otomatis saat unit sedang dimainkan.
 * Disimpan di Pengaturan "tv.running_text" (khusus cabang), dikirim ke TV di status (`running_text`).
 */
final class RunningTextTv
{
    public const KUNCI = 'tv.running_text';

    /** menit => label; 0 = sampai dimatikan */
    public const DURASI = [15 => '15 mnt', 30 => '30 mnt', 60 => '1 jam', 120 => '2 jam', 240 => '4 jam', 480 => '8 jam', 0 => 'Sampai dimatikan'];

    public const POSISI = ['bawah' => 'Bawah', 'atas' => 'Atas'];

    public const UKURAN = ['kecil' => 'Kecil', 'sedang' => 'Sedang', 'besar' => 'Besar'];

    public const KECEPATAN = ['lambat' => 'Lambat', 'sedang' => 'Sedang', 'cepat' => 'Cepat'];

    /** nama => warna teks (#RRGGBB) */
    public const WARNA = ['putih' => '#FFFFFF', 'kuning' => '#FACC15', 'hijau' => '#4ADE80', 'biru' => '#38BDF8', 'merah' => '#F87171'];

    public static function bawaan(): array
    {
        return [
            'aktif' => false,
            'teks' => '',
            'sampai' => null,              // ISO waktu berakhir; null = sampai dimatikan
            'sembunyi_saat_main' => true,
            'posisi' => 'bawah',
            'opasitas' => 60,              // kepekatan latar strip (0 = tanpa latar, 100 = hitam pekat)
            'ukuran' => 'sedang',
            'tebal' => false,
            'kecepatan' => 'sedang',
            'warna' => 'putih',
        ];
    }

    public static function ambil(?string $cabangId): array
    {
        $isi = Pengaturan::ambil(self::KUNCI, [], $cabangId);

        return array_merge(self::bawaan(), is_array($isi) ? array_intersect_key($isi, self::bawaan()) : []);
    }

    public static function simpan(array $isi, ?string $cabangId): void
    {
        Pengaturan::simpan(self::KUNCI, array_merge(self::bawaan(), array_intersect_key($isi, self::bawaan())), $cabangId);

        if ($cabangId) {
            NotifikasiTv::cabang($cabangId, 'running_text');
        }
    }

    /** Sedang tayang (aktif, ada teks, belum lewat waktunya) */
    public static function tayang(array $rt): bool
    {
        return $rt['aktif'] && trim((string) $rt['teks']) !== ''
            && ($rt['sampai'] === null || now()->lt($rt['sampai']));
    }

    /** Payload untuk API TV; null = tidak tayang */
    public static function untukApi(?string $cabangId): ?array
    {
        $rt = self::ambil($cabangId);

        if (! self::tayang($rt)) {
            return null;
        }

        return [
            'teks' => trim(preg_replace('/\s+/u', ' ', (string) $rt['teks'])),
            'sampai_ms' => $rt['sampai'] ? Carbon::parse($rt['sampai'])->getTimestampMs() : null,
            'sembunyi_saat_main' => (bool) $rt['sembunyi_saat_main'],
            'posisi' => $rt['posisi'],
            'opasitas' => max(0, min(100, (int) $rt['opasitas'])),
            'ukuran' => $rt['ukuran'],
            'tebal' => (bool) $rt['tebal'],
            'kecepatan' => $rt['kecepatan'],
            'warna' => self::WARNA[$rt['warna']] ?? '#FFFFFF',
        ];
    }
}
