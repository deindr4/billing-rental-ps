<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Jobs\BuatLaporanTutupKas;
use App\Livewire\Concerns\WithAlert;
use App\Models\Shift;
use App\Models\User;
use App\Services\Billing\ShiftService;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ganti kasir (satu laci per cabang): hitung laci, tinggal modal tetap, sisanya disetor,
 * penerima mengonfirmasi dengan PIN-nya (boleh menghitung ulang) → shift penerima langsung dibuka.
 */
#[Layout('layouts.operator')]
#[Title('Serah Terima Shift')]
class SerahTerima extends Component
{
    use WithAlert;

    public ?int $kasFisik = null;

    public ?int $ditinggal = null;

    public ?string $penerimaId = null;

    public string $pin = '';

    /** Penerima menghitung ulang uang yang ditinggal */
    public bool $hitungUlang = false;

    public ?int $dihitungPenerima = null;

    public string $catatan = '';

    public function mount()
    {
        if (! $this->shift) {
            return $this->redirectRoute('shift.buka', navigate: true);
        }

        $this->ditinggal = ShiftService::modalTetap(app(Tenancy::class)->cabangId());
    }

    #[Computed]
    public function shift(): ?Shift
    {
        return app(ShiftService::class)->aktif(auth()->user(), app(Tenancy::class)->cabangId());
    }

    #[Computed]
    public function ringkasan(): array
    {
        return app(ShiftService::class)->ringkasan($this->shift);
    }

    #[Computed]
    public function potret(): array
    {
        return app(ShiftService::class)->potretBerjalan($this->shift->cabang_id);
    }

    /** Karyawan aktif di cabang ini yang boleh memegang kas, selain pemegang sekarang */
    #[Computed]
    public function calonPenerima(): Collection
    {
        $cabangId = $this->shift->cabang_id;

        return User::query()
            ->where('tenant_id', $this->shift->tenant_id)
            ->where('is_active', true)
            ->whereKeyNot($this->shift->user_id)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => $u->can('shift.kelola') && $u->cabangTersedia()->whereKey($cabangId)->exists())
            ->values();
    }

    public function selisih(): ?int
    {
        return $this->kasFisik === null ? null : $this->kasFisik - $this->ringkasan['seharusnya'];
    }

    public function ditinggalEfektif(): int
    {
        return max(0, min((int) $this->ditinggal, (int) $this->kasFisik));
    }

    public function serahkan()
    {
        $this->validate([
            'kasFisik' => 'required|integer|min:0',
            'ditinggal' => 'required|integer|min:0',
            'penerimaId' => 'required|string',
            'pin' => 'required|string',
            'dihitungPenerima' => 'nullable|integer|min:0',
            'catatan' => 'nullable|string|max:500',
        ], [
            'kasFisik.required' => 'Hitung uang di laci terlebih dahulu.',
            'penerimaId.required' => 'Pilih kasir yang menerima laci.',
            'pin.required' => 'Penerima memasukkan PIN-nya.',
        ]);

        if ($this->selisih() !== 0 && blank($this->catatan)) {
            $this->addError('catatan', 'Ada selisih kas. Tulis keterangannya.');

            return;
        }

        $penerima = $this->calonPenerima->firstWhere('id', $this->penerimaId);

        if (! $penerima) {
            $this->addError('penerimaId', 'Penerima tidak valid.');

            return;
        }

        try {
            ['lama' => $lama] = app(ShiftService::class)->serahTerima(
                $this->shift,
                auth()->user(),
                $penerima,
                $this->pin,
                (int) $this->kasFisik,
                $this->ditinggalEfektif(),
                $this->hitungUlang ? (int) $this->dihitungPenerima : null,
                trim($this->catatan) ?: null,
            );
        } catch (BillingException $e) {
            $this->pin = '';
            $this->alert('Serah terima gagal', $e->getMessage(), 'error');

            return;
        }

        // Laporan shift yang diserahkan ke Telegram / WhatsApp (bila diaktifkan)
        BuatLaporanTutupKas::dispatch($lama->id);

        $this->flashSuccess("Laci diserahkan ke {$penerima->name}");

        return $this->redirectRoute('shift.laporan', ['id' => $lama->id], navigate: true);
    }

    public function render()
    {
        return view('livewire.operator.serah-terima');
    }
}
