<?php

namespace App\Services\Notifikasi;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Kirim pesan & dokumen lewat Telegram Bot API.
 */
final class TelegramService
{
    private function url(string $token, string $metode): string
    {
        return "https://api.telegram.org/bot{$token}/{$metode}";
    }

    public function kirimTeks(string $token, string $chatId, string $teks, ?string $topicId = null): void
    {
        $respon = Http::timeout(20)->asForm()->post($this->url($token, 'sendMessage'), array_filter([
            'chat_id' => $chatId,
            'text' => mb_substr($teks, 0, 4000),
            'message_thread_id' => $topicId,
            'disable_web_page_preview' => 'true',
        ]));

        $this->cek($respon);
    }

    public function kirimDokumen(string $token, string $chatId, string $path, string $namaFile, ?string $caption = null, ?string $topicId = null): void
    {
        $respon = Http::timeout(60)
            ->attach('document', file_get_contents($path), $namaFile)
            ->post($this->url($token, 'sendDocument'), array_filter([
                'chat_id' => $chatId,
                'caption' => $caption ? mb_substr($caption, 0, 1000) : null,
                'message_thread_id' => $topicId,
            ]));

        $this->cek($respon);
    }

    private function cek($respon): void
    {
        if (! $respon->successful() || ! $respon->json('ok')) {
            throw new RuntimeException('Telegram: '.($respon->json('description') ?? 'HTTP '.$respon->status()));
        }
    }
}
