<div>
    <x-sheet wire:model="buka"
             :judul="$this->transaksi ? __('Pembayaran · :nama', ['nama' => $this->transaksi->unit?->nama ?? $this->transaksi->nomor]) : __('Pembayaran')">
        @if ($this->transaksi)
            @php $trx = $this->transaksi; @endphp

            {{-- Total --}}
            <div class="text-center mb-4">
                <div class="text-sm text-muted">{{ $this->tagihanGabung->isNotEmpty() ? __('Total gabungan :n tagihan', ['n' => $this->tagihanGabung->count() + 1]) : __('Total tagihan') }}</div>
                <x-rupiah :nilai="$this->sisa" class="text-3xl font-semibold" />
                <div class="text-xs text-muted num">{{ $trx->nomor }} · {{ $trx->pelanggan_nama ?: __('Tamu') }}</div>
            </div>

            {{-- Rincian --}}
            <details class="rounded-md border border-line mb-4 text-sm">
                <summary class="px-3 py-2 cursor-pointer select-none">{{ __('Rincian (:n item)', ['n' => $trx->items->count()]) }}</summary>
                <ul class="divide-y divide-line border-t border-line">
                    @foreach ($trx->items as $item)
                        <li class="px-3 py-2 flex justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block">{{ $item->nama }}</span>
                                @if ($item->catatan)
                                    <span class="block text-xs text-muted">{{ $item->catatan }}</span>
                                @endif
                            </span>
                            <x-rupiah :nilai="$item->subtotal" class="shrink-0" />
                        </li>
                    @endforeach
                    @foreach ($trx->diskon->where('nilai', '>', 0) as $d)
                        <li class="px-3 py-2 flex justify-between gap-3 text-accent">
                            <span>{{ $d->nama }}</span>
                            <span class="shrink-0">-<x-rupiah :nilai="$d->nilai" /></span>
                        </li>
                    @endforeach
                </ul>
            </details>

            {{-- Bayar sekaligus dengan tagihan unit lain / POS (rombongan main di beberapa TV, bayar sekali) --}}
            @if ($this->tagihanLain->isNotEmpty())
                <details class="rounded-md border border-line mb-4 text-sm" @if ($this->tagihanGabung->isNotEmpty()) open @endif>
                    <summary class="px-3 py-2 cursor-pointer select-none flex items-center justify-between gap-2">
                        <span>{{ __('Bayar sekaligus dengan tagihan lain') }}</span>
                        @if ($this->tagihanGabung->isNotEmpty())
                            <span class="label text-accent">{{ __('+:n tagihan', ['n' => $this->tagihanGabung->count()]) }}</span>
                        @endif
                    </summary>
                    <ul class="divide-y divide-line border-t border-line">
                        @foreach ($this->tagihanLain as $lain)
                            @php $dipilih = in_array($lain->id, $gabung, true); @endphp
                            <li wire:key="gabung-{{ $lain->id }}">
                                <label class="px-3 py-2 flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" class="size-4" @checked($dipilih) wire:click="toggleGabung('{{ $lain->id }}')">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-medium">{{ $lain->unit?->nama ?? __('POS / F&B') }}</span>
                                        <span class="block text-xs text-muted num">{{ $lain->nomor }}{{ $lain->pelanggan_nama ? ' · '.$lain->pelanggan_nama : '' }}</span>
                                    </span>
                                    <x-rupiah :nilai="$lain->sisaTagihan()" @class(['shrink-0', 'text-accent font-medium' => $dipilih]) />
                                </label>
                            </li>
                        @endforeach
                    </ul>
                    <p class="px-3 py-2 border-t border-line text-xs text-muted">
                        {{ __('Tiap unit tetap tercatat sendiri di laporan; struknya satu (gabungan). Hanya tagihan yang sesinya sudah selesai.') }}
                    </p>
                </details>
            @endif

            {{-- Member: pilih, saldo, tukar poin & stamp --}}
            @if ($this->programMemberAktif && $trx->jenis !== \App\Models\Transaksi::JENIS_TOP_UP)
                <div class="mb-4 space-y-3">
                    @include('livewire.operator.partials.pilih-member')

                    @if ($this->member)
                        @php
                            $aturan = $this->aturanMember();
                            $m = $this->member;
                        @endphp

                        <div class="flex flex-wrap gap-2">
                            @if ($m->saldo > 0 && $this->sisa > 0)
                                <button type="button" wire:click="pakaiSaldo" class="btn btn-tint tint-indigo h-9 px-3 text-sm">
                                    {{ __('Bayar pakai saldo') }}
                                </button>
                            @endif
                            @if ($aturan->targetStamp() > 0 && $aturan->hadiahStampMenit() > 0 && $m->stamp >= $aturan->targetStamp() && $trx->unit_id && $this->sisa > 0)
                                <button type="button" wire:click="tukarStamp" wire:loading.attr="disabled" class="btn btn-tint tint-indigo h-9 px-3 text-sm">
                                    {{ __('Tukar :n stamp · gratis :lama', ['n' => $aturan->targetStamp(), 'lama' => \App\Models\Sesi::formatDurasi($aturan->hadiahStampMenit() * 60)]) }}
                                </button>
                            @endif
                        </div>

                        @if ($aturan->nilaiPoin() > 0 && $m->poin >= $aturan->minTukarPoin() && $this->sisa > 0)
                            <div>
                                <div class="flex gap-2">
                                    <input type="number" inputmode="numeric" min="{{ $aturan->minTukarPoin() }}" max="{{ $m->poin }}"
                                           wire:model="poinDitukar" class="input num flex-1" placeholder="{{ __('Tukar poin (maks :n)', ['n' => $m->poin]) }}">
                                    <button type="button" wire:click="tukarPoin" wire:loading.attr="disabled" class="btn btn-tint tint-indigo h-10 px-4 text-sm">{{ __('Tukar') }}</button>
                                </div>
                                <p class="text-xs text-muted mt-1">{{ __('1 poin =') }} <x-rupiah :nilai="$aturan->nilaiPoin()" />, {{ __('minimal :n poin.', ['n' => $aturan->minTukarPoin()]) }}</p>
                                @error('poinDitukar') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        @foreach ($this->penukaran() as $d)
                            <div wire:key="tukar-{{ $d->id }}" class="flex items-center justify-between gap-3 rounded-md border border-line px-3 py-2 text-sm">
                                <span class="min-w-0 truncate">{{ $d->nama }} · <span class="text-accent">-<x-rupiah :nilai="$d->nilai" /></span></span>
                                <button type="button" wire:click="batalTukar('{{ $d->id }}')" class="text-xs text-danger shrink-0">{{ __('Batal') }}</button>
                            </div>
                        @endforeach
                    @endif
                </div>
            @endif

            {{-- Metode pembayaran --}}
            <form id="form-bayar" wire:submit="simpan" class="space-y-3">
                @foreach ($baris as $i => $b)
                    <div wire:key="baris-{{ $i }}-{{ count($baris) }}-{{ $b['metode'] }}"
                         class="rounded-md border border-line p-3 space-y-3">

                        <div class="flex items-center gap-2">
                            <div @class(["grid gap-1 flex-1", "grid-cols-4" => count($this->metodeTersedia()) === 4, "grid-cols-3" => count($this->metodeTersedia()) !== 4])>
                                @foreach ($this->metodeTersedia() as $kode => $nama)
                                    <button type="button"
                                            wire:click="$set('baris.{{ $i }}.metode', '{{ $kode }}')"
                                            @class(['btn h-8 text-sm', 'btn-primary' => $b['metode'] === $kode])>
                                        {{ __($nama) }}
                                    </button>
                                @endforeach
                            </div>
                            @if (count($baris) > 1)
                                <button type="button" wire:click="hapusBaris({{ $i }})"
                                        class="btn btn-ghost h-8 px-2 text-sm text-danger">{{ __('Hapus') }}</button>
                            @endif
                        </div>

                        @if (count($baris) > 1)
                            <div>
                                <label class="block text-xs text-muted mb-1">{{ __('Nominal :metode', ['metode' => __($this->metodeTersedia()[$b['metode']] ?? $b['metode'])]) }}</label>
                                <x-input-uang wire:model.live="baris.{{ $i }}.jumlah" />
                                @error("baris.$i.jumlah")
                                    <p class="text-sm text-danger mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        @if ($b['metode'] === 'tunai')
                            <div>
                                <label class="block text-xs text-muted mb-1">{{ __('Uang diterima') }}</label>
                                <x-input-uang wire:model.live="baris.{{ $i }}.diterima" class="text-lg" />
                                <div class="flex flex-wrap gap-2 mt-2">
                                    @foreach ($this->saranTunai((int) $b['jumlah']) as $n)
                                        <button type="button" wire:click="setDiterima({{ $i }}, {{ $n }})"
                                                class="btn h-8 px-3 text-sm num">
                                            {{ $n === (int) $b['jumlah'] ? __('Uang pas') : number_format($n, 0, ',', '.') }}
                                        </button>
                                    @endforeach
                                </div>
                                @error("baris.$i.diterima")
                                    <p class="text-sm text-danger mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        @else
                            @if ($b['metode'] === 'qris' && ($qr = $this->qris((int) $b['jumlah'])))
                                <div class="flex flex-col items-center gap-2 rounded-md bg-white p-3" wire:key="qr-{{ $i }}-{{ (int) $b['jumlah'] }}">
                                    <canvas x-data x-init="buatQr($el, @js($qr))"></canvas>
                                    <div class="text-center text-sm" style="color:#0f1c2b">
                                        <div class="font-semibold">{{ app(\App\Services\Publik\QrisService::class)->merchant($qr)['nama'] }}</div>
                                        <div class="text-lg font-bold num">Rp {{ number_format((int) $b['jumlah'], 0, ',', '.') }}</div>
                                        <div class="text-xs" style="color:#5b6b80">{{ __('Scan dengan aplikasi bank / e-wallet · nominal otomatis') }}</div>
                                    </div>
                                </div>
                                <p class="text-xs text-muted">{{ __('Tekan Bayar setelah notifikasi dana masuk di HP/rekening rental.') }}</p>
                            @endif
                            <div>
                                <label class="block text-xs text-muted mb-1">{{ __('Referensi') }} <span class="opacity-70">({{ __('opsional') }})</span></label>
                                <input type="text" wire:model="baris.{{ $i }}.referensi" class="input" maxlength="100"
                                       placeholder="{{ $b['metode'] === 'qris' ? __('ID / jam transaksi QRIS') : __('Nama pengirim / bank') }}">
                            </div>
                        @endif
                    </div>
                @endforeach

                @error('baris')
                    <p class="text-sm text-danger">{{ $message }}</p>
                @enderror

                @if ($baris === [])
                    <p class="rounded-md border border-line px-3 py-2.5 text-sm text-muted">
                        {{ __('Seluruh tagihan tertutup potongan member. Tekan Bayar untuk melunasi Rp0.') }}
                    </p>
                @elseif (count($baris) < count($this->metodeTersedia()))
                    <button type="button" wire:click="tambahBaris" class="btn btn-ghost w-full text-sm text-muted">
                        + {{ __('Bagi ke metode lain (split)') }}
                    </button>
                @endif
            </form>

            <x-slot:footer>
                <dl class="text-sm space-y-1 mb-3">
                    @if (count($baris) > 1)
                        <div class="flex justify-between">
                            <dt class="text-muted">{{ __('Total dibayar') }}</dt>
                            <dd><x-rupiah :nilai="$this->totalInput()" /></dd>
                        </div>
                        @if ($this->kurang() > 0)
                            <div class="flex justify-between text-danger">
                                <dt>{{ __('Kurang') }}</dt>
                                <dd><x-rupiah :nilai="$this->kurang()" /></dd>
                            </div>
                        @endif
                    @endif
                    <div class="flex justify-between items-baseline">
                        <dt class="text-muted">{{ __('Kembalian') }}</dt>
                        <dd><x-rupiah :nilai="$this->kembalian()" class="text-2xl font-semibold text-accent" /></dd>
                    </div>
                </dl>

                <button type="submit" form="form-bayar" class="btn btn-primary w-full h-11"
                        wire:loading.attr="disabled" wire:target="simpan"
                        @disabled($this->totalInput() !== $this->sisa)>
                    <span wire:loading.remove wire:target="simpan">{{ __('Bayar') }}</span>
                    <span wire:loading wire:target="simpan">{{ __('Memproses...') }}</span>
                </button>
            </x-slot:footer>
        @endif
    </x-sheet>
</div>
