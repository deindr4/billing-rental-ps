<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\Cabang;
use App\Models\KategoriProduk;
use App\Models\Produk;
use App\Models\Sesi;
use App\Models\Transaksi;
use App\Services\Billing\PosService;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.operator')]
#[Title('POS')]
class Pos extends Component
{
    use WithAlert;

    #[Url(as: 'kategori', except: '')]
    public string $kategoriId = '';

    public string $cari = '';

    /** @var array<string,int> produk_id => qty */
    public array $keranjang = [];

    public string $pelanggan = '';

    /** '' = bayar langsung, selain itu = unit tujuan (gabung ke tagihan sesi) */
    #[Url(as: 'unit', except: '')]
    public string $tujuanUnitId = '';

    public bool $keranjangBuka = false;

    /* ---------------- Data ---------------- */

    #[Computed]
    public function kategori(): Collection
    {
        return KategoriProduk::query()
            ->where('is_active', true)
            ->orderBy('urutan')
            ->orderBy('nama')
            ->get(['id', 'nama']);
    }

    #[Computed]
    public function produk(): Collection
    {
        $kata = trim($this->cari);

        return Produk::aktif()
            ->with('stok:id,produk_id,qty')
            ->when($this->kategoriId !== '', fn ($q) => $q->where('kategori_produk_id', $this->kategoriId))
            ->when($kata !== '', function ($q) use ($kata) {
                $q->where(function ($w) use ($kata) {
                    $w->where('nama', 'like', "%{$kata}%")
                        ->orWhere('barcode', $kata)
                        ->orWhere('kode', $kata);
                });
            })
            ->orderBy('urutan')
            ->orderBy('nama')
            ->limit(200)
            ->get();
    }

    #[Computed]
    public function isiKeranjang(): Collection
    {
        if ($this->keranjang === []) {
            return collect();
        }

        $produk = Produk::with('stok:id,produk_id,qty')
            ->whereIn('id', array_keys($this->keranjang))
            ->get()
            ->keyBy('id');

        return collect($this->keranjang)
            ->map(fn (int $qty, string $id) => $produk->has($id) ? [
                'produk' => $produk[$id],
                'qty' => $qty,
                'subtotal' => $produk[$id]->harga_jual * $qty,
            ] : null)
            ->filter();
    }

    #[Computed]
    public function total(): int
    {
        return (int) $this->isiKeranjang->sum('subtotal');
    }

    #[Computed]
    public function jumlahItem(): int
    {
        return array_sum($this->keranjang);
    }

    /** Unit yang bisa ditambahi F&B: sesi berjalan/dijeda, atau selesai tapi belum dibayar */
    #[Computed]
    public function unitTujuan(): Collection
    {
        return Sesi::query()
            ->with(['unit:id,nama,urutan', 'transaksi:id,nomor,pelanggan_nama,status'])
            ->where(function ($q) {
                $q->aktif()->orWhere(function ($q) {
                    $q->where('status', Sesi::STATUS_SELESAI)
                        ->whereHas('transaksi', fn ($t) => $t->where('status', Transaksi::STATUS_BELUM_BAYAR));
                });
            })
            ->get()
            ->filter(fn (Sesi $s) => $s->unit)
            ->sortBy(fn (Sesi $s) => $s->unit->urutan)
            ->values();
    }

    /* ---------------- Keranjang ---------------- */

    public function tambah(string $produkId): void
    {
        $this->keranjang[$produkId] = min(999, ($this->keranjang[$produkId] ?? 0) + 1);
    }

    public function kurang(string $produkId): void
    {
        if (! isset($this->keranjang[$produkId])) {
            return;
        }

        $this->keranjang[$produkId]--;

        if ($this->keranjang[$produkId] <= 0) {
            unset($this->keranjang[$produkId]);
        }
    }

    public function hapus(string $produkId): void
    {
        unset($this->keranjang[$produkId]);
    }

    public function kosongkan(): void
    {
        $this->keranjang = [];
        $this->pelanggan = '';
        $this->keranjangBuka = false;
    }

    /** Enter di kolom cari: cocokkan barcode/kode, atau tambahkan jika hasil cuma satu */
    public function scan(): void
    {
        $kata = trim($this->cari);

        if ($kata === '') {
            return;
        }

        $produk = Produk::aktif()
            ->where(fn ($q) => $q->where('barcode', $kata)->orWhere('kode', $kata))
            ->first()
            ?? ($this->produk->count() === 1 ? $this->produk->first() : null);

        if (! $produk) {
            $this->warning('Produk tidak ditemukan');

            return;
        }

        $this->tambah($produk->id);
        $this->cari = '';
        unset($this->produk);
        $this->toast($produk->nama.' ditambahkan', 'success');
    }

    /* ---------------- Proses ---------------- */

    public function proses(PosService $pos, Tenancy $tenancy): void
    {
        $this->validate(['pelanggan' => 'nullable|string|max:100']);

        try {
            if ($this->tujuanUnitId !== '') {
                $sesi = $this->unitTujuan->firstWhere('unit_id', $this->tujuanUnitId);

                if (! $sesi) {
                    throw new BillingException('Unit tujuan sudah tidak punya sesi aktif. Pilih ulang.');
                }

                $pos->tambahKeTagihan($sesi->transaksi, auth()->user(), $this->keranjang);

                $this->success('Ditambahkan ke tagihan '.$sesi->unit->nama);
                $this->kosongkan();
                $this->tujuanUnitId = '';
                $this->dispatch('sesi-berubah');

                return;
            }

            $cabang = Cabang::findOrFail($tenancy->cabangId());
            $transaksi = $pos->jual($cabang, auth()->user(), $this->keranjang, trim($this->pelanggan) ?: null);
        } catch (BillingException $e) {
            $this->alert('Tidak bisa diproses', $e->getMessage(), 'error');

            return;
        }

        $this->kosongkan();
        $this->dispatch('buka-pembayaran', transaksiId: $transaksi->id);
    }

    #[On('sesi-berubah')]
    public function segarkan(): void
    {
        unset($this->produk, $this->isiKeranjang, $this->unitTujuan);
    }

    public function render()
    {
        return view('livewire.operator.pos');
    }
}
