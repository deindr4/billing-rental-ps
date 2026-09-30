<?php

namespace App\Jobs;

use App\Models\NotifikasiLog;
use App\Services\Notifikasi\PengaturanNotifikasi;
use App\Services\Notifikasi\TelegramService;
use App\Services\Notifikasi\WhatsappService;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Kirim satu pesan (+ lampiran opsional) ke satu saluran. Gagal -> dicoba ulang otomatis.
 */
class KirimNotifikasi implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** Jeda coba ulang (detik): internet mati tetap terkirim belakangan */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function __construct(
        public string $logId,
        public string $teks,
        public ?string $pdf = null,
        public ?string $namaFile = null,
    ) {}

    public static function antrekan(
        string $saluran,
        string $tenantId,
        ?string $cabangId,
        string $jenis,
        string $teks,
        ?string $pdf = null,
        ?string $namaFile = null,
        ?Model $referensi = null,
        ?string $tujuan = null, // kosong = tujuan dari pengaturan cabang
    ): NotifikasiLog {
        $log = NotifikasiLog::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'cabang_id' => $cabangId,
            'saluran' => $saluran,
            'jenis' => $jenis,
            'tujuan' => $tujuan,
            'status' => 'antre',
            'referensi_type' => $referensi?->getMorphClass(),
            'referensi_id' => $referensi?->getKey(),
        ]);

        self::dispatch($log->id, $teks, $pdf, $namaFile);

        return $log;
    }

    public function handle(TelegramService $telegram, WhatsappService $whatsapp, Tenancy $tenancy): void
    {
        $log = NotifikasiLog::withoutGlobalScopes()->findOrFail($this->logId);

        if ($log->status === 'terkirim') {
            return;
        }

        $tenancy->set($log->tenant_id, $log->cabang_id);
        $setelan = PengaturanNotifikasi::untuk($log->cabang_id);
        $log->increment('percobaan');

        // Teks yang sudah terkirim tidak dikirim ulang saat coba ulang (misal hanya PDF yang gagal)
        $kunciTeks = "notif:{$log->id}:teks_terkirim";

        try {
            if ($log->saluran === 'telegram') {
                $token = $setelan->telegramToken() ?? throw new RuntimeException('Token bot Telegram belum diatur.');
                $chat = $setelan->telegramChatId() ?? throw new RuntimeException('ID grup Telegram belum diatur.');
                $topik = $setelan->telegramTopicId();

                $log->update(['tujuan' => $chat]);

                if (! Cache::has($kunciTeks)) {
                    $telegram->kirimTeks($token, $chat, $this->teks, $topik);
                    Cache::put($kunciTeks, true, now()->addDays(2));
                }

                if ($this->pdf && is_file($this->pdf)) {
                    $telegram->kirimDokumen($token, $chat, $this->pdf, $this->namaFile ?? basename($this->pdf), 'Detail transaksi', $topik);
                }
            } else {
                $tujuan = $log->tujuan ?: ($setelan->waTujuan() ?? throw new RuntimeException('Tujuan WhatsApp belum diatur.'));

                $log->update(['tujuan' => $tujuan]);

                if (! Cache::has($kunciTeks)) {
                    $whatsapp->kirimTeks($tujuan, $this->teks);
                    Cache::put($kunciTeks, true, now()->addDays(2));
                }

                if ($this->pdf && is_file($this->pdf)) {
                    $whatsapp->kirimDokumen($tujuan, $this->pdf, $this->namaFile ?? basename($this->pdf), 'Detail transaksi');
                }
            }
        } catch (Throwable $e) {
            // Tetap "antre" selama masih dicoba ulang; status gagal diisi di failed()
            $log->update(['pesan_error' => mb_substr($e->getMessage(), 0, 1000)]);

            throw $e; // biar dicoba ulang oleh queue
        }

        Cache::forget($kunciTeks);
        $log->update(['status' => 'terkirim', 'pesan_error' => null, 'dikirim_pada' => now()]);
    }

    /** Semua percobaan habis */
    public function failed(?Throwable $e): void
    {
        NotifikasiLog::withoutGlobalScopes()
            ->whereKey($this->logId)
            ->where('status', '!=', 'terkirim')
            ->update([
                'status' => 'gagal',
                'pesan_error' => $e ? mb_substr($e->getMessage(), 0, 1000) : 'Gagal terkirim',
            ]);
    }
}
