<?php

namespace App\Livewire\Admin;

use App\Services\Notifikasi\Lonceng;
use Livewire\Component;

/** Lonceng di topbar panel admin: jumlah belum dibaca (semua cabang pengguna) → halaman Notifikasi aplikasi kasir */
class LoncengAdmin extends Component
{
    public function render()
    {
        $u = auth()->user();

        return view('livewire.admin.lonceng-admin', [
            'jumlah' => $u?->tenant_id ? Lonceng::jumlahBelumDibaca($u) : null,
        ]);
    }
}
