<?php

namespace App\Livewire\Operator;

use App\Livewire\Concerns\WithAlert;
use App\Models\Transaksi;
use App\Services\Struk\StrukService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Pratinjau struk thermal sebelum dicetak. Tombol Cetak meneruskan ke RawBT (printer Bluetooth).
 */
class PratinjauStruk extends Component
{
    use WithAlert;

    public bool $buka = false;

    public ?string $transaksiId = null;

    #[On('buka-pratinjau-struk')]
    public function bukaUntuk(string $transaksiId): void
    {
        $user = auth()->user();

        if (! $user->can('transaksi.lihat') && ! $user->can('pembayaran.terima')) {
            $this->error('Anda tidak punya izin mencetak struk');

            return;
        }

        $this->transaksiId = $transaksiId;
        unset($this->transaksi, $this->struk);

        if (! $this->transaksi) {
            $this->error('Transaksi tidak ditemukan');

            return;
        }

        $this->buka = true;
    }

    #[Computed]
    public function transaksi(): ?Transaksi
    {
        return $this->transaksiId ? Transaksi::find($this->transaksiId) : null;
    }

    /** @return array{kolom:int, lebar:int, baris:array} */
    #[Computed]
    public function struk(): array
    {
        $layanan = app(StrukService::class);
        $data = $layanan->data($this->transaksi);
        $kolom = StrukService::LEBAR[$data['setelan']['lebar']];

        return [
            'kolom' => $kolom,
            'lebar' => $data['setelan']['lebar'],
            'baris' => $layanan->baris($data, $kolom),
        ];
    }

    public function render()
    {
        return view('livewire.operator.pratinjau-struk');
    }
}
