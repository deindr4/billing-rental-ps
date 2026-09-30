<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Penyusun perintah ESC/POS sederhana untuk printer thermal 58/80 mm.
 * Teks dikonversi ke ASCII supaya aman di semua printer murah (tanpa code page khusus).
 */
final class EscPos
{
    private const ESC = "\x1B";

    private const GS = "\x1D";

    private string $buffer = '';

    public function __construct()
    {
        $this->buffer = self::ESC.'@'; // reset printer
    }

    public function rata(string $posisi): self
    {
        $this->buffer .= self::ESC.'a'.chr(match ($posisi) {
            'tengah' => 1,
            'kanan' => 2,
            default => 0,
        });

        return $this;
    }

    public function tebal(bool $aktif = true): self
    {
        $this->buffer .= self::ESC.'E'.chr($aktif ? 1 : 0);

        return $this;
    }

    /** Huruf tinggi & lebar dua kali (satu baris muat setengah kolom) */
    public function besar(bool $aktif = true): self
    {
        $this->buffer .= self::GS.'!'.chr($aktif ? 0x11 : 0x00);

        return $this;
    }

    public function baris(string $teks = ''): self
    {
        $this->buffer .= self::ascii($teks)."\n";

        return $this;
    }

    public function maju(int $baris = 1): self
    {
        $this->buffer .= self::ESC.'d'.chr(max(0, min(255, $baris)));

        return $this;
    }

    /** Potong kertas (printer tanpa pisau mengabaikan perintah ini) */
    public function potong(): self
    {
        $this->buffer .= self::GS.'V'.chr(66).chr(0);

        return $this;
    }

    public function hasil(): string
    {
        return $this->buffer;
    }

    public static function ascii(string $teks): string
    {
        $teks = strtr($teks, ['–' => '-', '—' => '-', '·' => '-', '×' => 'x', '…' => '...']);

        return preg_replace('/[^\x20-\x7E]/', '', Str::ascii($teks)) ?? '';
    }
}
