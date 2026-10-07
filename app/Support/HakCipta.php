<?php

namespace App\Support;

use App\Models\Pengaturan;

/**
 * Hak cipta pengembang di kaki aplikasi web (lisensi: LICENSE, syarat atribusi).
 * Teks asli wajib tetap tampil; rental boleh menambahkan namanya sendiri di sebelahnya
 * (Admin → Pengaturan Tampilan → "Teks tambahan di kaki aplikasi"). Dijaga JagaHakCipta.
 */
final class HakCipta
{
    /** "Copyright © deindr4" */
    private const ASLI = 'Q29weXJpZ2h0IMKpIGRlaW5kcjQ=';

    public static function asli(): string
    {
        return (string) base64_decode(self::ASLI, true);
    }

    /** Teks tambahan milik rental (opsional), tampil setelah teks asli */
    public static function tambahan(): ?string
    {
        return rescue(fn () => trim((string) Pengaturan::ambil('tema.footer_tambahan', '')) ?: null, null, false);
    }

    /** "Copyright © deindr4 · Delta Games" */
    public static function baris(): string
    {
        return self::asli().(($t = self::tambahan()) ? ' · '.$t : '');
    }
}
