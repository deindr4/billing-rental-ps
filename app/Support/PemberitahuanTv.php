<?php

namespace App\Support;

use App\Exceptions\BillingException;
use App\Models\Pengaturan;

/**
 * Pemberitahuan sekali tampil di tengah layar TV (mis. "Mohon tenang saat bermain").
 * Dikirim kasir per unit / semua TV cabang lewat perintah remote `pemberitahuan` (APK >= 0.6.0).
 */
final class PemberitahuanTv
{
    /** Pesan cepat bawaan; bisa diganti di Admin → Pengaturan Operasional (tv.pesan_cepat) */
    public const PESAN_BAWAAN = [
        '🙏 Mohon tenang saat bermain',
        '⏰ Waktu hampir habis, hubungi kasir untuk tambah waktu',
        '🍜 Pesanan Anda sudah siap, silakan ambil di kasir',
        '🚭 Dilarang merokok di dalam ruangan',
        '🧹 Mohon jaga kebersihan, terima kasih',
        '🎮 Mohon gunakan stik dengan hati-hati',
    ];

    public const EMOJI = ['🙏', '⚠️', '⏰', '🔇', '🚭', '🧹', '🍜', '🥤', '🎮', '🏆', '🔥', '👍', '❤️', '😊', '📢', '🎉'];

    /** detik => label */
    public const DURASI = [5 => '5 dtk', 10 => '10 dtk', 15 => '15 dtk', 30 => '30 dtk', 60 => '1 mnt'];

    public const UKURAN = ['sedang' => 'Sedang', 'besar' => 'Besar', 'jumbo' => 'Jumbo'];

    public const HURUF = ['sans' => 'Biasa', 'serif' => 'Klasik', 'mono' => 'Mono'];

    public const MAKS_TEKS = 150;

    /** @return array<int, string> */
    public static function pesanCepat(?string $cabangId = null): array
    {
        $daftar = Pengaturan::ambil('tv.pesan_cepat', null, $cabangId);

        return is_array($daftar) && $daftar !== [] ? array_values($daftar) : self::PESAN_BAWAAN;
    }

    /** Validasi & rapikan isi pemberitahuan sebelum dikirim ke TV */
    public static function rapikan(array $isi): array
    {
        $teks = trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', (string) ($isi['teks'] ?? ''))));

        if ($teks === '') {
            throw new BillingException('Isi pemberitahuan belum diketik.');
        }

        if (mb_strlen($teks) > self::MAKS_TEKS) {
            throw new BillingException('Pemberitahuan maksimal '.self::MAKS_TEKS.' karakter.');
        }

        $detik = (int) ($isi['detik'] ?? 10);

        return [
            'teks' => $teks,
            'detik' => isset(self::DURASI[$detik]) ? $detik : 10,
            'ukuran' => isset(self::UKURAN[$isi['ukuran'] ?? '']) ? $isi['ukuran'] : 'besar',
            'huruf' => isset(self::HURUF[$isi['huruf'] ?? '']) ? $isi['huruf'] : 'sans',
            'tebal' => (bool) ($isi['tebal'] ?? true),
        ];
    }
}
