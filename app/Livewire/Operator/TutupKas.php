<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Jobs\BuatLaporanTutupKas;
use App\Livewire\Concerns\WithAlert;
use App\Models\Shift;
use App\Services\Billing\ShiftService;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Tutup kas akhir hari (tanpa kasir penerus). Ganti kasir di tengah hari pakai Serah Terima.
 * Uang yang ditinggal untuk besok dicatat; sisanya = setoran ke owner / brankas.
 */
#[Layout('layouts.operator')]
#[Title('Tutup Kas')]
class TutupKas extends Component
{
    use WithAlert;

    public ?int $kasFisik = null;

    /** Modal kembalian yang ditinggal di laci untuk shift besok (null = modal tetap cabang) */
    public ?int $ditinggal = null;

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

    public function selisih(): ?int
    {
        return $this->kasFisik === null ? null : $this->kasFisik - $this->ringkasan['seharusnya'];
    }

    /** Uang ditinggal tidak bisa melebihi kas fisik */
    public function ditinggalEfektif(): int
    {
        return max(0, min((int) $this->ditinggal, (int) $this->kasFisik));
    }

    /** Dipanggil dari tombol konfirmasi */
    public function tutup(array $konfirmasi = [])
    {
        $this->validate([
            'kasFisik' => 'required|integer|min:0',
            'ditinggal' => 'nullable|integer|min:0',
            'catatan' => 'nullable|string|max:500',
        ], [
            'kasFisik.required' => 'Masukkan jumlah uang di laci (kas fisik).',
        ]);

        $selisih = $this->selisih();

        if ($selisih !== 0 && blank($this->catatan)) {
            $this->addError('catatan', 'Ada selisih kas. Tulis keterangannya.');

            return;
        }

        try {
            $shift = app(ShiftService::class)->tutup(
                $this->shift,
                auth()->user(),
                (int) $this->kasFisik,
                trim($this->catatan) ?: null,
                null,
                $this->ditinggalEfektif(),
            );
        } catch (BillingException $e) {
            $this->alert('Tidak bisa tutup kas', $e->getMessage(), 'error');

            return;
        }

        // Laporan otomatis ke Telegram / WhatsApp (jika diaktifkan di admin)
        BuatLaporanTutupKas::dispatch($shift->id);

        $this->flashAlert(
            'Shift ditutup',
            sprintf(
                'Kas fisik Rp %s · Selisih Rp %s · Disetor Rp %s',
                number_format($shift->kas_fisik, 0, ',', '.'),
                number_format($shift->selisih, 0, ',', '.'),
                number_format((int) $shift->setoran, 0, ',', '.')
            ),
            'success'
        );

        return $this->redirectRoute('shift.laporan', ['id' => $shift->id], navigate: true);
    }

    public function render()
    {
        return view('livewire.operator.tutup-kas');
    }
}
