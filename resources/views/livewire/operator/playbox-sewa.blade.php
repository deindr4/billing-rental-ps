<div wire:poll.60s>
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <div class="label">Sewa bawa pulang</div>
            <h1 class="text-xl font-semibold tracking-tight">Sewa Playbox</h1>
        </div>
        <div class="flex gap-2 w-full sm:w-auto">
            <input type="search" wire:model.live.debounce.300ms="cari" class="input flex-1 sm:w-56" placeholder="Cari nama / HP / nomor">
            <a href="{{ route('playbox.baru') }}" wire:navigate class="btn btn-primary h-10 px-4"><x-ikon name="plus" size="16" /> Sewa baru</a>
        </div>
    </div>

    {{-- Unit --}}
    <div class="flex gap-1.5 overflow-x-auto pb-1 mb-3">
        @foreach ($this->unit as $u)
            <span @class(['chip shrink-0', 'text-st-kosong' => $u->status === 'tersedia', 'text-st-hampir' => $u->status === 'disewa', 'text-st-servis' => $u->status === 'servis'])>
                <span class="dot"></span> {{ $u->kode }} · {{ \App\Models\Playbox::STATUS[$u->status] ?? $u->status }}
            </span>
        @endforeach
    </div>

    <div class="flex gap-1.5 mb-4">
        @foreach (['berjalan' => 'Sedang disewa', 'riwayat' => 'Riwayat'] as $k => $l)
            <button type="button" wire:click="$set('tab', '{{ $k }}')"
                    @class(['btn h-8 px-3 text-xs font-mono uppercase tracking-wider', 'btn-primary' => $tab === $k, 'text-muted' => $tab !== $k])>{{ $l }}</button>
        @endforeach
    </div>

    @if ($this->sewa->isEmpty())
        <div class="kartu p-10 text-center text-muted">{{ $tab === 'berjalan' ? 'Tidak ada Playbox yang sedang disewa.' : 'Belum ada riwayat.' }}</div>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->sewa as $s)
                @php
                    $telat = $s->telat();
                    $belumBayar = $s->transaksi && $s->transaksi->status === 'belum_bayar';
                    $warna = $s->status !== 'berjalan' ? 'var(--border)' : ($telat ? 'var(--danger)' : ($s->jatuh_tempo->lt(now()->addHours(3)) ? 'var(--status-hampir-habis)' : 'var(--status-main)'));
                @endphp
                <div wire:key="s-{{ $s->id }}" class="kartu kartu-status flex flex-col" style="--warna-status: {{ $warna }}">
                    <div class="px-4 pt-3 pb-2 flex justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold">{{ $s->playbox?->kode }} <span class="font-normal text-muted text-sm">· {{ $s->playbox?->nama }}</span></div>
                            <div class="text-xs text-muted num">{{ $s->nomor }}</div>
                        </div>
                        <span class="label shrink-0" style="color: {{ $warna }}">
                            {{ $s->status === 'berjalan' ? ($telat ? 'TELAT' : 'DISEWA') : strtoupper($s->status) }}
                        </span>
                    </div>
                    <dl class="px-4 pb-3 text-sm space-y-1 flex-1">
                        <div class="flex justify-between gap-2"><dt class="text-muted">Penyewa</dt>
                            <dd class="truncate font-medium">{{ $s->penyewa?->nama }}@if ($s->penyewa?->daftar_hitam) <span class="text-danger">⚠</span>@endif</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-muted">Tempat</dt><dd class="truncate">{{ \App\Models\Penyewa::JENIS_TEMPAT[$s->penyewa?->jenis_tempat] ?? '' }} · {{ \Illuminate\Support\Str::limit($s->alamat, 28) }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-muted">Durasi</dt><dd>{{ $s->labelDurasi() }}{{ $s->perpanjangan ? ' +'.count($s->perpanjangan).'×' : '' }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-muted">Jatuh tempo</dt>
                            <dd @class(['num', 'text-danger font-semibold' => $telat])>{{ $s->jatuh_tempo->format('d/m H:i') }}{{ $s->status === 'berjalan' ? ' · '.$s->jatuh_tempo->diffForHumans(short: true) : '' }}</dd></div>
                        @if ($s->deposit)<div class="flex justify-between gap-2"><dt class="text-muted">Deposit</dt><dd><x-rupiah :nilai="$s->deposit" /></dd></div>@endif
                        @if ($belumBayar)<div class="text-danger text-xs">Sewa belum dibayar</div>@endif
                    </dl>
                    <div class="px-3 py-2.5 border-t border-line flex flex-wrap gap-1.5">
                        @if ($belumBayar)
                            <button type="button" wire:click="bayar('{{ $s->transaksi_id }}')" class="btn btn-primary h-9 px-3 text-sm">Bayar</button>
                        @endif
                        @if ($s->status === 'berjalan')
                            <a href="{{ route('playbox.kembali', ['id' => $s->id]) }}" wire:navigate class="btn btn-tint tint-hijau h-9 px-3 text-sm">Kembali</a>
                            <button type="button" wire:click="bukaPerpanjang('{{ $s->id }}')" class="btn btn-tint tint-kuning h-9 px-3 text-sm">Perpanjang</button>
                        @endif
                        <a href="{{ route('playbox.surat', ['id' => $s->id]) }}" target="_blank" class="btn btn-ikon h-9 w-9" title="Surat sewa"><x-ikon name="transaksi" size="16" /></a>
                        @if ($s->penyewa?->telepon)
                            <a href="{{ \App\Livewire\Operator\PlayboxSewa::linkWa($s) }}" target="_blank" rel="noopener" class="btn btn-ikon h-9 w-9 text-ik-hijau" title="WhatsApp penyewa"><x-ikon name="pengumuman" size="16" /></a>
                        @endif
                        @if ($s->urlMaps())
                            <a href="{{ $s->urlMaps() }}" target="_blank" rel="noopener" class="btn btn-ikon h-9 w-9 text-ik-biru" title="Lokasi di Google Maps"><x-ikon name="cabang" size="16" /></a>
                        @endif
                        @if ($s->status === 'berjalan')
                            <x-confirm-button action="batal" :params="[$s->id]" title="Batalkan sewa {{ $s->nomor }}?"
                                              text="Tagihan dibatalkan (uang dikembalikan bila sudah dibayar), deposit dikembalikan, unit tersedia lagi."
                                              confirm-text="Ya, batalkan" danger reason class="btn-tint tint-merah h-9 px-3 text-sm ml-auto">Batal</x-confirm-button>
                        @endif
                    </div>

                    @if ($perpanjangId === $s->id)
                        <div class="px-3 py-3 border-t border-line space-y-2">
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($s->playbox?->tarif() ?? [] as $sat => $h)
                                    <button type="button" wire:click="$set('satuan', '{{ $sat }}')" @class(['btn h-8 px-2.5 text-xs', 'btn-primary' => $satuan === $sat])>
                                        {{ \App\Models\Playbox::SATUAN[$sat] }} · <x-rupiah :nilai="$h" />
                                    </button>
                                @endforeach
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="number" min="1" wire:model.live="jumlah" class="input num w-20 h-9">
                                <span class="text-sm text-muted flex-1">{{ \App\Models\Playbox::SATUAN[$satuan] ?? '' }} · <x-rupiah :nilai="($s->playbox?->harga($satuan) ?? 0) * max(1, $jumlah)" /></span>
                                <button type="button" wire:click="$set('perpanjangId', null)" class="btn h-9 px-3 text-sm">Batal</button>
                                <button type="button" wire:click="perpanjang" class="btn btn-primary h-9 px-3 text-sm">Perpanjang</button>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <livewire:operator.pembayaran />
</div>
