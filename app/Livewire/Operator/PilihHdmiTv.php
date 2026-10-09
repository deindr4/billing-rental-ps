<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\PerangkatTv;
use App\Models\Unit;
use App\Services\Tv\HdmiTvService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Panel kasir "HDMI": pilih / paksa input HDMI TV unit ini (mis. pelanggan pindah dari PS4 ke PS5 di TV yang sama).
 * TV yang sedang terbuka langsung pindah; tarif tidak berubah.
 */
class PilihHdmiTv extends Component
{
    use WithAlert;

    public bool $buka = false;

    public ?string $unitId = null;

    #[On('buka-pilih-hdmi')]
    public function bukaUntuk(string $unitId): void
    {
        if (! auth()->user()->can('rental.kelola')) {
            $this->error('Anda tidak punya izin mengatur TV');

            return;
        }

        $this->unitId = $unitId;
        unset($this->unit, $this->perangkat);

        if (! $this->perangkat || $this->perangkat->daftarInput() === []) {
            $this->error('TV unit ini belum melaporkan daftar HDMI');

            return;
        }

        $this->buka = true;
    }

    #[Computed]
    public function unit(): ?Unit
    {
        return $this->unitId ? Unit::find($this->unitId) : null;
    }

    #[Computed]
    public function perangkat(): ?PerangkatTv
    {
        return $this->unitId ? PerangkatTv::aktif()->where('unit_id', $this->unitId)->first() : null;
    }

    public function pilih(string $inputId, HdmiTvService $hdmi): void
    {
        if (! auth()->user()->can('rental.kelola') || ! $perangkat = $this->perangkat) {
            return;
        }

        try {
            $label = $hdmi->pindah($perangkat, $inputId, auth()->user());
        } catch (BillingException $e) {
            $this->alert('Gagal pindah HDMI', $e->getMessage(), 'error');

            return;
        }

        $this->buka = false;
        unset($this->perangkat);
        $this->success(__('TV :unit memakai :hdmi', ['unit' => $this->unit?->nama, 'hdmi' => $label]));
        $this->dispatch('sesi-berubah');
    }

    public function render()
    {
        return view('livewire.operator.pilih-hdmi-tv');
    }
}
