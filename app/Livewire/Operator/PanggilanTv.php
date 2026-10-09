<?php

namespace App\Livewire\Operator;

use App\Models\Booking;
use App\Models\LogTv;
use App\Models\PembayaranOnline;
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
            $this->dispatch('ui:alert', icon: 'info', title: __('Booking online baru'),
                text: "{$b->nama} · ".$b->mulai_pada->translatedFormat('D d M H:i')." ({$b->kode}). ".__('Buka menu Jadwal & Booking.'));
        }

        // Bayar mandiri QRIS di TV: berhasil (info) atau perlu tindakan kasir (peringatan)
        $online = PembayaranOnline::query()->with('unit:id,nama')->whereIn('status', ['selesai', 'perlu_tindakan'])
            ->where('updated_at', '>', $batas)->orderBy('updated_at')->get();

        foreach ($online as $p) {
            $rp = 'Rp '.number_format($p->nominal, 0, ',', '.');

            $p->status === 'perlu_tindakan'
                ? $this->dispatch('ui:alert', icon: 'warning', title: __('Bayar mandiri perlu tindakan'),
                    text: "{$p->unit?->nama} · {$rp}: {$p->catatan} ".__('Buka menu Pembayaran online.'))
                : $this->dispatch('ui:toast', icon: 'success', title: __(':unit: bayar mandiri :nominal', ['unit' => $p->unit?->nama, 'nominal' => $rp]));
        }

        $this->sejak = max(
            $this->sejak,
            $panggilan->last()?->created_at->getTimestampMs() ?? 0,
            $booking->last()?->created_at->getTimestampMs() ?? 0,
            $online->last()?->updated_at->getTimestampMs() ?? 0,
        );

        if ($panggilan->isEmpty()) {
            return;
        }
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
