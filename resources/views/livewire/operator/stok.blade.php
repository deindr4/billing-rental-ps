<div>
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <h1 class="text-xl font-semibold">{{ __('Stok') }}</h1>
            <p class="text-sm text-muted">{{ __('Saldo, belanja barang, hitung fisik, dan riwayat keluar-masuk.') }}</p>
        </div>
        <a href="{{ route('pos') }}" wire:navigate class="btn h-9">{{ __('Kembali ke POS') }}</a>
    </div>

    {{-- Tab --}}
    <div class="flex gap-2 overflow-x-auto pb-1 mb-4">
        @foreach (\App\Livewire\Operator\Stok::TAB as $kode => $nama)
            <button type="button" wire:click="gantiTab('{{ $kode }}')"
                    @class(['btn h-8 px-3 text-sm shrink-0', 'btn-primary' => $tab === $kode])>{{ __($nama) }}</button>
        @endforeach
    </div>

    {{-- ================= SALDO ================= --}}
    @if ($tab === 'saldo')
        @php
            $nilaiStok = $this->daftarProduk->sum(fn ($p) => max(0, $p->sisaStok()) * (int) ($p->stok?->hpp_rata ?? 0));
        @endphp

        <div class="flex flex-wrap items-center gap-3 mb-3">
            <input type="search" wire:model.live.debounce.300ms="cari" class="input sm:w-72" placeholder="{{ __('Cari produk') }}">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model.live="hanyaMenipis"> {{ __('Hanya stok menipis') }}
            </label>
            @if ($this->bolehLihatLaba())
                <span class="text-sm text-muted sm:ml-auto">
                    {{ __('Nilai stok') }} <x-rupiah :nilai="$nilaiStok" class="text-fg font-semibold" />
                </span>
            @endif
        </div>

        <div class="surface overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-muted border-b border-line">
                    <tr>
                        <th class="px-3 py-2 font-medium text-left">{{ __('Produk') }}</th>
                        <th class="px-3 py-2 font-medium text-right">{{ __('Stok') }}</th>
                        @if ($this->bolehLihatLaba())
                            <th class="px-3 py-2 font-medium text-right hidden sm:table-cell">{{ __('HPP') }}</th>
                        @endif
                        <th class="px-3 py-2 font-medium text-right hidden sm:table-cell">{{ __('Harga jual') }}</th>
                        @if ($this->bolehLihatLaba())
                            <th class="px-3 py-2 font-medium text-right">{{ __('Margin') }}</th>
                            <th class="px-3 py-2 font-medium text-right hidden lg:table-cell">{{ __('Nilai stok') }}</th>
                        @endif
                        <th class="px-3 py-2 font-medium text-left">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($this->daftarProduk as $p)
                        @php
                            $qty = $p->sisaStok();
                            $hpp = (int) ($p->stok?->hpp_rata ?? 0);
                            $margin = $p->harga_jual - $hpp;
                            $persen = $p->harga_jual > 0 ? round($margin / $p->harga_jual * 100) : 0;
                        @endphp
                        <tr wire:key="saldo-{{ $p->id }}">
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ $p->nama }}</div>
                                <div class="text-xs text-muted">{{ $p->kode ?: '-' }} · {{ $p->satuan }}</div>
                            </td>
                            <td class="px-3 py-2 text-right num font-semibold">{{ $qty }}</td>
                            @if ($this->bolehLihatLaba())
                                <td class="px-3 py-2 text-right hidden sm:table-cell text-muted"><x-rupiah :nilai="$hpp" /></td>
                            @endif
                            <td class="px-3 py-2 text-right hidden sm:table-cell"><x-rupiah :nilai="$p->harga_jual" /></td>
                            @if ($this->bolehLihatLaba())
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                @if ($hpp > 0)
                                    <div @class([
                                        'num',
                                        'text-danger' => $margin <= 0,
                                        'text-st-hampir' => $margin > 0 && $persen < 20,
                                        'text-accent' => $persen >= 20,
                                    ])>
                                        <x-rupiah :nilai="$margin" />
                                    </div>
                                    <div class="text-xs text-muted num">{{ $persen }}%</div>
                                @else
                                    <span class="text-xs text-muted">{{ __('Belum ada HPP') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right hidden lg:table-cell"><x-rupiah :nilai="max(0, $qty) * $hpp" /></td>
                            @endif
                            <td class="px-3 py-2">
                                @if ($qty <= 0)
                                    <span class="badge text-danger">{{ __('Habis') }}</span>
                                @elseif ($p->stokMenipis())
                                    <span class="badge text-st-hampir">{{ __('Menipis') }}</span>
                                @else
                                    <span class="badge text-muted">{{ __('Aman') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-muted">{{ __('Tidak ada produk.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="text-xs text-muted mt-2">
            {{ __('HPP = harga pokok rata-rata dari stok masuk. Margin kuning di bawah 20%, merah jika rugi.') }}
        </p>

    {{-- ================= STOK MASUK ================= --}}
    @elseif ($tab === 'masuk')
        @if (! $this->bolehMasuk())
            <div class="surface p-6 text-center text-muted">{{ __('Anda tidak punya izin mencatat stok masuk.') }}</div>
        @else
            <form wire:submit="simpanMasuk" class="surface p-4 space-y-4 max-w-3xl">
                <div class="space-y-3">
                    @foreach ($masuk as $i => $b)
                        <div wire:key="masuk-{{ $i }}-{{ count($masuk) }}" class="grid gap-2 sm:grid-cols-[1fr_110px_170px_auto] items-start">
                            <div>
                                <select wire:model="masuk.{{ $i }}.produk_id" class="input" aria-label="{{ __('Produk') }}">
                                    <option value="">{{ __('Pilih produk') }}</option>
                                    @foreach ($this->pilihanProduk as $p)
                                        <option value="{{ $p->id }}">{{ $p->nama }}</option>
                                    @endforeach
                                </select>
                                @error("masuk.$i.produk_id") <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <input type="number" inputmode="numeric" min="1" wire:model.live.debounce.300ms="masuk.{{ $i }}.qty"
                                       class="input num" placeholder="{{ __('Jumlah') }}">
                                @error("masuk.$i.qty") <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-input-uang wire:model.live="masuk.{{ $i }}.harga" :placeholder="__('Harga pokok / pcs')" />
                                @error("masuk.$i.harga") <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="hapusBarisMasuk({{ $i }})" class="btn btn-ghost h-9 px-2 text-danger text-sm">{{ __('Hapus') }}</button>
                        </div>
                    @endforeach
                </div>

                <button type="button" wire:click="tambahBarisMasuk" class="btn btn-ghost text-sm text-muted">+ {{ __('Tambah baris') }}</button>

                <div>
                    <label class="block text-sm mb-1.5">{{ __('Keterangan') }} <span class="text-muted">({{ __('opsional') }})</span></label>
                    <input type="text" wire:model="keteranganMasuk" class="input" maxlength="200" placeholder="{{ __('Contoh: belanja di Toko Makmur') }}">
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="dariKas">
                    {{ __('Dibayar dari laci kas (tercatat sebagai pengeluaran shift)') }}
                </label>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3">
                    <span class="text-muted">{{ __('Total belanja') }} <x-rupiah :nilai="$this->totalMasuk()" class="text-fg text-lg font-semibold" /></span>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="simpanMasuk">{{ __('Simpan Stok Masuk') }}</button>
                </div>
            </form>
        @endif

    {{-- ================= OPNAME ================= --}}
    @elseif ($tab === 'opname')
        @if (! $this->bolehOpname())
            <div class="surface p-6 text-center text-muted">{{ __('Anda tidak punya izin melakukan opname.') }}</div>
        @else
            <p class="text-sm text-muted mb-3">{{ __('Hitung stok di rak/kulkas, lalu isi kolom Fisik. Produk yang dikosongkan tidak diubah.') }}</p>

            <input type="search" wire:model.live.debounce.300ms="cari" class="input sm:w-72 mb-3" placeholder="{{ __('Cari produk') }}">

            <form onsubmit="return false">
                <div class="surface overflow-x-auto mb-4">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-muted border-b border-line">
                            <tr>
                                <th class="px-3 py-2 font-medium">{{ __('Produk') }}</th>
                                <th class="px-3 py-2 font-medium text-right">{{ __('Sistem') }}</th>
                                <th class="px-3 py-2 font-medium w-32">{{ __('Fisik') }}</th>
                                <th class="px-3 py-2 font-medium text-right">{{ __('Selisih') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($this->daftarProduk as $p)
                                @php
                                    $isi = $fisik[$p->id] ?? null;
                                    $selisih = ($isi === null || $isi === '') ? null : (int) $isi - $p->sisaStok();
                                @endphp
                                <tr wire:key="opn-{{ $p->id }}">
                                    <td class="px-3 py-2">{{ $p->nama }}</td>
                                    <td class="px-3 py-2 text-right num">{{ $p->sisaStok() }}</td>
                                    <td class="px-3 py-2">
                                        <input type="number" inputmode="numeric" min="0"
                                               wire:model.live.debounce.400ms="fisik.{{ $p->id }}" class="input num h-8">
                                    </td>
                                    <td @class([
                                        'px-3 py-2 text-right num',
                                        'text-danger' => $selisih !== null && $selisih < 0,
                                        'text-accent' => $selisih !== null && $selisih > 0,
                                        'text-muted' => $selisih === 0 || $selisih === null,
                                    ])>
                                        {{ $selisih === null ? '-' : ($selisih > 0 ? '+'.$selisih : $selisih) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="surface p-4 space-y-3 max-w-xl">
                    <div>
                        <label class="block text-sm mb-1.5">{{ __('Keterangan') }}</label>
                        <input type="text" wire:model="alasanOpname" class="input" maxlength="200" placeholder="{{ __('Contoh: opname akhir bulan') }}">
                        @error('alasanOpname') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
                    </div>
                    <x-confirm-button action="simpanOpname"
                                      :title="__('Simpan hasil opname?')"
                                      :text="__('Stok sistem akan disesuaikan dengan hitung fisik.')"
                                      class="btn-primary w-full">
                        {{ __('Simpan Opname') }}
                    </x-confirm-button>
                </div>
            </form>
        @endif

    {{-- ================= RIWAYAT ================= --}}
    @elseif ($tab === 'riwayat')
        <div class="flex flex-wrap gap-2 mb-3">
            <input type="search" wire:model.live.debounce.300ms="cari" class="input sm:w-72" placeholder="{{ __('Cari produk') }}">
            <select wire:model.live="jenisRiwayat" class="input sm:w-48">
                <option value="">{{ __('Semua jenis') }}</option>
                @foreach (\App\Models\StokMutasi::JENIS as $kode => $nama)
                    <option value="{{ $kode }}">{{ __($nama) }}</option>
                @endforeach
            </select>
        </div>

        <div class="surface overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted border-b border-line">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ __('Waktu') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Produk') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Jenis') }}</th>
                        <th class="px-3 py-2 font-medium text-right">{{ __('Qty') }}</th>
                        @if ($lihatHpp = $this->bolehLihatLaba())
                            <th class="px-3 py-2 font-medium text-right">{{ __('Harga pokok') }}</th>
                        @endif
                        <th class="px-3 py-2 font-medium hidden md:table-cell">{{ __('Keterangan') }}</th>
                        <th class="px-3 py-2 font-medium hidden md:table-cell">{{ __('Oleh') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @php $bolehKoreksi = $this->bolehKoreksiHpp(); $kolom = $lihatHpp ? 7 : 6; @endphp
                    @forelse ($riwayat as $m)
                        <tr wire:key="mts-{{ $m->id }}">
                            <td class="px-3 py-2 num whitespace-nowrap text-muted">{{ $m->created_at->format('d/m H:i') }}</td>
                            <td class="px-3 py-2">{{ $m->produk?->nama }}</td>
                            <td class="px-3 py-2">{{ __(\App\Models\StokMutasi::JENIS[$m->jenis] ?? $m->jenis) }}</td>
                            <td @class(['px-3 py-2 text-right num', 'text-accent' => $m->qty > 0, 'text-danger' => $m->qty < 0])>
                                {{ $m->qty > 0 ? '+'.$m->qty : $m->qty }}
                            </td>
                            @if ($lihatHpp)
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    @if ($m->jenis === 'masuk' && $m->harga_pokok !== null)
                                        <x-rupiah :nilai="$m->harga_pokok" />
                                        {{-- Owner: betulkan harga pokok salah input staf (HPP & laba dihitung ulang) --}}
                                        @if ($bolehKoreksi && $koreksiId !== $m->id)
                                            <button type="button" wire:click="mulaiKoreksi('{{ $m->id }}')" class="btn-tint tint-kuning h-7 px-2 text-xs ml-1">{{ __('Koreksi') }}</button>
                                        @endif
                                    @else
                                        <span class="text-muted">–</span>
                                    @endif
                                </td>
                            @endif
                            <td class="px-3 py-2 hidden md:table-cell text-muted">{{ $m->keterangan }}</td>
                            <td class="px-3 py-2 hidden md:table-cell text-muted">{{ $m->user?->name ?? __('Sistem') }}</td>
                        </tr>
                        @if ($koreksiId === $m->id)
                            <tr wire:key="koreksi-{{ $m->id }}" class="bg-accent/5">
                                <td colspan="{{ $kolom }}" class="px-3 py-3">
                                    <div class="text-sm font-medium mb-2">{{ __('Koreksi harga pokok :produk · :qty pcs (:waktu)', ['produk' => $m->produk?->nama, 'qty' => $m->qty, 'waktu' => $m->created_at->format('d/m/Y H:i')]) }}</div>
                                    <div class="flex flex-wrap items-start gap-2">
                                        <div>
                                            <input type="number" min="0" wire:model="koreksiHarga" class="input w-40 num" placeholder="{{ __('Harga pokok / pcs') }}">
                                            @error('koreksiHarga') <div class="text-xs text-danger mt-1">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="flex-1 min-w-[220px]">
                                            <input type="text" wire:model="koreksiAlasan" class="input w-full" placeholder="{{ __('Alasan (mis. salah ketik, harga nota Rp 2.500)') }}">
                                            @error('koreksiAlasan') <div class="text-xs text-danger mt-1">{{ $message }}</div> @enderror
                                        </div>
                                        <button type="button" wire:click="simpanKoreksi" wire:loading.attr="disabled" class="btn btn-primary h-10 px-4">{{ __('Simpan') }}</button>
                                        <button type="button" wire:click="batalKoreksi" class="btn h-10 px-4">{{ __('Batal') }}</button>
                                    </div>
                                    <p class="text-xs text-muted mt-2">
                                        {{ __('HPP rata-rata & HPP penjualan sejak stok ini masuk dihitung ulang (laporan laba ikut benar). Jumlah stok tidak berubah.') }}
                                        {{ __('Bila belanja ini dibayar dari kas laci, catatan pengeluaran kas tidak ikut berubah.') }}
                                    </p>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-muted">{{ __('Belum ada riwayat.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($riwayat && (! $riwayat->onFirstPage() || $riwayat->hasMorePages()))
            <div class="flex justify-between gap-2 mt-3">
                <button type="button" wire:click="previousPage" class="btn h-9" @disabled($riwayat->onFirstPage())>{{ __('Sebelumnya') }}</button>
                <button type="button" wire:click="nextPage" class="btn h-9" @disabled(! $riwayat->hasMorePages())>{{ __('Berikutnya') }}</button>
            </div>
        @endif
    @endif
</div>
