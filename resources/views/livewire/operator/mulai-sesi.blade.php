<div>
    <x-sheet wire:model="buka" :judul="$this->unit ? 'Mulai Rental · '.$this->unit->nama : 'Mulai Rental'">
        @if ($this->unit)
            <form id="form-mulai-sesi" wire:submit="simpan" class="space-y-5">

                {{-- Data pelanggan: tamu / member --}}
                @if ($this->programMemberAktif)
                    <div>
                        <div class="label mb-2">Data pelanggan</div>
                        @include('livewire.operator.partials.pilih-member')
                        @error('memberId') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
                    </div>
                @endif

                {{-- Mode --}}
                <div class="grid grid-cols-3 gap-1.5 rounded-md border border-line bg-bg p-1">
                    @foreach (\App\Livewire\Operator\MulaiSesi::MODE as $kode => $nama)
                        <button type="button" wire:click="$set('mode', '{{ $kode }}')"
                                @class([
                                    'h-9 rounded text-sm font-medium',
                                    'bg-surface-2 text-fg border border-line' => $mode === $kode,
                                    'text-muted' => $mode !== $kode,
                                ])>{{ $nama }}</button>
                    @endforeach
                </div>

                {{-- ============ DURASI ============ --}}
                @if ($mode === 'durasi')
                    @if (! $this->tarifPerJam)
                        <p class="text-sm text-danger">Tarif per jam unit ini belum diatur. Atur di Admin → Paket Harga (jenis Tarif per jam).</p>
                    @else
                        <div>
                            <div class="label mb-2">Pilih durasi</div>
                            <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                                @foreach (\App\Livewire\Operator\MulaiSesi::PILIHAN_DURASI as $m)
                                    <button type="button" wire:click="$set('durasiMenit', {{ $m }})"
                                            @class([
                                                'rounded-md border px-2 py-2.5 text-center',
                                                'border-accent' => (int) $durasiMenit === $m,
                                                'border-line hover:border-muted' => (int) $durasiMenit !== $m,
                                            ])>
                                        <span class="block font-semibold">{{ \App\Models\Sesi::formatDurasi($m * 60) }}</span>
                                        <x-rupiah :nilai="$this->hargaDurasi($m)" class="block text-xs text-muted mt-0.5" />
                                    </button>
                                @endforeach
                            </div>

                            <div class="flex items-center gap-2 mt-3">
                                <input type="number" inputmode="numeric" min="15" max="720" step="15"
                                       wire:model.live.debounce.300ms="durasiMenit" class="input num w-28">
                                <span class="text-sm text-muted">menit (durasi lain)</span>
                            </div>
                            @error('durasiMenit') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror

                            <p class="text-xs text-muted mt-2">
                                Tarif <x-rupiah :nilai="$this->tarifPerJam" class="text-fg" /> / jam. Timer hitung mundur, bisa ditambah waktu.
                            </p>
                        </div>
                    @endif

                {{-- ============ PAKET ============ --}}
                @elseif ($mode === 'paket')
                    @if ($this->paketTersedia->isEmpty())
                        <p class="text-sm text-muted">Belum ada paket untuk unit ini. Buat di Admin → Paket Harga (jenis Paket), atau pakai mode Durasi.</p>
                    @else
                        <div class="space-y-2">
                            @foreach ($this->paketTersedia as $paket)
                                <label wire:key="paket-{{ $paket->id }}"
                                       @class([
                                           'flex items-center justify-between gap-3 rounded-md border px-3 py-2.5 cursor-pointer',
                                           'border-accent' => $paketId === $paket->id,
                                           'border-line' => $paketId !== $paket->id,
                                       ])>
                                    <span class="flex items-center gap-3 min-w-0">
                                        <input type="radio" wire:model.live="paketId" value="{{ $paket->id }}" class="shrink-0">
                                        <span class="min-w-0">
                                            <span class="block font-medium truncate">{{ $paket->nama }}</span>
                                            <span class="block text-xs text-muted">
                                                {{ \App\Models\Sesi::formatDurasi($paket->durasi_menit * 60) }}{{ $paket->keterangan ? ' · '.$paket->keterangan : '' }}
                                            </span>
                                        </span>
                                    </span>
                                    <x-rupiah :nilai="$paket->harga" class="font-semibold shrink-0" />
                                </label>
                            @endforeach
                        </div>
                    @endif
                    @error('paketId') <p class="text-sm text-danger">{{ $message }}</p> @enderror

                {{-- ============ OPEN BILLING ============ --}}
                @else
                    <div class="rounded-md border border-line px-3 py-2.5 text-sm space-y-1">
                        <div class="flex justify-between gap-2">
                            <span class="text-muted">Tarif</span>
                            @if ($this->tarifPerJam)
                                <span><x-rupiah :nilai="$this->tarifPerJam" /> / jam</span>
                            @else
                                <span class="text-danger">Belum diatur</span>
                            @endif
                        </div>
                        <p class="text-xs text-muted">
                            Timer hitung maju. Dihitung per {{ $this->aturanOpen['blok'] }} menit, minimal {{ $this->aturanOpen['minimal'] }} menit.
                            Main {{ $this->aturanOpen['toleransi'] }} menit pertama lalu berhenti tidak ditagih.
                        </p>
                    </div>
                @endif

                {{-- TV berisi beberapa konsol: pilih HDMI yang dibuka (tarif tetap per unit) --}}
                @if ($tv = $this->perangkatTv)
                    <div>
                        <div class="block text-sm mb-1.5">Konsol / HDMI di TV</div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($tv->pilihanHdmi() as $id => $label)
                                <button type="button" wire:click="$set('hdmi', @js($id))"
                                        @class(['btn h-9 px-3 text-sm', 'btn-primary' => $hdmi === $id])>{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Sewa aksesori (stik tambahan, headset, …) --}}
                @if ($this->daftarAksesori->isNotEmpty())
                    @include('livewire.operator.partials.pilih-aksesori', ['daftar' => $this->daftarAksesori, 'pilihan' => $aksesori])
                @endif

                {{-- Waktu pilih game: TV terbuka, waktu sewa belum berjalan --}}
                @if ($this->pilihGameDefault > 0)
                    <label class="flex items-start gap-3 rounded-md border border-line px-3 py-2.5 cursor-pointer">
                        <input type="checkbox" wire:model.live="pilihGame" class="mt-0.5 shrink-0">
                        <span class="text-sm">
                            <span class="block font-medium">Beri waktu pilih game {{ $this->pilihGameDefault }} menit</span>
                            <span class="block text-xs text-muted">TV langsung terbuka; waktu sewa mulai dihitung setelah {{ $this->pilihGameDefault }} menit dan tidak ditagih.</span>
                        </span>
                    </label>
                @endif

                {{-- Nama tamu --}}
                @if ($jenisPelanggan === 'tamu')
                    <div>
                        <label for="pelanggan" class="block text-sm mb-1.5">Nama pelanggan <span class="text-muted">(opsional)</span></label>
                        <input id="pelanggan" type="text" wire:model="pelanggan" class="input" placeholder="Tamu" maxlength="100">
                        @error('pelanggan') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
                    </div>
                @endif
            </form>

            <x-slot:footer>
                @php
                    $tagihan = $this->tagihanAwal();
                    $diskonMember = $this->diskonMember();
                    $aks = $this->tagihanAksesori();
                @endphp
                @if (count($aksesori) > 0)
                    @php $adaPerJam = $this->daftarAksesori->whereIn('id', array_keys($aksesori))->contains('satuan', 'jam'); @endphp
                    <div class="flex items-center justify-between gap-3 mb-1 text-sm">
                        <span class="text-muted">Sewa aksesori</span>
                        <span>
                            @if ($aks > 0)+<x-rupiah :nilai="$aks" />@endif
                            @if ($adaPerJam)<span class="text-xs text-muted">{{ $aks > 0 ? '+ ' : '' }}per jam dihitung saat selesai</span>@endif
                        </span>
                    </div>
                @endif
                @if ($diskonMember > 0)
                    <div class="flex items-center justify-between gap-3 mb-1 text-sm">
                        <span class="text-muted">Sewa</span>
                        <x-rupiah :nilai="$tagihan" />
                    </div>
                    <div class="flex items-center justify-between gap-3 mb-1 text-sm text-accent">
                        <span>Diskon member {{ $this->member->tier }} (-{{ $this->infoMember()['diskon'] }}%)</span>
                        <span>-<x-rupiah :nilai="$diskonMember" /></span>
                    </div>
                @endif
                <div class="flex items-center justify-between gap-3 mb-3 text-sm">
                    <span class="label">{{ $diskonMember > 0 ? 'Total estimasi' : 'Tagihan awal' }}</span>
                    @if ($tagihan)
                        <x-rupiah :nilai="$tagihan - $diskonMember + $aks" class="text-xl font-semibold text-accent" />
                    @elseif ($this->member && $this->infoMember()['diskon'] > 0 && $jenisPelanggan === 'member')
                        <span class="text-muted">Dihitung saat selesai · diskon {{ $this->infoMember()['diskon'] }}%</span>
                    @else
                        <span class="text-muted">Dihitung saat selesai</span>
                    @endif
                </div>
                <button type="submit" form="form-mulai-sesi" class="btn btn-primary w-full h-11"
                        wire:loading.attr="disabled" wire:target="simpan">
                    <span wire:loading.remove wire:target="simpan">Mulai Rental</span>
                    <span wire:loading wire:target="simpan">Memproses...</span>
                </button>
            </x-slot:footer>
        @endif
    </x-sheet>
</div>
