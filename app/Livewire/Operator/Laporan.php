<?php

namespace App\Livewire\Operator;

use App\Livewire\Concerns\PeriodeLaporan;
use App\Services\LaporanService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Laporan cabang aktif (sisi operator).
 */
#[Layout('layouts.operator')]
#[Title('Laporan')]
class Laporan extends Component
{
    use PeriodeLaporan;

    public function mount(): void
    {
        $this->siapkanPeriode();
    }

    public function render(LaporanService $laporan)
    {
        return view('livewire.operator.laporan', $this->dataLaporan($laporan));
    }
}
