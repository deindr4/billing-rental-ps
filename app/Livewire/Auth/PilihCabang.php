<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Pilih Cabang')]
class PilihCabang extends Component
{
    #[Computed]
    public function daftarCabang(): Collection
    {
        return auth()->user()->cabangTersedia()->get(['id', 'kode', 'nama', 'alamat']);
    }

    public function pilih(string $cabangId)
    {
        $cabang = auth()->user()->cabangTersedia()->whereKey($cabangId)->first();

        if (! $cabang) {
            $this->addError('cabang', __('Cabang tidak tersedia untuk akun Anda.'));

            return;
        }

        session()->put('cabang_id', $cabang->id);

        return $this->redirectRoute('rental', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.pilih-cabang');
    }
}
