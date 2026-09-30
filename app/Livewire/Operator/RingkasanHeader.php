<?php

namespace App\Livewire\Operator;

use App\Models\Pembayaran;
use App\Models\Unit;
use App\Services\Billing\KasService;
use App\Services\Billing\ShiftService;
use App\Support\Tenancy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Strip ringkasan di header: terisi, okupansi, omzet shift, kas laci.
 */
class RingkasanHeader extends Component
{
    #[On('sesi-berubah')]
    #[On('transaksi-berubah')]
    public function segarkan(): void
    {
        // Cukup memicu render ulang
    }

    public function render(Tenancy $tenancy, ShiftService $shifts, KasService $kas)
    {
        $status = Unit::aktif()->pluck('status');
        $total = $status->count();
        $terisi = $status->filter(fn ($s) => in_array($s, ['main', 'pause', 'menunggu_bayar'], true))->count();

        $shift = $tenancy->cabangId() ? $shifts->aktif(auth()->user(), $tenancy->cabangId()) : null;

        return view('livewire.operator.ringkasan-header', [
            'terisi' => $terisi,
            'total' => $total,
            'okupansi' => $total > 0 ? (int) round($terisi / $total * 100) : 0,
            'omzet' => $shift
                ? (int) Pembayaran::query()->where('shift_id', $shift->id)->where('status', 'sukses')->sum('jumlah')
                : 0,
            'kas' => $shift ? $kas->saldo($shift) : 0,
        ]);
    }
}
