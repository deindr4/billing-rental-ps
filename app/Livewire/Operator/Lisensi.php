<?php

namespace App\Livewire\Operator;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Lisensi MIT + syarat atribusi, kontak pengembang (Telegram) & grup WhatsApp info pengembangan. Terbuka untuk semua pengguna. */
#[Layout('layouts.operator')]
#[Title('Lisensi MIT')]
class Lisensi extends Component
{
    public function render()
    {
        $file = base_path('LICENSE');

        return view('livewire.operator.lisensi', [
            'teks' => is_file($file) ? (string) file_get_contents($file) : '',
            'versi' => trim((string) @file_get_contents(base_path('VERSION'))),
        ]);
    }
}
