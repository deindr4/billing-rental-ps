<?php

namespace App\Livewire\Operator;

use App\Exceptions\BillingException;
use App\Livewire\Concerns\WithAlert;
use App\Models\Cabang;
use App\Models\Produk;
use App\Models\StokMutasi;
use App\Services\Billing\InventoriService;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.operator')]
#[Title('Stok')]
class Stok extends Component
{
    use WithAlert, WithPagination;

    public const TAB = [
        'saldo' => 'Saldo Stok',
        'masuk' => 'Stok Masuk',
        'opname' => 'Opname',
        'riwayat' => 'Riwayat',
    ];

    #[Url(as: 'tab', except: 'saldo')]
    public string $tab = 'saldo';

    public string $cari = '';

    public bool $hanyaMenipis = false;

    // Stok masuk
    /** @var array<int, array{produk_id:string, qty:int|null, harga:int|null}> */
    public array $masuk = [];

    public string $keteranganMasuk = '';

    public bool $dariKas = false;

    // Opname
    /** @var array<string, int|null> produk_id => qty fisik */
    public array $fisik = [];

    public string $alasanOpname = '';

    // Riwayat
    public string $jenisRiwayat = '';

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TAB)) {
            $this->tab = 'saldo';
        }

        $this->masuk = [$this->barisMasuk()];
    }

    public function gantiTab(string $tab): void
    {
        if (array_key_exists($tab, self::TAB)) {
            $this->tab = $tab;
            $this->resetValidation();
            $this->resetPage();
        }
    }

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'jenisRiwayat', 'hanyaMenipis'], true)) {
            $this->resetPage();
        }
    }

    public function bolehMasuk(): bool
    {
        return auth()->user()->can('stok.masuk');
    }

    public function bolehOpname(): bool
    {
        return auth()->user()->can('stok.opname');
    }

    public function bolehLihatLaba(): bool
    {
        return auth()->user()->can('laporan.laba');
    }

    /* ---------------- Data ---------------- */

    #[Computed]
    public function daftarProduk(): Collection
    {
        $kata = trim($this->cari);

        return Produk::aktif()
            ->where('lacak_stok', true)
            ->with('stok:id,produk_id,qty,hpp_rata')
            ->when($kata !== '', fn ($q) => $q->where(fn ($w) => $w->where('nama', 'like', "%{$kata}%")->orWhere('kode', $kata)->orWhere('barcode', $kata)))
            ->orderBy('nama')
            ->get()
            ->when($this->hanyaMenipis, fn ($c) => $c->filter(fn (Produk $p) => $p->stokMenipis())->values());
    }

    #[Computed]
    public function pilihanProduk(): Collection
    {
        return Produk::aktif()->where('lacak_stok', true)->orderBy('nama')->get(['id', 'nama']);
    }

    public function totalMasuk(): int
    {
        return array_sum(array_map(fn ($b) => (int) ($b['qty'] ?? 0) * (int) ($b['harga'] ?? 0), $this->masuk));
    }

    /* ---------------- Stok masuk ---------------- */

    public function tambahBarisMasuk(): void
    {
        $this->masuk[] = $this->barisMasuk();
    }

    public function hapusBarisMasuk(int $i): void
    {
        unset($this->masuk[$i]);
        $this->masuk = array_values($this->masuk) ?: [$this->barisMasuk()];
    }

    public function simpanMasuk(InventoriService $inventori, Tenancy $tenancy): void
    {
        if (! $this->bolehMasuk()) {
            $this->alert('Akses ditolak', 'Anda tidak punya izin untuk aksi ini.', 'error');

            return;
        }

        $this->validate([
            'masuk' => 'required|array|min:1',
            'masuk.*.produk_id' => 'required|string',
            'masuk.*.qty' => 'required|integer|min:1|max:100000',
            'masuk.*.harga' => 'required|integer|min:0|max:100000000',
            'keteranganMasuk' => 'nullable|string|max:200',
        ], [
            'masuk.*.produk_id.required' => 'Pilih produk.',
            'masuk.*.qty.required' => 'Isi jumlah.',
            'masuk.*.qty.min' => 'Minimal 1.',
            'masuk.*.harga.required' => 'Isi harga pokok (boleh 0).',
        ]);

        try {
            $nomor = $inventori->stokMasuk(
                Cabang::findOrFail($tenancy->cabangId()),
                auth()->user(),
                $this->masuk,
                trim($this->keteranganMasuk) ?: null,
                $this->dariKas
            );
        } catch (BillingException $e) {
            $this->alert('Gagal menyimpan', $e->getMessage(), 'error');

            return;
        }

        $this->masuk = [$this->barisMasuk()];
        $this->reset(['keteranganMasuk', 'dariKas']);
        unset($this->daftarProduk);
        $this->alert('Stok masuk tersimpan', $nomor, 'success');
    }

    /* ---------------- Opname ---------------- */

    /** Dipanggil dari tombol konfirmasi (parameter terakhir berisi hasil konfirmasi) */
    public function simpanOpname(array $konfirmasi = []): void
    {
        $inventori = app(InventoriService::class);
        $tenancy = app(Tenancy::class);

        if (! $this->bolehOpname()) {
            $this->alert('Akses ditolak', 'Anda tidak punya izin untuk aksi ini.', 'error');

            return;
        }

        $this->validate([
            'fisik.*' => 'nullable|integer|min:0|max:100000',
            'alasanOpname' => 'required|string|min:3|max:200',
        ], [
            'fisik.*.integer' => 'Harus angka.',
            'fisik.*.min' => 'Tidak boleh negatif.',
            'alasanOpname.required' => 'Tulis keterangan opname.',
        ]);

        try {
            $hasil = $inventori->opname(
                Cabang::findOrFail($tenancy->cabangId()),
                auth()->user(),
                $this->fisik,
                $this->alasanOpname
            );
        } catch (BillingException $e) {
            $this->alert('Gagal menyimpan', $e->getMessage(), 'error');

            return;
        }

        $this->reset(['fisik', 'alasanOpname']);
        unset($this->daftarProduk);

        $this->alert(
            'Opname tersimpan',
            $hasil['jumlah'] > 0 ? "{$hasil['jumlah']} produk disesuaikan ({$hasil['nomor']})." : 'Semua stok sudah cocok, tidak ada perubahan.',
            'success'
        );
    }

    /* ---------------- Render ---------------- */

    public function render()
    {
        $riwayat = $this->tab === 'riwayat'
            ? StokMutasi::query()
                ->with(['produk:id,nama', 'user:id,name'])
                ->when($this->jenisRiwayat !== '', fn ($q) => $q->where('jenis', $this->jenisRiwayat))
                ->when(trim($this->cari) !== '', fn ($q) => $q->whereHas('produk', fn ($p) => $p->where('nama', 'like', '%'.trim($this->cari).'%')))
                ->latest('created_at')
                ->simplePaginate(30)
            : null;

        return view('livewire.operator.stok', ['riwayat' => $riwayat]);
    }

    private function barisMasuk(): array
    {
        return ['produk_id' => '', 'qty' => null, 'harga' => null];
    }
}
