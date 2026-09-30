<?php

namespace App\Livewire\Operator;

use App\Models\Booking;
use App\Models\LogTv;
use App\Models\Unit;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Tidak terlihat; memeriksa panggilan "Panggil Kasir" dari TV lalu membunyikan & menampilkan notifikasi
 * di aplikasi kasir (semua halaman). Dipasang di layout operator.
 */
class PanggilanTv extends Component
{
    /** Waktu terakhir diperiksa (unix ms) */
    public int $sejak = 0;

    public function mount(): void
    {
        $this->sejak = now()->getTimestampMs();
    }

    public function periksa(): void
    {
        // Carbon 3: createFromTimestampMs default UTC, samakan dengan zona aplikasi (kolom created_at)
        $batas = Carbon::createFromTimestampMs($this->sejak, config('app.timezone'));

        $panggilan = LogTv::query()
            ->where('jenis', 'panggil_kasir')
            ->where('created_at', '>', $batas)
            ->orderBy('created_at')
            ->get(['unit_id', 'created_at']);

        // Booking online baru juga diberitahukan ke kasir
        $booking = Booking::query()->where('sumber', 'online')->where('created_at', '>', $batas)->orderBy('created_at')->get();

        foreach ($booking as $b) {
            $this->dispatch('ui:alert', icon: 'info', title: 'Booking online baru',
                text: "{$b->nama} · ".$b->mulai_pada->translatedFormat('D d M H:i')." ({$b->kode}). Buka menu Jadwal & Booking.");
        }

        if ($panggilan->isEmpty()) {
            if ($booking->isNotEmpty()) {
                $this->sejak = $booking->last()->created_at->getTimestampMs();
            }

            return;
        }

        $this->sejak = max($panggilan->last()->created_at->getTimestampMs(), $booking->last()?->created_at->getTimestampMs() ?? 0);
        $nama = Unit::whereIn('id', $panggilan->pluck('unit_id')->filter())->pluck('nama')->unique()->implode(', ');

        $this->dispatch('ui:panggil-kasir', unit: $nama ?: 'TV');
    }

    public function render()
    {
        return <<<'HTML'
            <div wire:poll.10s="periksa" class="hidden"></div>
        HTML;
    }
}
