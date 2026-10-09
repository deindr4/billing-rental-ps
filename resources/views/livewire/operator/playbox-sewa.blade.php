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

    {{-- Unit: ringkasan jumlah + chip per unit (geser bila banyak) --}}
    @php $hitung = $this->unit->countBy('status'); @endphp
    <div class="flex gap-1.5 overflow-x-auto pb-1 mb-3">
        <span class="chip shrink-0 font-medium">
            <span class="text-st-kosong">{{ $hitung['tersedia'] ?? 0 }} tersedia</span> ·
            <span class="text-st-hampir">{{ $hitung['disewa'] ?? 0 }} disewa</span>
            @if ($hitung['servis'] ?? 0) · <span class="text-st-servis">{{ $hitung['servis'] }} servis</span>@endif
        </span>
        @foreach ($this->unit as $u)
            <span @class(['chip shrink-0', 'text-st-kosong' => $u->status === 'tersedia', 'text-st-hampir' => $u->status === 'disewa', 'text-st-servis' => $u->status === 'servis'])>
                <span class="dot"></span> {{ $u->kode }}
            </span>
        @endforeach
    </div>

    <div class="flex items-center gap-1.5 mb-4">
        @foreach (['berjalan' => 'Sedang disewa', 'riwayat' => 'Riwayat'] as $k => $l)
            <button type="button" wire:click="$set('tab', '{{ $k }}')"
                    @class(['btn h-8 px-3 text-xs font-mono uppercase tracking-wider', 'btn-primary' => $tab === $k, 'text-muted' => $tab !== $k])>{{ $l }}</button>
        @endforeach
        {{-- Tampilan kotak / daftar --}}
        <div class="ml-auto flex rounded-md border border-line overflow-hidden">
            @foreach (['kotak' => 'Tampilan kotak', 'daftar' => 'Tampilan daftar'] as $k => $l)
                <button type="button" wire:click="$set('tampilan', '{{ $k }}')" title="{{ $l }}" aria-label="{{ $l }}"
                        @class(['h-8 w-9 grid place-items-center', 'bg-accent text-[var(--accent-contrast)]' => $tampilan === $k, 'text-muted hover:text-fg' => $tampilan !== $k])>
                    <x-ikon :name="$k" size="16" />
                </button>
            @endforeach
        </div>
    </div>

    @if ($this->sewa->isEmpty())
        <div class="kartu p-10 text-center text-muted">{{ $tab === 'berjalan' ? 'Tidak ada Playbox yang sedang disewa.' : 'Belum ada riwayat.' }}</div>
    @elseif ($tampilan === 'daftar')
        {{-- ======================= DAFTAR ======================= --}}
        <div class="surface overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted border-b border-line">
                    <tr>
                        <th class="px-3 py-2 font-medium">Unit</th>
                        <th class="px-3 py-2 font-medium">Penyewa</th>
                        <th class="px-3 py-2 font-medium hidden lg:table-cell">Tempat</th>
                        <th class="px-3 py-2 font-medium hidden md:table-cell">Durasi</th>
                        <th class="px-3 py-2 font-medium">Jatuh tempo</th>
                        <th class="px-3 py-2 font-medium text-right hidden md:table-cell">Deposit</th>
                        <th class="px-3 py-2 font-medium text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($this->sewa as $s)
                        @php
                            $telat = $s->telat();
                            $belumBayar = $s->transaksi && $s->transaksi->status === 'belum_bayar';
                            $warna = $s->status !== 'berjalan' ? 'var(--border)' : ($telat ? 'var(--danger)' : ($s->jatuh_tempo->lt(now()->addHours(3)) ? 'var(--status-hampir-habis)' : 'var(--status-main)'));
                        @endphp
                        <tr wire:key="d-{{ $s->id }}" class="align-middle">
                            <td class="px-3 py-2 whitespace-nowrap" style="box-shadow: inset 3px 0 0 {{ $warna }}">
                                <div class="font-semibold">{{ $s->playbox?->kode }}</div>
                                <div class="text-xs text-muted num">{{ $s->nomor }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <div class="font-medium truncate max-w-44">{{ $s->penyewa?->nama }}@if ($s->penyewa?->daftar_hitam) <span class="text-danger">⚠</span>@endif</div>
                                <div class="text-xs text-muted num">{{ $s->penyewa?->telepon }}</div>
                            </td>
                            <td class="px-3 py-2 hidden lg:table-cell text-muted">
                                <div class="truncate max-w-52">{{ \App\Models\Penyewa::JENIS_TEMPAT[$s->penyewa?->jenis_tempat] ?? '' }} · {{ $s->alamat }}</div>
                            </td>
                            <td class="px-3 py-2 hidden md:table-cell whitespace-nowrap">{{ $s->labelDurasi() }}{{ $s->perpanjangan ? ' +'.count($s->perpanjangan).'×' : '' }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <div @class(['num', 'text-danger font-semibold' => $telat])>{{ $s->jatuh_tempo->format('d/m H:i') }}</div>
                                <div class="text-xs" style="color: {{ $warna }}">
                                    {{ $s->status === 'berjalan' ? ($telat ? 'TELAT ' : '').$s->jatuh_tempo->diffForHumans(short: true) : strtoupper($s->status) }}
                                    @if ($belumBayar)<span class="text-danger">· belum bayar</span>@endif
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right hidden md:table-cell whitespace-nowrap">@if ($s->deposit)<x-rupiah :nilai="$s->deposit" />@else<span class="text-muted">–</span>@endif</td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-1.5 whitespace-nowrap">
                                    @include('livewire.operator.partials.aksi-sewa-playbox', ['ringkas' => true])
                                </div>
                            </td>
                        </tr>
                        @if ($perpanjangId === $s->id)
                            <tr wire:key="dp-{{ $s->id }}"><td colspan="7" class="px-3 py-3 bg-surface-2">
                                @include('livewire.operator.partials.perpanjang-playbox')
                            </td></tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        {{-- ======================= KOTAK ======================= --}}
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
                        @include('livewire.operator.partials.aksi-sewa-playbox')
                    </div>

                    @if ($perpanjangId === $s->id)
                        <div class="px-3 py-3 border-t border-line">
                            @include('livewire.operator.partials.perpanjang-playbox')
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <livewire:operator.pembayaran />
</div>
