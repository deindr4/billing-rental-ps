@php
    $warna = ['dijadwalkan' => 'var(--status-main)', 'dikerjakan' => 'var(--status-servis)', 'selesai' => 'var(--status-kosong)', 'batal' => 'var(--text-muted)'];
@endphp
<div>
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <div class="label">{{ __('Servis unit, perbaikan & jadwal berkala') }}</div>
            <h1 class="text-xl font-semibold tracking-tight">{{ __('Maintenance') }}</h1>
        </div>
        <button type="button" wire:click="tambah" class="btn btn-primary h-10 px-4">
            <x-ikon name="plus" size="16" /> {{ __('Tiket baru') }}
        </button>
    </div>

    <div class="grid grid-cols-3 gap-3 mb-4">
        <div class="kartu p-3">
            <div class="label">{{ __('Dikerjakan') }}</div>
            <div class="text-xl font-semibold num" style="color: var(--status-servis)">{{ $this->ringkasan['dikerjakan'] }}</div>
        </div>
        <div class="kartu p-3">
            <div class="label">{{ __('Dijadwalkan') }}</div>
            <div class="text-xl font-semibold num" style="color: var(--status-main)">{{ $this->ringkasan['dijadwalkan'] }}</div>
        </div>
        <div class="kartu p-3">
            <div class="label">{{ __('Biaya bulan ini') }}</div>
            <x-rupiah :nilai="$this->ringkasan['biaya_bulan']" class="text-xl font-semibold" />
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-[1fr_340px]">
        <section class="space-y-3">
            <div class="grid grid-cols-3 gap-1.5 rounded-md border border-line bg-bg p-1 max-w-sm">
                @foreach (['terbuka' => __('Terbuka'), 'selesai' => __('Riwayat'), 'semua' => __('Semua')] as $k => $n)
                    <button type="button" wire:click="$set('tab', '{{ $k }}')"
                            @class(['h-8 rounded text-sm font-medium', 'bg-surface-2 text-fg border border-line' => $tab === $k, 'text-muted' => $tab !== $k])>{{ $n }}</button>
                @endforeach
            </div>

            @forelse ($this->daftar as $m)
                <div wire:key="mt-{{ $m->id }}" class="kartu kartu-status p-4 space-y-2" style="--warna-status: {{ $warna[$m->status] }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-semibold">{{ $m->judul }}</div>
                            <div class="text-xs text-muted">
                                {{ __(\App\Models\Maintenance::JENIS[$m->jenis]) }}
                                @if ($m->unit) · {{ $m->unit->nama }} @endif
                                @if ($m->aset) · {{ $m->aset->nama }} @endif
                            </div>
                        </div>
                        <span class="chip shrink-0" style="color: {{ $warna[$m->status] }}; border-color: {{ $warna[$m->status] }}">{{ __(\App\Models\Maintenance::STATUS[$m->status]) }}</span>
                    </div>

                    @if ($m->deskripsi)
                        <p class="text-sm text-muted">{{ $m->deskripsi }}</p>
                    @endif

                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                        @if ($m->dijadwalkan_pada && $m->status === 'dijadwalkan')
                            <span @class(['text-st-hampir' => $m->dijadwalkan_pada->isPast()])>{{ __('Jadwal :tanggal', ['tanggal' => $m->dijadwalkan_pada->translatedFormat('d M Y')]) }}</span>
                        @endif
                        @if ($m->mulai_pada) <span>{{ __('Mulai :waktu', ['waktu' => $m->mulai_pada->format('d/m H:i')]) }}</span> @endif
                        @if ($m->selesai_pada) <span>{{ __('Selesai :waktu', ['waktu' => $m->selesai_pada->format('d/m H:i')]) }}{{ $m->durasiJam() !== null ? ' ('.__(':n jam', ['n' => $m->durasiJam()]).')' : '' }}</span> @endif
                        @if ($m->vendor) <span>{{ __('Vendor: :nama', ['nama' => $m->vendor]) }}</span> @endif
                        @if ($m->biaya > 0) <span>{{ __('Biaya') }} <x-rupiah :nilai="$m->biaya" class="text-fg" />{{ $m->pengeluaran_id ? ' · '.__('tercatat di pengeluaran') : '' }}</span> @endif
                        <span>{{ __('Oleh :nama', ['nama' => $m->user?->name ?? '-']) }}{{ $m->penyelesai ? ' · '.__('ditutup :nama', ['nama' => $m->penyelesai->name]) : '' }}</span>
                    </div>

                    @if ($m->hasil)
                        <p class="text-sm rounded-md bg-bg px-3 py-2">{{ $m->hasil }}</p>
                    @endif

                    @if ($m->isTerbuka())
                        <div class="flex justify-end gap-1 pt-1">
                            <x-confirm-button action="batal" :params="[$m->id]" :title="__('Batalkan tiket?')" :text="__('Unit kembali siap dipakai jika tidak ada servis lain.')" reason danger class="h-8 px-3 text-xs">{{ __('Batal') }}</x-confirm-button>
                            @if ($m->status === 'dijadwalkan')
                                <x-confirm-button action="mulai" :params="[$m->id]" :title="__('Mulai kerjakan?')" :text="$m->unit ? __(':unit ditandai servis dan tidak bisa disewa.', ['unit' => $m->unit->nama]) : ''" class="h-8 px-3 text-xs">{{ __('Mulai') }}</x-confirm-button>
                            @endif
                            <button type="button" wire:click="bukaSelesai('{{ $m->id }}')" class="btn btn-primary h-8 px-3 text-xs">{{ __('Selesai') }}</button>
                        </div>
                    @endif
                </div>
            @empty
                <div class="kartu p-8 text-center text-muted">
                    {{ $tab === 'terbuka' ? __('Tidak ada maintenance yang terbuka.') : __('Belum ada riwayat.') }}
                </div>
            @endforelse
        </section>

        {{-- Jadwal servis berkala --}}
        <section>
            <div class="kartu">
                <div class="px-4 py-2.5 border-b border-line font-medium">{{ __('Jadwal servis berkala') }}</div>
                <ul class="divide-y divide-line">
                    @forelse ($this->jatuhTempo as $a)
                        <li wire:key="jt-{{ $a->id }}" class="px-4 py-3 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-medium truncate">{{ $a->nama }}</div>
                                <div @class(['text-xs', 'text-danger' => $a->servisBerikutnya()->isPast(), 'text-st-hampir' => ! $a->servisBerikutnya()->isPast()])>
                                    {{ $a->unit?->nama ? $a->unit->nama.' · ' : '' }}{{ $a->servisBerikutnya()->isPast() ? __('Terlambat sejak :tanggal', ['tanggal' => $a->servisBerikutnya()->translatedFormat('d M')]) : __('Jatuh tempo :tanggal', ['tanggal' => $a->servisBerikutnya()->translatedFormat('d M')]) }}
                                </div>
                            </div>
                            <button type="button" wire:click="tambah(null, '{{ $a->id }}')" class="btn btn-tint tint-biru h-8 px-3 text-xs shrink-0">{{ __('Jadwalkan') }}</button>
                        </li>
                    @empty
                        <li class="p-5 text-sm text-muted text-center">{{ __('Tidak ada servis yang jatuh tempo. Atur interval servis di menu Aset.') }}</li>
                    @endforelse
                </ul>
            </div>
        </section>
    </div>

    {{-- ================= Tiket baru ================= --}}
    <x-sheet wire:model="formBuka" :judul="__('Tiket maintenance')">
        <form id="form-mt" wire:submit="simpan" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm mb-1.5">{{ __('Unit') }}</label>
                    <select wire:model.live="form.unit_id" class="input">
                        <option value="">- ({{ __('bukan unit') }})</option>
                        @foreach ($this->units as $u) <option value="{{ $u->id }}">{{ $u->nama }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm mb-1.5">{{ __('Aset') }} <span class="text-muted">({{ __('opsional') }})</span></label>
                    <select wire:model="form.aset_id" class="input">
                        <option value="">-</option>
                        @foreach ($this->asetPilihan as $a) <option value="{{ $a->id }}">{{ $a->nama }}</option> @endforeach
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-1">
                @foreach (\App\Models\Maintenance::JENIS as $k => $n)
                    <button type="button" wire:click="$set('form.jenis', '{{ $k }}')" @class(['btn h-9 text-xs', 'btn-primary' => ($form['jenis'] ?? '') === $k])>{{ __($n) }}</button>
                @endforeach
            </div>
            <div>
                <label class="block text-sm mb-1.5">{{ __('Masalah / pekerjaan') }}</label>
                <input type="text" wire:model="form.judul" class="input" maxlength="150" placeholder="{{ __('Stik 2 drift / PS mati sendiri / bersihkan debu') }}">
                @error('form.judul') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm mb-1.5">{{ __('Detail') }} <span class="text-muted">({{ __('opsional') }})</span></label>
                <textarea wire:model="form.deskripsi" rows="2" class="input" maxlength="1000"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm mb-1.5">{{ __('Jadwal') }}</label>
                    <input type="date" wire:model="form.dijadwalkan_pada" class="input">
                </div>
                <div>
                    <label class="block text-sm mb-1.5">{{ __('Vendor / teknisi') }}</label>
                    <input type="text" wire:model="form.vendor" class="input" maxlength="100">
                </div>
            </div>
            <label class="flex items-start gap-3 rounded-md border border-line px-3 py-2.5 cursor-pointer">
                <input type="checkbox" wire:model="form.mulai" class="mt-0.5">
                <span class="text-sm">
                    <span class="block font-medium">{{ __('Mulai kerjakan sekarang') }}</span>
                    <span class="block text-xs text-muted">{{ __('Unit langsung ditandai servis (tidak bisa disewa, layar TV menampilkan "servis").') }}</span>
                </span>
            </label>
        </form>
        <x-slot:footer>
            <button type="submit" form="form-mt" class="btn btn-primary w-full h-11" wire:loading.attr="disabled" wire:target="simpan">{{ __('Simpan tiket') }}</button>
        </x-slot:footer>
    </x-sheet>

    {{-- ================= Selesai ================= --}}
    <x-sheet wire:model="selesaiBuka" :judul="__('Selesaikan maintenance')">
        <form id="form-selesai" wire:submit="simpanSelesai" class="space-y-4">
            <div>
                <label class="block text-sm mb-1.5">{{ __('Hasil pekerjaan') }}</label>
                <textarea wire:model="hasil.hasil" rows="2" class="input" maxlength="1000" placeholder="{{ __('Ganti analog stik kiri') }}"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm mb-1.5">{{ __('Biaya') }}</label>
                    <x-input-uang wire:model.live="hasil.biaya" />
                </div>
                <div>
                    <label class="block text-sm mb-1.5">{{ __('Vendor / teknisi') }}</label>
                    <input type="text" wire:model="hasil.vendor" class="input" maxlength="100">
                </div>
            </div>
            @error('hasil.biaya') <p class="text-sm text-danger">{{ $message }}</p> @enderror

            @if ((int) ($hasil['biaya'] ?? 0) > 0)
                <label class="flex items-start gap-3 rounded-md border border-line px-3 py-2.5 cursor-pointer">
                    <input type="checkbox" wire:model.live="hasil.catat_pengeluaran" class="mt-0.5">
                    <span class="text-sm">
                        <span class="block font-medium">{{ __('Catat biaya sebagai pengeluaran') }}</span>
                        <span class="block text-xs text-muted">{{ __('Kategori Sparepart & Perbaikan, mengurangi laba.') }}</span>
                    </span>
                </label>
                @if (! empty($hasil['catat_pengeluaran']))
                    <div class="grid grid-cols-2 gap-1">
                        @foreach (\App\Models\Pengeluaran::SUMBER_DANA as $k => $n)
                            <button type="button" wire:click="$set('hasil.sumber_dana', '{{ $k }}')" @class(['btn h-9 text-sm', 'btn-primary' => $hasil['sumber_dana'] === $k])>{{ __($n) }}</button>
                        @endforeach
                    </div>
                @endif
            @endif

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="hasil.aset_rusak"> {{ __('Aset tidak bisa diperbaiki (tandai rusak)') }}
            </label>
        </form>
        <x-slot:footer>
            <button type="submit" form="form-selesai" class="btn btn-primary w-full h-11" wire:loading.attr="disabled" wire:target="simpanSelesai">{{ __('Selesai & unit siap dipakai') }}</button>
        </x-slot:footer>
    </x-sheet>
</div>
