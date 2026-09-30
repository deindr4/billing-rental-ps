{{-- Isi keranjang POS (dipakai di panel desktop dan sheet HP) --}}
<div class="space-y-4">
    {{-- Tujuan --}}
    <div>
        <label class="block text-sm mb-1.5">Tujuan</label>
        <select wire:model.live="tujuanUnitId" class="input">
            <option value="">Bayar langsung</option>
            @foreach ($this->unitTujuan as $s)
                <option value="{{ $s->unit_id }}">
                    Gabung ke {{ $s->unit->nama }}{{ $s->transaksi?->pelanggan_nama ? ' · '.$s->transaksi->pelanggan_nama : '' }}
                </option>
            @endforeach
        </select>
    </div>

    {{-- Item --}}
    @if ($this->isiKeranjang->isEmpty())
        <p class="text-sm text-muted py-6 text-center">Keranjang kosong. Klik produk untuk menambahkan.</p>
    @else
        <ul class="divide-y divide-line rounded-md border border-line">
            @foreach ($this->isiKeranjang as $id => $baris)
                <li wire:key="krj-{{ $id }}" class="px-3 py-2">
                    <div class="flex justify-between gap-2 text-sm">
                        <span class="min-w-0 truncate">{{ $baris['produk']->nama }}</span>
                        <x-rupiah :nilai="$baris['subtotal']" class="shrink-0" />
                    </div>
                    <div class="flex items-center justify-between gap-2 mt-1.5">
                        <span class="text-xs text-muted num">@ {{ number_format($baris['produk']->harga_jual, 0, ',', '.') }}</span>
                        <div class="flex items-center gap-1">
                            <button type="button" wire:click="kurang('{{ $id }}')" class="btn h-7 w-7 px-0" aria-label="Kurangi">−</button>
                            <span class="num w-8 text-center">{{ $baris['qty'] }}</span>
                            <button type="button" wire:click="tambah('{{ $id }}')" class="btn h-7 w-7 px-0" aria-label="Tambah">+</button>
                            <button type="button" wire:click="hapus('{{ $id }}')" class="btn btn-ghost h-7 px-2 text-xs text-danger">Hapus</button>
                        </div>
                    </div>
                    @if ($baris['produk']->lacak_stok && $baris['qty'] > $baris['produk']->sisaStok())
                        <div class="text-xs text-danger mt-1">Stok hanya {{ $baris['produk']->sisaStok() }}</div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Pelanggan (hanya bayar langsung) --}}
    @if ($tujuanUnitId === '')
        <div>
            <label class="block text-sm mb-1.5">Nama pelanggan <span class="text-muted">(opsional)</span></label>
            <input type="text" wire:model="pelanggan" class="input" maxlength="100" placeholder="Tamu">
        </div>
    @endif

    {{-- Total + tombol --}}
    <div class="border-t border-line pt-3">
        <div class="flex justify-between items-baseline mb-3">
            <span class="text-muted">Total <span class="num">({{ $this->jumlahItem }} item)</span></span>
            <x-rupiah :nilai="$this->total" class="text-2xl font-semibold" />
        </div>

        <div class="grid grid-cols-3 gap-2">
            <button type="button" wire:click="kosongkan" class="btn" @disabled($this->isiKeranjang->isEmpty())>Batal</button>
            <button type="button" wire:click="proses" wire:loading.attr="disabled" wire:target="proses"
                    class="btn btn-primary col-span-2 h-11" @disabled($this->isiKeranjang->isEmpty())>
                <span wire:loading.remove wire:target="proses">{{ $tujuanUnitId === '' ? 'Bayar' : 'Tambahkan ke Tagihan' }}</span>
                <span wire:loading wire:target="proses">Memproses...</span>
            </button>
        </div>
    </div>
</div>
