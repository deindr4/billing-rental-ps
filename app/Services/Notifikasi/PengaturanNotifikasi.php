<?php

namespace App\Services\Notifikasi;

use App\Models\Pengaturan;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Pengaturan notifikasi per cabang (Telegram & WhatsApp).
 */
final class PengaturanNotifikasi
{
    public function __construct(private string $cabangId) {}

    public static function untuk(string $cabangId): self
    {
        return new self($cabangId);
    }

    private function ambil(string $kunci, mixed $default = null): mixed
    {
        return Pengaturan::ambil("notif.{$kunci}", $default, $this->cabangId);
    }

    public function simpan(string $kunci, mixed $nilai): void
    {
        Pengaturan::simpan("notif.{$kunci}", $nilai, $this->cabangId);
    }

    /* Telegram */

    public function telegramAktif(): bool
    {
        return (bool) $this->ambil('telegram.aktif', false) && $this->telegramToken() && $this->telegramChatId();
    }

    public function telegramToken(): ?string
    {
        $terenkripsi = $this->ambil('telegram.token');

        if (! $terenkripsi) {
            return null;
        }

        try {
            return Crypt::decryptString($terenkripsi);
        } catch (Throwable) {
            return null;
        }
    }

    public function simpanTelegramToken(string $token): void
    {
        $this->simpan('telegram.token', Crypt::encryptString(trim($token)));
    }

    public function telegramChatId(): ?string
    {
        return $this->ambil('telegram.chat_id') ?: null;
    }

    public function telegramTopicId(): ?string
    {
        return $this->ambil('telegram.topic_id') ?: null;
    }

    /* WhatsApp */

    public function waAktif(): bool
    {
        return (bool) $this->ambil('wa.aktif', false) && $this->waTujuan();
    }

    public function waTujuan(): ?string
    {
        return $this->ambil('wa.tujuan') ?: null;
    }

    /* Laporan */

    public function kirimSaatTutupKas(): bool
    {
        return (bool) $this->ambil('laporan.tutup_kas', true);
    }

    public function lampirkanPdf(): bool
    {
        return (bool) $this->ambil('laporan.pdf', true);
    }

    /* Lonceng */

    /** Jenis notifikasi penting yang diteruskan ke Telegram / WhatsApp (bawaan: semua yang penting) */
    public function loncengTeruskan(): array
    {
        return (array) $this->ambil('lonceng.teruskan', Lonceng::jenisPenting());
    }

    /** Nilai mentah untuk form admin */
    public function nilaiForm(): array
    {
        return [
            'lonceng_teruskan' => $this->loncengTeruskan(),
            'telegram_aktif' => (bool) $this->ambil('telegram.aktif', false),
            'telegram_token' => null,
            'telegram_chat_id' => $this->telegramChatId(),
            'telegram_topic_id' => $this->telegramTopicId(),
            'wa_aktif' => (bool) $this->ambil('wa.aktif', false),
            'wa_tujuan' => $this->waTujuan(),
            'laporan_tutup_kas' => $this->kirimSaatTutupKas(),
            'laporan_pdf' => $this->lampirkanPdf(),
        ];
    }

    public function adaTokenTelegram(): bool
    {
        return (bool) $this->ambil('telegram.token');
    }
}
