<?php

namespace App\Livewire\Operator;

use App\Models\Transaksi;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.operator')]
#[Title('Transaksi')]
class DaftarTransaksi extends Component
{
    use WithPagination;

    public const JENIS = [
        'billing' => 'Billing',
        'pos' => 'POS',
        'top_up' => 'Top-up',
        'turnamen' => 'Turnamen',
        'sewa_luar' => 'Sewa Playbox',
    ];

    public const STATUS = [
        'lunas' => 'Lunas',
        'belum_bayar' => 'Belum Bayar',
        'dibatalkan' => 'Dibatalkan',
    ];

    #[Url(except: '')]
    public string $cari = '';

    #[Url(except: '')]
    public string $dari = '';

    #[Url(except: '')]
    public string $sampai = '';

    #[Url(except: '')]
    public string $jenis = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->dari = $this->tanggalValid($this->dari) ?? today()->toDateString();
        $this->sampai = $this->tanggalValid($this->sampai) ?? today()->toDateString();
    }

    /** Setiap filter berubah, kembali ke halaman 1 */
    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'dari', 'sampai', 'jenis', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function hariIni(): void
    {
        $this->dari = $this->sampai = today()->toDateString();
        $this->resetPage();
    }

    public function resetFilter(): void
    {
        $this->reset(['cari', 'jenis', 'status']);
        $this->hariIni();
    }

    #[On('transaksi-berubah')]
    #[On('sesi-berubah')]
    public function segarkan(): void
    {
        // Cukup memicu render ulang
    }

    public function render()
    {
        $dari = Carbon::parse($this->tanggalValid($this->dari) ?? today()->toDateString())->startOfDay();
        $sampai = Carbon::parse($this->tanggalValid($this->sampai) ?? today()->toDateString())->endOfDay();

        if ($sampai->lt($dari)) {
            [$dari, $sampai] = [$sampai->copy()->startOfDay(), $dari->copy()->endOfDay()];
        }

        $query = Transaksi::query()
            ->whereBetween('created_at', [$dari, $sampai])
            ->when($this->jenis !== '', fn ($q) => $q->where('jenis', $this->jenis))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when(trim($this->cari) !== '', function ($q) {
                $kata = trim($this->cari);

                $q->where(function ($w) use ($kata) {
                    $w->where('nomor', 'like', $kata.'%')      // awal nomor
                        ->orWhere('nomor', 'like', '%'.$kata)  // akhir nomor, misal "0012"
                        ->orWhere('pelanggan_nama', 'like', $kata.'%');
                });
            });

        $ringkasan = (clone $query)
            ->selectRaw('COUNT(*) as jumlah')
            ->selectRaw("SUM(CASE WHEN status = 'lunas' THEN total ELSE 0 END) as omzet")
            ->selectRaw("SUM(CASE WHEN status = 'belum_bayar' THEN 1 ELSE 0 END) as belum_bayar")
            ->selectRaw("SUM(CASE WHEN status = 'dibatalkan' THEN 1 ELSE 0 END) as dibatalkan")
            ->first();

        $daftar = $query
            ->with(['unit:id,nama', 'user:id,name'])
            ->latest('created_at')
            ->simplePaginate(25);

        return view('livewire.operator.daftar-transaksi', [
            'daftar' => $daftar,
            'ringkasan' => $ringkasan,
        ]);
    }

    private function tanggalValid(?string $tanggal): ?string
    {
        if (! $tanggal) {
            return null;
        }

        return rescue(fn () => Carbon::createFromFormat('Y-m-d', $tanggal)->toDateString(), null, false);
    }
}
