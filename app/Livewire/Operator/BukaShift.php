<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\Cabang;
use App\Models\Shift;
use App\Services\Billing\ShiftService;
use App\Support\Tenancy;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.operator')]
#[Title('Buka Shift')]
class BukaShift extends Component
{
    use WithAlert;

    public ?int $kasAwal = null;

    public ?int $kasAkhirSebelumnya = null;

    /** Laci sedang dipegang kasir lain (satu laci per cabang) → tidak bisa buka, perlu serah terima */
    public ?Shift $dipegang = null;

    public function mount(ShiftService $shift, Tenancy $tenancy)
    {
        if ($shift->aktif(auth()->user(), $tenancy->cabangId())) {
            return $this->redirectRoute('rental', navigate: true);
        }

        $this->dipegang = $shift->terbukaDiCabang($tenancy->cabangId());

        // Saran kas awal: uang yang ditinggal di laci saat shift terakhir ditutup (data lama: kas fisik)
        $terakhir = Shift::query()
            ->where('status', Shift::STATUS_TUTUP)
            ->orderByDesc('ditutup_pada')
            ->orderByDesc('dibuka_pada') // serah terima & tutup bisa di detik yang sama
            ->first(['kas_fisik', 'kas_ditinggal']);
        $this->kasAkhirSebelumnya = $terakhir ? (int) ($terakhir->kas_ditinggal ?? $terakhir->kas_fisik) : null;
    }

    public function pakaiKasSebelumnya(): void
    {
        $this->kasAwal = $this->kasAkhirSebelumnya;
    }

    public function simpan(ShiftService $shift, Tenancy $tenancy)
    {
        $this->validate(
            ['kasAwal' => 'required|integer|min:0|max:100000000'],
            [
                'kasAwal.required' => 'Kas awal wajib diisi (isi 0 jika laci kosong).',
                'kasAwal.integer' => 'Kas awal harus berupa angka.',
                'kasAwal.min' => 'Kas awal tidak boleh negatif.',
            ]
        );

        try {
            $cabang = Cabang::findOrFail($tenancy->cabangId());
            $shift->buka(auth()->user(), $cabang, (int) $this->kasAwal);
        } catch (BillingException $e) {
            $this->error($e->getMessage());

            return;
        }

        $this->flashSuccess('Shift dibuka');

        return $this->redirectRoute('rental', navigate: true);
    }

    public function render()
    {
        return view('livewire.operator.buka-shift');
    }
}
