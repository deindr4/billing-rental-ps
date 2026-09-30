<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Memberi tahu satu TV bahwa statusnya berubah; TV lalu mengambil ulang GET /api/tv/status.
 * Isi status sengaja tidak dikirim lewat websocket supaya satu sumber kebenaran (API).
 * Dikirim lewat antrean & setelah transaksi DB selesai; jika Reverb mati, TV tetap polling.
 */
class TvSegarkan implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public bool $afterCommit = true;

    public function __construct(
        public string $perangkatId,
        public string $alasan,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('tv.'.$this->perangkatId);
    }

    public function broadcastAs(): string
    {
        return 'segarkan';
    }

    public function broadcastWith(): array
    {
        return ['alasan' => $this->alasan, 'waktu_ms' => now()->getTimestampMs()];
    }
}
