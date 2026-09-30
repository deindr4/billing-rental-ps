<div>
    <x-sheet wire:model="buka" :judul="$this->sesi ? 'Kelola Sesi · '.$this->sesi->unit->nama : 'Kelola Sesi'">
        @if ($this->sesi)
            @php
                $sesi = $this->sesi;
                $trx = $sesi->transaksi;
            @endphp

            {{-- Timer --}}
            <div wire:key="timer-{{ $sesi->id }}-{{ $sesi->versi_tagihan }}"
                 x-data="timerSesi({
                     mode: @js($sesi->mode),
                     mulai: {{ $sesi->mulai_pada->getTimestampMs() }},
                     berakhir: {{ $sesi->berakhir_pada?->getTimestampMs() ?? 'null' }},
                     dijeda: {{ $sesi->dijeda_pada?->getTimestampMs() ?? 'null' }},
                     jedaDetik: {{ (int) $sesi->total_jeda_detik }},
                     peringatanMenit: 5,
                     serverNow: {{ now()->getTimestampMs() }},
                 })"
                 class="rounded-md border border-line bg-bg px-3 py-2 mb-4">
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-xs text-muted">{{ $sesi->isPaket() ? 'Sisa waktu' : 'Durasi berjalan' }}</span>
                    @if ($sesi->isPaket())
                        <span class="text-xs text-muted">Selesai {{ $sesi->berakhir_pada->format('H:i') }}</span>
                    @endif
                </div>
                <div class="num text-3xl font-semibold leading-tight"
                     :class="{ 'text-st-hampir': hampir, 'text-danger': habis }"
                     x-text="teks">--:--:--</div>
                @if ($sesi->status === 'dijeda')
                    <div class="text-xs text-muted">Dijeda sejak {{ $sesi->dijeda_pada->format('H:i') }}</div>
                @endif
            </div>

            {{-- Info sesi --}}
            <dl class="text-sm space-y-1 mb-4">
                <div class="flex justify-between gap-2">
                    <dt class="text-muted">Pelanggan</dt>
                    <dd class="truncate">{{ $trx->pelanggan_nama ?: 'Tamu' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-muted">No. Transaksi</dt>
                    <dd class="num">{{ $trx->nomor }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-muted">Mulai</dt>
                    <dd class="num">{{ $sesi->mulai_pada->format('d/m H:i') }}</dd>
                </div>
            </dl>

            {{-- ================= PANEL UTAMA ================= --}}
            @if ($panel === 'utama')
                <div class="rounded-md border border-line mb-4">
                    <div class="px-3 py-2 border-b border-line text-sm font-medium">Rincian tagihan</div>

                    <ul class="divide-y divide-line text-sm">
                        @if (! $sesi->isPaket())
                            <li class="px-3 py-2 flex justify-between gap-3 text-muted">
                                <span>Sewa (open billing)</span>
                                <span>
                                    Dihitung saat selesai
                                    @if ($this->tarifPerJam)
                                        · <x-rupiah :nilai="$this->tarifPerJam" /> / jam
                                    @endif
                                </span>
                            </li>
                        @endif

                        @foreach ($trx->items as $item)
                            <li class="px-3 py-2 flex justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="block">{{ $item->nama }}{{ $item->qty > 1 ? ' x'.$item->qty : '' }}</span>
                                    @if ($item->catatan)
                                        <span class="block text-xs text-muted">{{ $item->catatan }}</span>
                                    @endif
                                </span>
                                <x-rupiah :nilai="$item->subtotal" class="shrink-0" />
                            </li>
                        @endforeach
                    </ul>

                    <div class="px-3 py-2 border-t border-line flex justify-between font-semibold">
                        <span>{{ $sesi->isPaket() ? 'Total' : 'Total sementara' }}</span>
                        <x-rupiah :nilai="$trx->total" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    @if ($sesi->isPaket())
                        <button type="button" wire:click="kePanel('tambah')" class="btn">Tambah Waktu</button>
                    @endif

                    <a href="{{ route('pos', ['unit' => $sesi->unit_id]) }}" wire:navigate
                       @class(['btn', 'col-span-2' => ! $sesi->isPaket()])>Tambah F&amp;B</a>

                    @if ($sesi->status === 'berjalan')
                        <button type="button" wire:click="pause" wire:loading.attr="disabled" class="btn">Pause</button>
                    @else
                        <button type="button" wire:click="resume" wire:loading.attr="disabled" class="btn btn-primary">Lanjutkan</button>
                    @endif

                    <button type="button" wire:click="kePanel('pindah')" class="btn">Pindah Unit</button>

                    <x-confirm-button action="selesai"
                                      title="Selesaikan sesi?"
                                      text="TV akan dikunci dan tagihan ditampilkan."
                                      confirm-text="Ya, selesaikan"
                                      danger
                                      class="w-full col-span-2">
                        Selesai
                    </x-confirm-button>
                </div>

            {{-- ================= PANEL TAMBAH WAKTU ================= --}}
            @elseif ($panel === 'tambah')
                <form wire:submit="tambahWaktu" class="space-y-4">
                    <div>
                        <div class="text-sm mb-1.5">Tambah berapa menit?</div>
                        <div class="grid grid-cols-4 gap-2 mb-2">
                            @foreach (\App\Livewire\Operator\KelolaSesi::PILIHAN_MENIT as $m)
                                <button type="button" wire:click="$set('tambahMenit', {{ $m }})"
                                        @class(['btn num', 'btn-primary' => (int) $tambahMenit === $m])>
                                    {{ $m >= 60 ? ($m / 60).' jam' : $m.' mnt' }}
                                </button>
                            @endforeach
                        </div>
                        <input type="number" inputmode="numeric" min="1" max="720"
                               wire:model.live.debounce.300ms="tambahMenit" class="input num" placeholder="Menit lain">
                        @error('tambahMenit')
                            <p class="text-sm text-danger mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model.live="gratis">
                        Gratis (kompensasi)
                    </label>

                    @if ($gratis)
                        <div>
                            <label for="alasanGratis" class="block text-sm mb-1.5">Alasan</label>
                            <textarea id="alasanGratis" wire:model="alasanGratis" rows="2" class="input h-auto py-2"
                                      placeholder="Contoh: stik error 10 menit"></textarea>
                            @error('alasanGratis')
                                <p class="text-sm text-danger mt-1.5">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="pinGratis" class="block text-sm mb-1.5">PIN supervisor / owner</label>
                            <input id="pinGratis" type="password" inputmode="numeric" autocomplete="off" maxlength="6"
                                   wire:model="pinGratis" class="input num tracking-[0.4em] text-center" placeholder="••••••">
                            @error('pinGratis')
                                <p class="text-sm text-danger mt-1.5">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    <div class="rounded-md border border-line px-3 py-2 text-sm space-y-1">
                        <div class="flex justify-between">
                            <span class="text-muted">Biaya tambahan</span>
                            <x-rupiah :nilai="$this->hargaTambah" class="font-semibold" />
                        </div>
                        @if ($tambahMenit && $sesi->berakhir_pada)
                            <div class="flex justify-between">
                                <span class="text-muted">Selesai baru</span>
                                <span class="num">{{ $sesi->berakhir_pada->copy()->addMinutes((int) $tambahMenit)->format('H:i') }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="kePanel('utama')" class="btn">Kembali</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="tambahWaktu">Simpan</button>
                    </div>
                </form>

            {{-- ================= PANEL PINDAH UNIT ================= --}}
            @elseif ($panel === 'pindah')
                <form wire:submit="pindah" class="space-y-4">
                    <div>
                        <div class="text-sm mb-1.5">Pindah ke unit</div>
                        @if ($this->unitKosong->isEmpty())
                            <p class="text-sm text-muted">Tidak ada unit kosong saat ini.</p>
                        @else
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ($this->unitKosong as $u)
                                    <label wire:key="tujuan-{{ $u->id }}"
                                           @class([
                                               'rounded-md border px-3 py-2 cursor-pointer text-sm',
                                               'border-accent' => $unitTujuanId === $u->id,
                                               'border-line' => $unitTujuanId !== $u->id,
                                           ])>
                                        <input type="radio" wire:model.live="unitTujuanId" value="{{ $u->id }}" class="sr-only">
                                        <span class="block font-medium">{{ $u->nama }}</span>
                                        <span class="block text-xs text-muted">{{ $u->tipeKonsol?->kode }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                        @error('unitTujuanId')
                            <p class="text-sm text-danger mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <div class="text-sm mb-1.5">Alasan</div>
                        <div class="flex flex-wrap gap-2 mb-2">
                            @foreach (\App\Livewire\Operator\KelolaSesi::ALASAN_PINDAH as $a)
                                <button type="button" wire:click="$set('alasanPindah', @js($a))"
                                        @class(['btn h-8 px-3 text-sm', 'btn-primary' => $alasanPindah === $a])>{{ $a }}</button>
                            @endforeach
                        </div>
                        <input type="text" wire:model="alasanPindah" class="input" placeholder="Atau tulis alasan lain" maxlength="200">
                        @error('alasanPindah')
                            <p class="text-sm text-danger mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="unitLamaServis">
                        Tandai {{ $sesi->unit->nama }} sebagai <span class="text-st-servis">Servis</span>
                    </label>

                    <p class="text-xs text-muted">Sisa waktu, tagihan, dan nomor transaksi ikut pindah.</p>

                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="kePanel('utama')" class="btn">Kembali</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="pindah"
                                @disabled($this->unitKosong->isEmpty())>Pindahkan</button>
                    </div>
                </form>
            @endif
        @endif
    </x-sheet>
</div>
