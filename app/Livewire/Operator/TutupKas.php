<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Jobs\BuatLaporanTutupKas;
use App\Livewire\Concerns\WithAlert;
use App\Models\KasMutasi;
use App\Models\Pembayaran;
use App\Models\Sesi;
use App\Models\Shift;
use App\Models\Transaksi;
use App\Models\Unit;
use App\Services\Billing\ShiftService;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.operator')]
#[Title('Tutup Kas')]
class TutupKas extends Component
{
    use WithAlert;

    public ?int $kasFisik = null;

    public string $catatan = '';

    public function mount()
    {
        if (! $this->shift) {
            return $this->redirectRoute('shift.buka', navigate: true);
        }
    }

    #[Computed]
    public function shift(): ?Shift
    {
        return app(ShiftService::class)->aktif(auth()->user(), app(Tenancy::class)->cabangId());
    }

    #[Computed]
    public function ringkasan(): array
    {
        $shift = $this->shift;

        $mutasi = KasMutasi::query()
            ->where('shift_id', $shift->id)
            ->selectRaw('jenis, SUM(jumlah) as total')
            ->groupBy('jenis')
            ->pluck('total', 'jenis');

        $perMetode = Pembayaran::query()
            ->where('shift_id', $shift->id)
            ->where('status', 'sukses')
            ->selectRaw('metode, SUM(jumlah) as total, COUNT(*) as jumlah')
            ->groupBy('metode')
            ->get()
            ->keyBy('metode');

        return [
            'kas_awal' => (int) ($mutasi['kas_awal'] ?? 0),
            'penjualan_tunai' => (int) ($mutasi['penjualan'] ?? 0),
            'pembatalan' => (int) ($mutasi['pembatalan'] ?? 0),
            'topup_tunai' => (int) ($mutasi['topup'] ?? 0),
            'modal' => (int) ($mutasi['modal'] ?? 0),
            'prive' => (int) ($mutasi['prive'] ?? 0),
            'pengeluaran' => (int) ($mutasi['pengeluaran'] ?? 0),
            'seharusnya' => (int) $mutasi->sum(),
            'qris' => (int) ($perMetode['qris']->total ?? 0),
            'transfer' => (int) ($perMetode['transfer']->total ?? 0),
            'saldo' => (int) ($perMetode['saldo']->total ?? 0),
            'jumlah_transaksi' => Pembayaran::query()
                ->where('shift_id', $shift->id)
                ->where('status', 'sukses')
                ->distinct()
                ->count('transaksi_id'),
            'jumlah_batal' => Transaksi::query()
                ->where('shift_id', $shift->id)
                ->where('status', Transaksi::STATUS_DIBATALKAN)
                ->count(),
            'sesi_aktif' => Sesi::query()->aktif()->count(),
            'menunggu_bayar' => Unit::query()->where('status', Unit::STATUS_MENUNGGU_BAYAR)->count(),
        ];
    }

    public function selisih(): ?int
    {
        return $this->kasFisik === null ? null : $this->kasFisik - $this->ringkasan['seharusnya'];
    }

    /** Dipanggil dari tombol konfirmasi */
    public function tutup(array $konfirmasi = [])
    {
        $this->validate([
            'kasFisik' => 'required|integer|min:0',
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
                trim($this->catatan) ?: null
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
                'Kas fisik Rp %s · Selisih Rp %s',
                number_format($shift->kas_fisik, 0, ',', '.'),
                number_format($shift->selisih, 0, ',', '.')
            ),
            'success'
        );

        return $this->redirectRoute('shift.buka', navigate: true);
    }

    public function render()
    {
        return view('livewire.operator.tutup-kas');
    }
}
