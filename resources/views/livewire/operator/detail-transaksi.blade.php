<div>
    <x-sheet wire:model="buka" :judul="$this->transaksi ? 'Transaksi · '.$this->transaksi->nomor : 'Detail Transaksi'">
        @if ($this->transaksi)
            @php
                $trx = $this->transaksi;
                $warna = ['lunas' => 'text-accent', 'belum_bayar' => 'text-st-hampir', 'dibatalkan' => 'text-danger'][$trx->status] ?? '';
                $labelStatus = \App\Livewire\Operator\DaftarTransaksi::STATUS[$trx->status] ?? $trx->status;
            @endphp

            {{-- Ringkasan --}}
            <div class="flex items-start justify-between gap-3 mb-4">
                <div class="min-w-0">
                    <div class="num text-sm">{{ $trx->nomor }}</div>
                    <div class="text-xs text-muted">
                        {{ $trx->created_at->format('d/m/Y H:i') }} · {{ $trx->user?->name }}
                    </div>
                    <div class="text-xs text-muted">
                        {{ $trx->unit?->nama ?? (\App\Livewire\Operator\DaftarTransaksi::JENIS[$trx->jenis] ?? $trx->jenis) }}
                        · {{ $trx->pelanggan_nama ?: 'Tamu' }}
                    </div>
                </div>
                <span class="badge {{ $warna }} shrink-0">{{ $labelStatus }}</span>
            </div>

            {{-- Dibatalkan --}}
            @if ($trx->isDibatalkan())
                <div class="rounded-md border border-danger px-3 py-2 mb-4 text-sm">
                    <div class="text-danger font-medium">Dibatalkan</div>
                    <div>{{ $trx->alasan_batal }}</div>
                    <div class="text-xs text-muted">
                        {{ $trx->dibatalkan_pada?->format('d/m/Y H:i') }} · {{ $this->namaPembatal }}
                    </div>
                </div>
            @endif

            {{-- Item --}}
            <div class="rounded-md border border-line mb-4 text-sm">
                <ul class="divide-y divide-line">
                    @forelse ($trx->items as $item)
                        <li class="px-3 py-2 flex justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block">{{ $item->nama }}{{ $item->qty > 1 ? ' x'.$item->qty : '' }}</span>
                                @if ($item->catatan)
                                    <span class="block text-xs text-muted">{{ $item->catatan }}</span>
                                @endif
                            </span>
                            <x-rupiah :nilai="$item->subtotal" class="shrink-0" />
                        </li>
                    @empty
                        <li class="px-3 py-2 text-muted">Belum ada item (open billing berjalan).</li>
                    @endforelse

                    @foreach ($trx->diskon as $d)
                        <li class="px-3 py-2 flex justify-between gap-3 text-accent">
                            <span>{{ $d->nama }}</span>
                            <span class="num">- Rp {{ number_format($d->nilai, 0, ',', '.') }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="px-3 py-2 border-t border-line flex justify-between font-semibold">
                    <span>Total</span>
                    <x-rupiah :nilai="$trx->total" />
                </div>
            </div>

            {{-- Pembayaran --}}
            @if ($trx->pembayaran->isNotEmpty())
                <div class="mb-4">
                    <div class="text-sm font-medium mb-1.5">Pembayaran</div>
                    <ul class="rounded-md border border-line divide-y divide-line text-sm">
                        @foreach ($trx->pembayaran as $p)
                            <li @class(['px-3 py-2 flex justify-between gap-3', 'text-muted line-through' => $p->status !== 'sukses'])>
                                <span class="min-w-0">
                                    <span class="block">{{ \App\Livewire\Operator\DetailTransaksi::METODE[$p->metode] ?? $p->metode }}</span>
                                    <span class="block text-xs text-muted">
                                        {{ $p->dibayar_pada->format('d/m H:i') }}
                                        @if ($p->metode === 'tunai' && $p->diterima)
                                            · diterima Rp {{ number_format($p->diterima, 0, ',', '.') }}
                                        @endif
                                        @if ($p->referensi)
                                            · {{ $p->referensi }}
                                        @endif
                                    </span>
                                </span>
                                <x-rupiah :nilai="$p->jumlah" class="shrink-0" />
                            </li>
                        @endforeach
                    </ul>
                    @if ($trx->kembalian > 0)
                        <div class="text-xs text-muted mt-1">Kembalian Rp {{ number_format($trx->kembalian, 0, ',', '.') }}</div>
                    @endif
                </div>
            @endif

            {{-- Riwayat sesi --}}
            @if ($trx->sesi && $trx->sesi->log->isNotEmpty())
                <details class="mb-4 text-sm">
                    <summary class="cursor-pointer select-none font-medium">Riwayat sesi ({{ $trx->sesi->log->count() }})</summary>
                    <ol class="mt-2 space-y-2 border-l border-line pl-3">
                        @foreach ($trx->sesi->log as $log)
                            <li>
                                <div>{{ \App\Livewire\Operator\DetailTransaksi::LABEL_LOG[$log->jenis] ?? $log->jenis }}</div>
                                <div class="text-xs text-muted">
                                    {{ $log->created_at->format('d/m H:i:s') }} · {{ $log->user?->name ?? 'Sistem' }}
                                    @if (! empty($log->data['menit']))
                                        · {{ $log->data['menit'] }} menit
                                    @endif
                                    @if (! empty($log->data['ke']))
                                        · {{ $log->data['dari'] ?? '' }} → {{ $log->data['ke'] }}
                                    @endif
                                    @if (! empty($log->data['alasan']))
                                        · {{ $log->data['alasan'] }}
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </details>
            @endif

            <x-slot:footer>
                @if ($this->bolehCetak())
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <button type="button" class="btn text-sm"
                                wire:click="$dispatch('buka-pratinjau-struk', { transaksiId: '{{ $trx->id }}' })">
                            Cetak struk
                        </button>
                        <a href="{{ route('struk.nota', $trx->id) }}" target="_blank" class="btn text-sm">Nota A4</a>
                        <button type="button" wire:click="$toggle('formWa')" @class(['btn text-sm', 'btn-primary' => $formWa])>Kirim WA</button>
                    </div>

                    @if ($formWa)
                        <form wire:submit="kirimWa" class="mb-3">
                            <div class="flex gap-2">
                                <input type="tel" wire:model="nomorWa" class="input flex-1" inputmode="tel"
                                       placeholder="Nomor WA pelanggan, 0812..." autofocus>
                                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="kirimWa">Kirim</button>
                            </div>
                            @error('nomorWa')
                                <p class="text-sm text-danger mt-1">{{ $message }}</p>
                            @enderror
                        </form>
                    @endif
                @endif

                <div class="grid grid-cols-2 gap-2">
                    @if (! $trx->isDibatalkan() && $trx->sisaTagihan() > 0 && $trx->items->isNotEmpty())
                        <button type="button" wire:click="bayar" class="btn btn-primary">Bayar</button>
                    @endif

                    @if ($this->bolehBatal())
                        <x-confirm-button action="batalkan"
                                          title="Batalkan transaksi?"
                                          text="Transaksi tidak dihapus, hanya ditandai batal. Butuh PIN supervisor/owner. Uang tunai yang sudah masuk dikeluarkan dari kas shift Anda."
                                          confirm-text="Ya, batalkan"
                                          danger
                                          reason
                                          pin
                                          @class(['w-full', 'col-span-2' => $trx->sisaTagihan() <= 0 || $trx->items->isEmpty()])>
                            Batalkan
                        </x-confirm-button>
                    @endif
                </div>
            </x-slot:footer>
        @endif
    </x-sheet>
</div>
