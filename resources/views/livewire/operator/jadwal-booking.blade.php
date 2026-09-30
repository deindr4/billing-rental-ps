@php
    $warna = ['menunggu' => 'var(--status-hampir-habis)', 'dikonfirmasi' => 'var(--status-kosong)', 'checkin' => 'var(--status-main)', 'batal' => 'var(--status-offline)', 'tidak_datang' => 'var(--text-muted)'];
    $l = $this->linimasa;
@endphp
<div wire:poll.30s="segarkan">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <div class="label">Booking online & telepon · {{ $this->menunggu }} menunggu konfirmasi</div>
            <h1 class="text-xl font-semibold tracking-tight">Jadwal & Booking</h1>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="geser(-1)" class="btn h-10 px-3">‹</button>
            <input type="date" wire:model.live="tanggal" class="input h-10 w-40">
            <button type="button" wire:click="geser(1)" class="btn h-10 px-3">›</button>
            <button type="button" wire:click="$set('tanggal', '{{ today()->toDateString() }}')" class="btn h-10 px-3 text-sm">Hari ini</button>
            <button type="button" wire:click="bukaForm" class="btn btn-primary h-10 px-4"><x-ikon name="plus" size="16" /> Booking</button>
        </div>
    </div>

    @unless ($this->aturan['aktif'])
        <div class="kartu p-3 mb-4 text-sm text-muted">
            Booking online belum dibuka untuk pelanggan (Admin → Pengaturan → Operasional → Booking). Booking lewat kasir tetap bisa.
        </div>
    @endunless

    {{-- ================= Linimasa ================= --}}
    <section class="kartu p-4 mb-4 overflow-x-auto">
        <div class="min-w-[720px]">
            <div class="flex items-center mb-2">
                <div class="w-32 shrink-0 label">{{ $this->hari->translatedFormat('l, d M') }}</div>
                <div class="flex-1 flex justify-between label">
                    @foreach ($l['jam'] as $j) <span>{{ $j }}</span> @endforeach
                </div>
            </div>
            @foreach ($l['baris'] as $r)
                <div wire:key="lini-{{ $r['unit']->id }}" class="flex items-center h-9 border-t border-line">
                    <div class="w-32 shrink-0 text-sm truncate pr-2">{{ $r['unit']->nama }}</div>
                    <div class="flex-1 relative h-6 rounded bg-bg">
                        @if ($r['sesi'])
                            <div class="absolute inset-y-0 rounded opacity-40" style="left: {{ $r['sesi']['kiri'] }}%; width: {{ $r['sesi']['lebar'] }}%; background: var(--status-main)" title="Sesi berjalan"></div>
                        @endif
                        @foreach ($r['blok'] as $bl)
                            <div class="absolute inset-y-0 rounded px-1.5 text-[11px] leading-6 truncate font-medium"
                                 style="left: {{ $bl['kiri'] }}%; width: {{ $bl['lebar'] }}%; background: color-mix(in srgb, {{ $warna[$bl['b']->status] }} 35%, transparent); border: 1px solid {{ $warna[$bl['b']->status] }}"
                                 title="{{ $bl['b']->kode }} · {{ $bl['b']->nama }}">
                                {{ $bl['b']->mulai_pada->format('H:i') }} {{ $bl['b']->nama }}
                            </div>
                        @endforeach
                        @if ($l['sekarang'] !== null)
                            <div class="absolute inset-y-[-4px] w-0.5 bg-danger" style="left: {{ $l['sekarang'] }}%"></div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ================= Daftar ================= --}}
    <section class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->daftar as $b)
            <div wire:key="bk-{{ $b->id }}" class="kartu kartu-status p-4 space-y-2" style="--warna-status: {{ $warna[$b->status] }}">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-semibold truncate">{{ $b->nama }}
                            @if ($b->member) <span class="chip">{{ $b->member->tier }}</span> @endif
                        </div>
                        <div class="text-xs text-muted num">{{ $b->kode }} · {{ $b->sumber === 'online' ? 'Online' : 'Kasir' }}</div>
                    </div>
                    <span class="chip shrink-0" style="color: {{ $warna[$b->status] }}; border-color: {{ $warna[$b->status] }}">{{ \App\Models\Booking::STATUS[$b->status] }}</span>
                </div>
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-2xl font-semibold num">{{ $b->mulai_pada->format('H:i') }}<span class="text-sm text-muted">–{{ $b->selesai_pada->format('H:i') }}</span></span>
                    <x-rupiah :nilai="$b->perkiraan_harga" class="text-sm text-muted" />
                </div>
                <div class="text-sm flex flex-wrap items-center gap-x-3 gap-y-1">
                    @if ($b->isAktif())
                        <select wire:change="pindahUnit('{{ $b->id }}', $event.target.value)" class="input h-8 w-auto text-sm py-0">
                            @foreach ($this->units as $u)
                                <option value="{{ $u->id }}" @selected($u->id === $b->unit_id)>{{ $u->nama }}</option>
                            @endforeach
                        </select>
                    @else
                        <span>{{ $b->unit?->nama }}</span>
                    @endif
                    <a href="https://wa.me/{{ preg_replace('/^0/', '62', $b->telepon) }}" target="_blank" class="num text-accent">{{ $b->telepon }}</a>
                </div>
                @if ($b->catatan) <p class="text-xs text-muted">{{ $b->catatan }}</p> @endif
                @if ($b->alasan_batal) <p class="text-xs text-danger">{{ $b->alasan_batal }}</p> @endif

                @if ($b->isAktif())
                    <div class="flex flex-wrap justify-end gap-1 pt-1">
                        <x-confirm-button action="batal" :params="[$b->id]" title="Batalkan booking {{ $b->kode }}?" reason danger class="h-8 px-2 text-xs">Batal</x-confirm-button>
                        @if ($b->mulai_pada->isPast())
                            <x-confirm-button action="tidakDatang" :params="[$b->id]" title="Tandai tidak datang?" class="h-8 px-2 text-xs">Tidak datang</x-confirm-button>
                        @endif
                        @if ($b->status === 'menunggu')
                            <button type="button" wire:click="konfirmasi('{{ $b->id }}')" class="btn h-8 px-3 text-xs">Konfirmasi</button>
                        @endif
                        @if ($b->mulai_pada->isToday() || $b->mulai_pada->lt(now()->addHour()))
                            <button type="button" wire:click="checkin('{{ $b->id }}')" class="btn btn-primary h-8 px-3 text-xs">Datang · mulai</button>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="kartu p-8 text-center text-muted md:col-span-2 xl:col-span-3">Belum ada booking di tanggal ini.</div>
        @endforelse
    </section>

    {{-- ================= Booking baru (kasir) ================= --}}
    <x-sheet wire:model="formBuka" judul="Booking baru">
        <form id="form-booking" wire:submit="simpan" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm mb-1.5">Nama</label>
                    <input type="text" wire:model="form.nama" class="input" maxlength="100">
                    @error('form.nama') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm mb-1.5">No. HP / WA</label>
                    <input type="tel" wire:model="form.telepon" class="input num" maxlength="20">
                    @error('form.telepon') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm mb-1.5">Tanggal</label>
                    <input type="date" wire:model.live="form.tanggal" class="input">
                </div>
                <div>
                    <label class="block text-sm mb-1.5">Durasi (menit)</label>
                    <input type="number" wire:model.live.debounce.400ms="form.durasi" class="input num" min="30" max="720" step="30">
                </div>
            </div>
            <div>
                <label class="block text-sm mb-1.5">Jam</label>
                <div class="grid grid-cols-4 sm:grid-cols-6 gap-1.5 max-h-48 overflow-y-auto">
                    @forelse ($this->slotKasir as $s)
                        <button type="button" wire:click="$set('form.jam', '{{ $s['jam'] }}')" @class(['btn h-9 text-sm num', 'btn-primary' => ($form['jam'] ?? '') === $s['jam']])>{{ $s['jam'] }}</button>
                    @empty
                        <p class="col-span-full text-sm text-muted">Tidak ada jam kosong.</p>
                    @endforelse
                </div>
                @error('form.jam') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            @php $unitSlot = collect($this->slotKasir)->firstWhere('jam', $form['jam'] ?? '')['unit'] ?? []; @endphp
            @if ($unitSlot)
                <div>
                    <label class="block text-sm mb-1.5">Unit</label>
                    <select wire:model="form.unit_id" class="input">
                        <option value="">Otomatis</option>
                        @foreach ($unitSlot as $id => $nama) <option value="{{ $id }}">{{ $nama }}</option> @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="block text-sm mb-1.5">Catatan</label>
                <input type="text" wire:model="form.catatan" class="input" maxlength="200">
            </div>
        </form>
        <x-slot:footer>
            <button type="submit" form="form-booking" class="btn btn-primary w-full h-11" wire:loading.attr="disabled" wire:target="simpan">Simpan booking</button>
        </x-slot:footer>
    </x-sheet>

    <livewire:operator.mulai-sesi />
</div>
