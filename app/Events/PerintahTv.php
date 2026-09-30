<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Perintah remote ke satu TV (layar mati/nyala, volume, restart).
 * Perintah juga disimpan sementara di server (lihat TvRemoteService), jadi tetap sampai lewat polling
 * jika websocket sedang putus. TV mengabaikan id perintah yang sudah pernah dijalankan.
 */
class PerintahTv implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $perangkatId,
        public array $perintah,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('tv.'.$this->perangkatId);
    }

    public function broadcastAs(): string
    {
        return 'perintah';
    }

    public function broadcastWith(): array
    {
        return $this->perintah;
    }
}
