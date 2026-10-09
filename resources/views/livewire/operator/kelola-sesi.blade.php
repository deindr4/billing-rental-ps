<div>
    <x-sheet wire:model="buka" :judul="$this->sesi ? __('Kelola Sesi · :unit', ['unit' => $this->sesi->unit->nama]) : __('Kelola Sesi')">
        @if ($this->sesi)
            @php
                $sesi = $this->sesi;
                $trx = $sesi->transaksi;
                $lama = fn (int $m) => $m >= 60 && $m % 60 === 0 ? __(':n jam', ['n' => $m / 60]) : __(':n menit', ['n' => $m]);
            @endphp

            {{-- Timer --}}
            <div wire:key="timer-{{ $sesi->id }}-{{ $sesi->versi_tagihan }}"
                 x-data="timerSesi({
                     mode: @js($sesi->mode),
                     mulai: {{ $sesi->mulai_pada->getTimestampMs() }},
                     berakhir: {{ $sesi->berakhir_pada?->getTimestampMs() ?? 'null' }},
                     dijeda: {{ $sesi->dijeda_pada?->getTimestampMs() ?? 'null' }},
                     jedaDetik: {{ (int) $sesi->total_jeda_detik + (int) $sesi->bonus_detik }},
                     peringatanMenit: 5,
                     serverNow: {{ now()->getTimestampMs() }},
                 })"
                 class="rounded-md border border-line bg-bg px-3 py-2 mb-4">
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-xs text-muted">{{ $sesi->isPaket() ? __('Sisa waktu') : __('Durasi berjalan') }}</span>
                    @if ($sesi->isPaket())
                        <span class="text-xs text-muted">{{ __('Selesai :jam', ['jam' => $sesi->berakhir_pada->format('H:i')]) }}</span>
                    @endif
                </div>
                <div class="num text-3xl font-semibold leading-tight"
                     :class="{ 'text-st-hampir': hampir, 'text-danger': habis }"
                     x-text="teks">--:--:--</div>
                @if ($sesi->status === 'dijeda')
                    <div class="text-xs text-muted">{{ __('Dijeda sejak :jam', ['jam' => $sesi->dijeda_pada->format('H:i')]) }}</div>
                @endif
            </div>

            {{-- Info sesi --}}
            <dl class="text-sm space-y-1 mb-4">
                <div class="flex justify-between gap-2">
                    <dt class="text-muted">{{ __('Pelanggan') }}</dt>
                    <dd class="truncate">{{ $trx->pelanggan_nama ?: __('Tamu') }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-muted">{{ __('No. Transaksi') }}</dt>
                    <dd class="num">{{ $trx->nomor }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-muted">{{ __('Mulai') }}</dt>
                    <dd class="num">{{ $sesi->mulai_pada->format('d/m H:i') }}</dd>
                </div>
            </dl>

            {{-- ================= PANEL UTAMA ================= --}}
            @if ($panel === 'utama')
                <div class="rounded-md border border-line mb-4">
                    <div class="px-3 py-2 border-b border-line text-sm font-medium">{{ __('Rincian tagihan') }}</div>

                    <ul class="divide-y divide-line text-sm">
                        @if (! $sesi->isPaket())
                            <li class="px-3 py-2 flex justify-between gap-3 text-muted">
                                <span>
                                    {{ __('Sewa (open billing)') }}
                                    @if ($this->tarifPerJam)
                                        <span class="block text-xs"><x-rupiah :nilai="$this->tarifPerJam" /> / {{ __('jam') }} · {{ __('final saat selesai') }}</span>
                                    @endif
                                </span>
                                <span class="text-right">
                                    <span class="block text-xs">{{ __('perkiraan s.d. :jam', ['jam' => now()->format('H:i')]) }}</span>
                                    <x-rupiah :nilai="$this->estimasiSewa ?? 0" />
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
                        <span>{{ $sesi->isPaket() ? __('Total') : __('Total sementara') }}</span>
                        <x-rupiah :nilai="$trx->total" />
                    </div>
                </div>

                {{-- Tambah waktu yang bisa dibatalkan (salah pencet) --}}
                @if ($this->riwayatTambah->isNotEmpty())
                    <div class="rounded-md border border-line mb-4">
                        <div class="px-3 py-2 border-b border-line text-sm font-medium flex items-center justify-between gap-2">
                            <span>{{ __('Tambah waktu') }}</span>
                            <span class="text-xs text-muted font-normal">{{ __('Salah pencet? Batalkan di sini') }}</span>
                        </div>
                        <ul class="divide-y divide-line text-sm">
                            @foreach ($this->riwayatTambah as $r)
                                <li wire:key="tw-{{ $r['id'] }}" class="px-3 py-2 flex items-center justify-between gap-3">
                                    <span class="min-w-0">
                                        <span class="block">
                                            +{{ $lama($r['menit']) }}
                                            · {{ $r['gratis'] ? __('gratis') : '' }}@if (! $r['gratis'])<x-rupiah :nilai="$r['harga']" />@endif
                                        </span>
                                        <span class="block text-xs text-muted">
                                            {{ $r['waktu']->format('H:i') }}{{ $r['oleh'] ? ' · '.$r['oleh'] : '' }}{{ $r['sebelum'] ? ' · '.__('sebelumnya selesai :jam', ['jam' => $r['sebelum']]) : '' }}
                                        </span>
                                    </span>
                                    <x-confirm-button action="batalTambahWaktu" :params="[$r['id']]"
                                                      :title="__('Batalkan tambah :n menit?', ['n' => $r['menit']])"
                                                      :text="__('Jam selesai dimundurkan :n menit & biayanya dihapus dari tagihan. TV langsung menyesuaikan.', ['n' => $r['menit']]).($r['butuhPin'] ? ' '.__('Butuh PIN supervisor/owner.') : '')"
                                                      :confirm-text="__('Ya, batalkan')"
                                                      reason
                                                      :pin="$r['butuhPin']"
                                                      class="btn-tint tint-merah h-8 px-3 text-xs shrink-0">
                                        {{ __('Batalkan') }}
                                    </x-confirm-button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Aksesori yang disewa: kembalikan lebih awal (per jam berhenti dihitung) / batal salah input --}}
                @if ($this->aksesoriSesi->isNotEmpty())
                    <div class="rounded-md border border-line mb-4">
                        <div class="px-3 py-2 border-b border-line text-sm font-medium">{{ __('Aksesori disewa') }}</div>
                        <ul class="divide-y divide-line text-sm">
                            @foreach ($this->aksesoriSesi as $s)
                                @php
                                    $bolehBatal = ! $s->selesai_pada && $s->mulai_pada->gt(now()->subMinutes(\App\Services\Billing\AksesoriService::BATAS_BATAL_MENIT));
                                    $perkiraan = app(\App\Services\Billing\AksesoriService::class)->perkiraan($s, $sesi);
                                @endphp
                                <li wire:key="sa-{{ $s->id }}" class="px-3 py-2 flex items-center justify-between gap-3">
                                    <span class="min-w-0">
                                        <span class="block">{{ $s->aksesori?->nama }}{{ $s->qty > 1 ? ' ×'.$s->qty : '' }}</span>
                                        <span class="block text-xs text-muted">
                                            {{ __('sejak :jam', ['jam' => $s->mulai_pada->format('H:i')]) }} ·
                                            @if ($s->selesai_pada)
                                                {{ __('dikembalikan :jam', ['jam' => $s->selesai_pada->format('H:i')]) }}
                                            @elseif ($s->perJam())
                                                {{ __('per jam, perkiraan') }} <x-rupiah :nilai="$perkiraan" />
                                            @else
                                                {{ __('flat per sesi') }}
                                            @endif
                                        </span>
                                    </span>
                                    @unless ($s->selesai_pada)
                                        <span class="flex gap-1.5 shrink-0">
                                            @if ($bolehBatal)
                                                <x-confirm-button action="batalAksesori" :params="[$s->id]"
                                                                  :title="__('Batalkan sewa :nama?', ['nama' => $s->aksesori?->nama])"
                                                                  :text="__('Salah input: dihapus dari tagihan tanpa biaya.')"
                                                                  :confirm-text="__('Ya, batalkan')"
                                                                  class="btn-tint tint-merah h-8 px-3 text-xs">{{ __('Batal') }}</x-confirm-button>
                                            @endif
                                            <x-confirm-button action="kembalikanAksesori" :params="[$s->id]"
                                                              :title="__(':nama dikembalikan?', ['nama' => $s->aksesori?->nama])"
                                                              :text="$s->perJam() ? __('Biaya per jam berhenti dihitung sekarang.') : __('Aksesori kembali tersedia untuk unit lain. Biaya flat tetap ditagih.')"
                                                              :confirm-text="__('Dikembalikan')"
                                                              class="btn-tint tint-teal h-8 px-3 text-xs">{{ __('Kembalikan') }}</x-confirm-button>
                                        </span>
                                    @endunless
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-2">
                    @if ($this->daftarAksesori->isNotEmpty())
                        <button type="button" wire:click="kePanel('aksesori')" class="btn btn-tint tint-ungu col-span-2">
                            <x-ikon name="aksesori" size="16" /> {{ __('Sewa Aksesori') }}
                        </button>
                    @endif

                    @if ($sesi->isPaket())
                        <button type="button" wire:click="kePanel('tambah')" class="btn btn-tint tint-kuning">
                            <x-ikon name="jam" size="16" /> {{ __('Tambah Waktu') }}
                        </button>
                    @endif

                    <a href="{{ route('pos', ['unit' => $sesi->unit_id]) }}" wire:navigate
                       @class(['btn btn-tint tint-oranye', 'col-span-2' => ! $sesi->isPaket()])>
                        <x-ikon name="fnb" size="16" /> {{ __('Tambah F&B') }}
                    </a>

                    @if ($sesi->status === 'berjalan')
                        <button type="button" wire:click="pause" wire:loading.attr="disabled" class="btn btn-tint tint-biru">{{ __('Pause') }}</button>
                    @else
                        <button type="button" wire:click="resume" wire:loading.attr="disabled" class="btn btn-primary">
                            <x-ikon name="play" size="16" /> {{ __('Lanjutkan') }}
                        </button>
                    @endif

                    <button type="button" wire:click="kePanel('pindah')" class="btn btn-tint tint-indigo">{{ __('Pindah Unit') }}</button>

                    {{-- Kompensasi PS restart / hang: menit diketik operator --}}
                    <button type="button" wire:click="kePanel('bonus')" class="btn btn-tint tint-pink">{{ __('Bonus Waktu') }}</button>

                    {{-- Struk / tagihan sementara (open bill: termasuk perkiraan sewa berjalan) --}}
                    <button type="button" class="btn btn-tint tint-teal"
                            wire:click="$dispatch('buka-pratinjau-struk', { transaksiId: '{{ $trx->id }}' })">{{ __('Cetak Struk') }}</button>

                    @php $belumKembali = $this->aksesoriBelumKembali(); @endphp
                    <x-confirm-button action="selesai"
                                      :title="__('Selesaikan sesi?')"
                                      :text="__('TV akan dikunci dan tagihan ditampilkan.').($belumKembali ? ' '.__('Jangan lupa ambil kembali aksesori: :daftar.', ['daftar' => $belumKembali]) : '')"
                                      :confirm-text="__('Ya, selesaikan')"
                                      danger
                                      class="w-full col-span-2">
                        {{ __('Selesai') }}
                    </x-confirm-button>

                    {{-- Tidak jadi main: tagihan batal Rp0, unit kosong lagi, TV terkunci --}}
                    <x-confirm-button action="batalSesi"
                                      :title="__('Batalkan sesi :unit?', ['unit' => $sesi->unit->nama])"
                                      :text="__('Tidak ditagih: tagihan :nomor dibatalkan, F&B dikembalikan ke stok, unit kosong lagi & TV langsung terkunci.', ['nomor' => $trx->nomor])
                                          .($this->batalSesiButuhPin ? ' '.__('Butuh PIN supervisor/owner.') : '')"
                                      :confirm-text="__('Ya, batalkan sesi')"
                                      danger
                                      reason
                                      :pin="$this->batalSesiButuhPin"
                                      class="btn-tint tint-merah w-full col-span-2 h-9 text-sm">
                        {{ __('Batalkan sesi (tidak jadi main)') }}
                    </x-confirm-button>
                    @if (! $this->batalSesiButuhPin && ! auth()->user()->can('transaksi.batal'))
                        <p class="col-span-2 text-xs text-muted text-center -mt-1">
                            {{ __('Tanpa PIN s.d. :jam (sesi Anda, belum ada pembayaran)', ['jam' => $sesi->created_at->copy()->addMinutes($this->menitTanpaPin)->format('H:i')]) }}
                        </p>
                    @endif
                </div>

            {{-- ================= PANEL TAMBAH WAKTU ================= --}}
            @elseif ($panel === 'tambah')
                <form wire:submit="tambahWaktu" class="space-y-4">
                    <div>
                        <div class="text-sm mb-1.5">{{ __('Tambah berapa menit?') }}</div>
                        <div class="grid grid-cols-4 gap-2 mb-2">
                            @foreach (\App\Livewire\Operator\KelolaSesi::PILIHAN_MENIT as $m)
                                <button type="button" wire:click="$set('tambahMenit', {{ $m }})"
                                        @class(['btn num', 'btn-primary' => (int) $tambahMenit === $m])>
                                    {{ $m >= 60 ? __(':n jam', ['n' => $m / 60]) : __(':n mnt', ['n' => $m]) }}
                                </button>
                            @endforeach
                        </div>
                        <input type="number" inputmode="numeric" min="1" max="720"
                               wire:model.live.debounce.300ms="tambahMenit" class="input num" placeholder="{{ __('Menit lain') }}">
                        @error('tambahMenit')
                            <p class="text-sm text-danger mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>

                    <p class="text-xs text-muted">{{ __('Waktu gratis (kompensasi PS restart/hang) pakai tombol Bonus Waktu.') }}</p>

                    <div class="rounded-md border border-line px-3 py-2 text-sm space-y-1">
                        <div class="flex justify-between">
                            <span class="text-muted">{{ __('Biaya tambahan') }}</span>
                            <x-rupiah :nilai="$this->hargaTambah" class="font-semibold" />
                        </div>
                        @if ($tambahMenit && $sesi->berakhir_pada)
                            <div class="flex justify-between">
                                <span class="text-muted">{{ __('Selesai baru') }}</span>
                                <span class="num">{{ $sesi->berakhir_pada->copy()->addMinutes((int) $tambahMenit)->format('H:i') }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="kePanel('utama')" class="btn">{{ __('Kembali') }}</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="tambahWaktu">{{ __('Simpan') }}</button>
                    </div>
                </form>

            {{-- ================= PANEL BONUS WAKTU ================= --}}
            @elseif ($panel === 'bonus')
                <form wire:submit="bonusWaktu" class="space-y-4">
                    <div class="rounded-md border border-line px-3 py-2 text-sm text-muted">
                        @if ($sesi->isPaket())
                            {{ __('Waktu selesai diundur sesuai bonus, tanpa biaya.') }}
                        @else
                            {{ __('Menit bonus tidak ditagih (dikurangkan dari durasi open billing).') }}
                        @endif
                        {{ __('Tercatat di tagihan & log aktivitas.') }}
                    </div>

                    <div>
                        <div class="text-sm mb-1.5">{{ __('Bonus berapa menit?') }}</div>
                        <div class="grid grid-cols-4 gap-2 mb-2">
                            @foreach (\App\Livewire\Operator\KelolaSesi::PILIHAN_BONUS as $m)
                                <button type="button" wire:click="$set('bonusMenit', {{ $m }})"
                                        @class(['btn num', 'btn-primary' => (int) $bonusMenit === $m])>{{ __(':n mnt', ['n' => $m]) }}</button>
                            @endforeach
                        </div>
                        <input type="number" inputmode="numeric" min="1" max="240"
                               wire:model.live.debounce.300ms="bonusMenit" class="input num" placeholder="{{ __('Ketik menit lain') }}">
                        @error('bonusMenit') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <div class="text-sm mb-1.5">{{ __('Alasan') }}</div>
                        <div class="flex flex-wrap gap-1.5 mb-2">
                            @foreach (\App\Livewire\Operator\KelolaSesi::ALASAN_BONUS as $a)
                                <button type="button" wire:click="$set('bonusAlasan', @js(__($a)))"
                                        @class(['btn h-8 px-3 text-xs', 'btn-primary' => $bonusAlasan === __($a)])>{{ __($a) }}</button>
                            @endforeach
                        </div>
                        <input type="text" wire:model="bonusAlasan" maxlength="200" class="input" placeholder="{{ __('Atau tulis alasan') }}">
                        @error('bonusAlasan') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    @if ($this->bonusButuhPin)
                        <div>
                            <label for="bonusPin" class="block text-sm mb-1.5">{{ __('PIN supervisor / owner') }}</label>
                            <input id="bonusPin" type="password" inputmode="numeric" autocomplete="off" maxlength="6"
                                   wire:model="bonusPin" class="input num tracking-[0.4em] text-center" placeholder="••••••">
                            @error('bonusPin') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="kePanel('utama')" class="btn">{{ __('Kembali') }}</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="bonusWaktu">
                            {{ (int) $bonusMenit ? __('Beri bonus :n menit', ['n' => (int) $bonusMenit]) : __('Beri bonus') }}
                        </button>
                    </div>
                </form>

            {{-- ================= PANEL SEWA AKSESORI ================= --}}
            @elseif ($panel === 'aksesori')
                <form wire:submit="sewaAksesori" class="space-y-4">
                    @include('livewire.operator.partials.pilih-aksesori', ['daftar' => $this->daftarAksesori, 'pilihan' => $aksesori])
                    @error('aksesori') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                    <p class="text-xs text-muted">{{ __('Masuk ke tagihan unit ini. Per jam dihitung sejak sekarang sampai dikembalikan / sesi selesai.') }}</p>

                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="kePanel('utama')" class="btn">{{ __('Kembali') }}</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="sewaAksesori"
                                @disabled($aksesori === [])>{{ __('Tambahkan') }}</button>
                    </div>
                </form>

            {{-- ================= PANEL PINDAH UNIT ================= --}}
            @elseif ($panel === 'pindah')
                <form wire:submit="pindah" class="space-y-4">
                    <div>
                        <div class="text-sm mb-1.5">{{ __('Pindah ke unit') }}</div>
                        @if ($this->unitKosong->isEmpty())
                            <p class="text-sm text-muted">{{ __('Tidak ada unit kosong saat ini.') }}</p>
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
                        <div class="text-sm mb-1.5">{{ __('Alasan') }}</div>
                        <div class="flex flex-wrap gap-2 mb-2">
                            @foreach (\App\Livewire\Operator\KelolaSesi::ALASAN_PINDAH as $a)
                                <button type="button" wire:click="$set('alasanPindah', @js(__($a)))"
                                        @class(['btn h-8 px-3 text-sm', 'btn-primary' => $alasanPindah === __($a)])>{{ __($a) }}</button>
                            @endforeach
                        </div>
                        <input type="text" wire:model="alasanPindah" class="input" placeholder="{{ __('Atau tulis alasan lain') }}" maxlength="200">
                        @error('alasanPindah')
                            <p class="text-sm text-danger mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="unitLamaServis">
                        {{ __('Tandai :unit sebagai Servis', ['unit' => $sesi->unit->nama]) }}
                    </label>

                    <p class="text-xs text-muted">{{ __('Sisa waktu, tagihan, dan nomor transaksi ikut pindah.') }}</p>

                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="kePanel('utama')" class="btn">{{ __('Kembali') }}</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="pindah"
                                @disabled($this->unitKosong->isEmpty())>{{ __('Pindahkan') }}</button>
                    </div>
                </form>
            @endif
        @endif
    </x-sheet>
</div>
