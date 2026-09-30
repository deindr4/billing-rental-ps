<?php

namespace App\Livewire\Publik;

use App\Models\Cabang;
use App\Services\Publik\BillboardService;
use App\Support\Tenancy;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Layar TV lobi/lounge: /billboard/{kode}?k=<kunci>. Tanpa login; kunci diatur di menu Lounge & Billboard.
 * Diperbarui tiap 5 detik (wire:poll); timer berjalan di browser.
 */
#[Layout('billboard.layout')]
class Billboard extends Component
{
    #[Locked]
    public string $cabangId = '';

    public function mount(string $kode): void
    {
        $cabang = Cabang::withoutGlobalScopes()->where('kode', $kode)->where('is_active', true)->first();

        abort_unless($cabang, 404);

        app(Tenancy::class)->set($cabang->tenant_id, $cabang->id);
        abort_unless(hash_equals(BillboardService::kunci($cabang), (string) request()->query('k')), 404);

        $this->cabangId = $cabang->id;
    }

    public function hydrate(): void
    {
        $cabang = Cabang::withoutGlobalScopes()->findOrFail($this->cabangId);
        app(Tenancy::class)->set($cabang->tenant_id, $cabang->id);
    }

    public function render()
    {
        $cabang = Cabang::withoutGlobalScopes()->with('tenant')->findOrFail($this->cabangId);

        return view('billboard.layar', ['d' => app(BillboardService::class)->data($cabang)])
            ->title(($cabang->tenant?->nama ?? 'Rental').' · '.$cabang->nama.' · Unit kosong & jadwal');
    }
}
