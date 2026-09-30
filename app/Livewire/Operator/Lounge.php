<?php

namespace App\Livewire\Operator;

use App\Livewire\Concerns\WithAlert;
use App\Models\Cabang;
use App\Models\Pengaturan;
use App\Services\Publik\BillboardService;
use App\Support\Tenancy;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Kelola billboard publik: link untuk dibagikan ke pelanggan (dibuka di HP), teks pengumuman, pratinjau.
 */
#[Layout('layouts.operator')]
#[Title('Billboard')]
class Lounge extends Component
{
    use WithAlert;

    public string $pengumuman = '';

    public function mount(): void
    {
        $cabangId = app(Tenancy::class)->cabangId();
        $this->pengumuman = (string) (Pengaturan::ambil('billboard.pengumuman', null, $cabangId) ?? Pengaturan::ambil('tv.pengumuman', '', $cabangId));
    }

    public function urlBillboard(): string
    {
        return BillboardService::url($this->cabang());
    }

    public function simpan(): void
    {
        $this->validate(['pengumuman' => 'nullable|string|max:500']);

        Pengaturan::simpan('billboard.pengumuman', trim($this->pengumuman), app(Tenancy::class)->cabangId());
        $this->success('Pengumuman diperbarui');
    }

    public function gantiKunci(): void
    {
        BillboardService::gantiKunci($this->cabang());
        $this->alert('Link billboard diganti', 'Link lama tidak berlaku lagi. Bagikan link yang baru.', 'success');
    }

    private function cabang(): Cabang
    {
        return Cabang::findOrFail(app(Tenancy::class)->cabangId());
    }

    public function render()
    {
        return view('livewire.operator.lounge');
    }
}
