<?php

namespace App\Livewire\Operator;

use App\Livewire\Concerns\PeriodeLaporan;
use App\Services\Analisa\AnalisaService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Analisa pintar (aturan otomatis, tanpa AI): temuan penting, audit kecurangan kasir, keuangan, operasional, stok.
 * Khusus pemilik izin laporan.laba (Owner).
 */
#[Layout('layouts.operator')]
#[Title('Analisa Pintar')]
class AnalisaPintar extends Component
{
    use PeriodeLaporan;

    public const TAB = [
        'temuan' => 'Temuan',
        'kasir' => 'Audit kasir',
        'keuangan' => 'Keuangan',
        'operasional' => 'Operasional',
        'stok' => 'Stok',
    ];

    #[Url(as: 'tab', except: 'temuan')]
    public string $tab = 'temuan';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('laporan.laba'), 403);

        // Analisa butuh data cukup: bawaan bulan ini
        $this->periode = 'bulan_ini';
        $this->siapkanPeriode();
    }

    public function pilihTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TAB) ? $tab : 'temuan';
    }

    public function render(AnalisaService $analisa)
    {
        [$dari, $sampai] = $this->rentang();

        return view('livewire.operator.analisa-pintar', [
            'dariTgl' => $dari,
            'sampaiTgl' => $sampai,
        ] + $analisa->semua($dari, $sampai));
    }
}
