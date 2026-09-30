<div wire:poll.15s>
    {{-- Judul + cari --}}
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <div class="label">Rental station</div>
            <h1 class="text-xl font-semibold tracking-tight">Matriks Unit</h1>
        </div>
        <input type="search" wire:model.live.debounce.300ms="cari"
               class="input sm:w-64" placeholder="Cari unit...">
    </div>

    {{-- Filter status --}}
    @php
        $tab = [
            'semua' => 'Semua Unit',
            'kosong' => 'Ready',
            'main' => 'Terisi',
            'menunggu_bayar' => 'Menunggu Bayar',
            'servis' => 'Maintenance',
        ];
    @endphp
    <div class="flex gap-1.5 overflow-x-auto pb-1 mb-4">
        @foreach ($tab as $kunci => $teks)
            <button type="button" wire:click="$set('filterStatus', '{{ $kunci }}')"
                    @class([
                        'btn h-8 px-3 text-xs font-mono uppercase tracking-wider shrink-0',
                        'btn-primary' => $filterStatus === $kunci,
                        'text-muted' => $filterStatus !== $kunci,
                    ])>
                {{ $teks }} <span class="opacity-70">({{ $ringkasan[$kunci] }})</span>
            </button>
        @endforeach
    </div>

    {{-- Grid unit --}}
    @if ($units->isEmpty())
        <div class="kartu p-10 text-center text-muted">Tidak ada unit yang cocok.</div>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            @foreach ($units as $unit)
                @php $sesi = $sesiPerUnit->get($unit->id); @endphp

                <x-kartu-unit
                    wire:key="unit-{{ $unit->id }}-{{ $unit->status }}-{{ $sesi?->versi_tagihan ?? 0 }}"
                    :unit="$unit"
                    :sesi="$sesi"
                    :tv="$tvPerUnit->get($unit->id)"
                    :bisa-remote="$bisaRemote"
                    :tarif="$tarif[$unit->id] ?? null"
                    :peringatan-menit="$peringatanMenit"
                    :server-now="$serverNow">

                    @switch($unit->status)
                        @case('kosong')
                            <button type="button"
                                    wire:click="$dispatch('buka-mulai-sesi', { unitId: '{{ $unit->id }}' })"
                                    class="btn btn-primary w-full">
                                <x-ikon name="play" size="16" /> Mulai Rental
                            </button>
                            @break

                        @case('main')
                        @case('pause')
                            @if ($sesi?->sedangPilihGame())
                                <button type="button" wire:click="mulaiSekarang('{{ $sesi->id }}')"
                                        class="btn w-full mb-2 text-sm">
                                    <x-ikon name="play" size="14" /> Pelanggan siap · mulai waktu sekarang
                                </button>
                            @endif
                            <div class="flex gap-2">
                                @if ($sesi?->mode === 'paket')
                                    <button type="button" title="Tambah waktu"
                                            wire:click="$dispatch('buka-kelola-sesi', { unitId: '{{ $unit->id }}', panel: 'tambah' })"
                                            class="btn btn-ikon text-muted">
                                        <x-ikon name="jam" size="18" />
                                    </button>
                                @endif
                                <a href="{{ route('pos', ['unit' => $unit->id]) }}" wire:navigate title="Tambah F&B"
                                   class="btn btn-ikon text-muted">
                                    <x-ikon name="fnb" size="18" />
                                </a>
                                <button type="button"
                                        wire:click="$dispatch('buka-kelola-sesi', { unitId: '{{ $unit->id }}' })"
                                        class="btn flex-1">
                                    <x-ikon name="kelola" size="16" /> Kelola Sesi
                                </button>
                            </div>
                            @break

                        @case('menunggu_bayar')
                            @if ($sesi)
                                <button type="button"
                                        wire:click="$dispatch('buka-pembayaran', { transaksiId: '{{ $sesi->transaksi_id }}' })"
                                        class="btn btn-primary w-full">
                                    <x-ikon name="bayar" size="16" /> Bayar
                                </button>
                            @endif
                            @break

                        @case('servis')
                            <x-confirm-button action="tandaiSiap"
                                              :params="[$unit->id]"
                                              title="Unit sudah selesai diperbaiki?"
                                              text="Status akan kembali Ready dan unit bisa dipakai lagi."
                                              confirm-text="Ya, siap dipakai"
                                              class="w-full">
                                Tandai Siap Dipakai
                            </x-confirm-button>
                            @break

                        @default
                            <div class="h-9"></div>
                    @endswitch
                </x-kartu-unit>
            @endforeach
        </div>
    @endif

    {{-- Dialog --}}
    <livewire:operator.mulai-sesi />
    <livewire:operator.kelola-sesi />
    <livewire:operator.kelola-tv />
    <livewire:operator.pembayaran />
</div>
