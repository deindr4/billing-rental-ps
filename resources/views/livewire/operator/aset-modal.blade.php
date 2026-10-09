@php
    $r = $this->ringkasan;
    $bolehLaba = $this->bisa('laporan.laba');
    $statusWarna = ['aktif' => 'var(--status-kosong)', 'servis' => 'var(--status-servis)', 'rusak' => 'var(--status-offline)', 'dilepas' => 'var(--text-muted)'];
@endphp
<div>
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <div class="label">{{ __('Kalkulasi penyusutan & periode balik modal (ROI)') }}</div>
            <h1 class="text-xl font-semibold tracking-tight">{{ __('Manajemen Aset & Modal') }}</h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('aset.laporan', 'pdf') }}" target="_blank" class="btn btn-tint tint-teal h-10 px-3 text-sm">{{ __('Laporan PDF') }}</a>
            <a href="{{ route('aset.laporan', 'csv') }}" class="btn btn-tint tint-hijau h-10 px-3 text-sm">{{ __('Excel (CSV)') }}</a>
            @if ($this->bisa('aset.kelola'))
                <button type="button" wire:click="tambah" class="btn btn-primary h-10 px-4">
                    <x-ikon name="plus" size="16" /> {{ __('Tambah aset') }}
                </button>
            @endif
        </div>
    </div>

    {{-- ================= Ringkasan ================= --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <div class="kartu p-4">
            <div class="label">{{ __('Total investasi') }}</div>
            <x-rupiah :nilai="$r['investasi']" class="text-xl font-semibold block mt-1" />
            <div class="text-xs text-muted mt-1">
                {{ __(':n aset', ['n' => $r['jumlah_aset']]) }}
                @foreach ($r['ringkas_kategori'] as $k => $n) · {{ $n }} {{ mb_strtolower(__(\App\Models\Aset::KATEGORI[$k] ?? $k)) }} @endforeach
            </div>
        </div>
        <div class="kartu p-4">
            <div class="label">{{ __('Nilai buku aset') }}</div>
            <x-rupiah :nilai="$r['nilai_buku']" class="text-xl font-semibold block mt-1" />
            <span class="chip mt-1.5" style="color: var(--status-hampir-habis)">{{ __('Penyusutan :persen%', ['persen' => $r['persen_susut']]) }}</span>
        </div>
        @if ($bolehLaba)
            <div class="kartu p-4">
                <div class="label">{{ __('Akumulasi laba') }}</div>
                <x-rupiah :nilai="$r['laba']" :class="'text-xl font-semibold block mt-1 '.($r['laba'] >= 0 ? 'text-accent' : 'text-danger')" />
                <div class="text-xs text-muted mt-1">{{ __('Laba bersih operasional') }} · ±<x-rupiah :nilai="$r['laba_per_bulan']" />/{{ __('bulan') }}</div>
            </div>
            <div class="kartu p-4">
                <div class="flex justify-between items-baseline">
                    <span class="label">{{ __('Payback / ROI') }}</span>
                    <span class="num text-sm text-accent font-semibold">{{ number_format($r['roi'], 1, ',', '.') }}%</span>
                </div>
                <div class="h-2 rounded-full bg-bg border border-line overflow-hidden mt-3">
                    <div class="h-full rounded-full bg-accent" style="width: {{ max(0, min(100, $r['roi'])) }}%"></div>
                </div>
                <div class="flex justify-between text-xs mt-2">
                    <span class="text-muted">{{ __('Est. balik modal') }}</span>
                    <span class="num">
                        @if ($r['balik_modal_bulan'] === 0) {{ __('Sudah balik modal') }}
                        @elseif ($r['balik_modal_bulan'] === null) -
                        @else {{ __('~:n bulan', ['n' => $r['balik_modal_bulan']]) }}
                        @endif
                    </span>
                </div>
            </div>
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-[1fr_360px]">
        {{-- ================= Daftar aset ================= --}}
        <section class="space-y-3">
            <div class="flex gap-1.5 overflow-x-auto pb-1">
                <button type="button" wire:click="$set('kategori', '')" @class(['chip shrink-0', 'text-fg border-accent' => $kategori === ''])>{{ __('Semua aset') }}</button>
                @foreach (\App\Models\Aset::KATEGORI as $kode => $nama)
                    <button type="button" wire:click="$set('kategori', '{{ $kode }}')" @class(['chip shrink-0', 'text-fg border-accent' => $kategori === $kode])>{{ __($nama) }}</button>
                @endforeach
                <button type="button" wire:click="$toggle('dilepas')" @class(['chip shrink-0', 'text-fg border-accent' => $dilepas])>{{ __('Dilepas') }}</button>
            </div>

            <div class="flex justify-between text-xs text-muted">
                <span class="label">{{ __('Daftar inventaris') }}</span>
                <span>{{ __('Metode garis lurus') }}</span>
            </div>

            @forelse ($this->daftar as $a)
                @php
                    $stat = $a->unit_id ? ($this->statistik[$a->unit_id] ?? null) : null;
                    $garansi = $a->sisaGaransiBulan();
                    $berikut = $a->servisBerikutnya();
                @endphp
                <div wire:key="aset-{{ $a->id }}" class="kartu p-4 space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex gap-3">
                            @if ($a->fotoUrl())
                                <img src="{{ $a->fotoUrl() }}" alt="" class="h-12 w-12 rounded-md object-cover shrink-0">
                            @endif
                            <div class="min-w-0">
                                <div class="font-semibold truncate">{{ $a->nama }}</div>
                                <div class="text-xs num truncate" style="color: var(--status-main)">
                                    {{ $a->unit ? __('UNIT :nama', ['nama' => $a->unit->nama]) : __(\App\Models\Aset::KATEGORI[$a->kategori]) }}{{ $a->serial ? ' · S/N: '.$a->serial : '' }}
                                </div>
                            </div>
                        </div>
                        <span class="chip shrink-0" style="color: {{ $statusWarna[$a->status] }}; border-color: {{ $statusWarna[$a->status] }}">
                            {{ __(\App\Models\Aset::STATUS[$a->status]) }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-2 rounded-md border border-line bg-bg p-3">
                        <div>
                            <div class="label">{{ __('Harga perolehan') }}</div>
                            <x-rupiah :nilai="$a->harga_perolehan" class="font-medium" />
                        </div>
                        <div>
                            <div class="label">{{ $a->status === 'dilepas' ? __('Nilai lepas') : __('Nilai buku saat ini') }}</div>
                            @if ($a->status === 'dilepas')
                                <x-rupiah :nilai="(int) $a->nilai_lepas" class="font-medium" />
                            @else
                                <x-rupiah :nilai="$a->nilaiBuku()" class="font-medium" style="color: var(--status-servis)" />
                            @endif
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs">
                        <span class="text-muted">{{ __('Beli: :tanggal · :n bln', ['tanggal' => $a->tanggal_beli->translatedFormat('d M Y'), 'n' => $a->umur_bulan]) }}</span>
                        @if ($garansi === null)
                            <span class="text-muted">{{ __('Tanpa data garansi') }}</span>
                        @elseif ($garansi === 0)
                            <span class="text-danger">{{ __('Garansi kedaluwarsa') }}</span>
                        @else
                            <span style="color: var(--status-main)">{{ __('Garansi s/d :bulan (:n bln)', ['bulan' => $a->garansi_sampai->translatedFormat('M Y'), 'n' => $garansi]) }}</span>
                        @endif
                    </div>

                    @if ($stat && $stat['detik'] + $stat['omzet'] > 0)
                        <div class="flex flex-wrap justify-between gap-2 rounded-md bg-bg px-3 py-2 text-xs">
                            <span class="text-muted">{{ __('Operasional:') }} <b class="text-fg num">{{ __(':n jam', ['n' => number_format(intdiv($stat['detik'], 3600), 0, ',', '.')]) }}</b></span>
                            <span class="text-accent">{{ __('Omzet:') }} <x-rupiah :nilai="$stat['omzet']" />
                                ({{ __('BEP :persen%', ['persen' => $a->harga_perolehan > 0 ? number_format($stat['omzet'] * 100 / $a->harga_perolehan, 0, ',', '.') : 0]) }})</span>
                        </div>
                    @endif

                    @if ($berikut && $a->status !== 'dilepas')
                        <div @class(['text-xs', 'text-st-hampir' => $a->jatuhTempoServis(), 'text-muted' => ! $a->jatuhTempoServis()])>
                            {{ __('Servis berikutnya: :tanggal', ['tanggal' => $berikut->translatedFormat('d M Y')]) }}
                            @if (isset($this->servisTerbuka[$a->id])) · {{ __('tiket servis terbuka') }} @endif
                        </div>
                    @endif

                    @if ($this->bisa('aset.kelola'))
                        <div class="flex justify-end gap-1">
                            @if ($a->status !== 'dilepas')
                                <button type="button" wire:click="bukaLepas('{{ $a->id }}')" class="btn btn-tint tint-merah h-7 px-2 text-xs">{{ __('Lepas') }}</button>
                            @endif
                            <button type="button" wire:click="ubah('{{ $a->id }}')" class="btn btn-tint tint-biru h-7 px-2 text-xs">{{ __('Ubah') }}</button>
                        </div>
                    @endif
                </div>
            @empty
                <div class="kartu p-8 text-center text-muted">
                    {{ __('Belum ada aset. Catat konsol, TV, stik, dan perabot supaya penyusutan & balik modal bisa dihitung.') }}
                </div>
            @endforelse
        </section>

        {{-- ================= Arus modal & prive ================= --}}
        <section class="space-y-3">
            <div class="kartu">
                <div class="flex items-center justify-between px-4 py-2.5 border-b border-line">
                    <span class="font-medium">{{ __('Arus modal & prive owner') }}</span>
                    <span class="label">{{ __('Bulan ini') }}</span>
                </div>
                <ul class="divide-y divide-line">
                    @forelse ($this->modalBulanIni as $m)
                        <li wire:key="modal-{{ $m->id }}" @class(['px-4 py-3 flex items-start justify-between gap-3', 'opacity-50' => $m->status !== 'aktif'])>
                            <div class="min-w-0">
                                <div class="font-medium">{{ __(\App\Models\ModalMutasi::JENIS[$m->jenis]) }}</div>
                                <div class="text-xs text-muted">{{ $m->keterangan }}</div>
                                <div class="text-xs text-muted">{{ $m->tanggal->format('d/m') }} · {{ __(\App\Models\ModalMutasi::SUMBER[$m->sumber]) }}{{ $m->status !== 'aktif' ? ' · '.__('dibatalkan') : '' }}</div>
                            </div>
                            <div class="text-right shrink-0">
                                <span @class(['font-semibold num', 'text-accent' => $m->jenis === 'modal', 'text-danger' => $m->jenis === 'prive'])>
                                    {{ $m->jenis === 'modal' ? '+' : '-' }}<x-rupiah :nilai="$m->jumlah" />
                                </span>
                                @if ($m->status === 'aktif' && $this->bisa('modal.kelola'))
                                    <x-confirm-button action="batalModal" :params="[$m->id]" :title="__('Batalkan catatan ini?')"
                                                      :text="$m->sumber === 'kas_laci' ? __('Uang laci kas ikut dikoreksi.') : ''" danger
                                                      class="h-6 px-2 text-xs mt-1 block ml-auto">{{ __('Batal') }}</x-confirm-button>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="p-6 text-center text-sm text-muted">{{ __('Belum ada catatan bulan ini.') }}</li>
                    @endforelse
                </ul>
                <div class="px-4 py-2.5 border-t border-line text-xs text-muted flex justify-between">
                    <span>{{ __('Total modal') }} <x-rupiah :nilai="$r['modal_masuk']" class="text-fg" /></span>
                    <span>{{ __('Total prive') }} <x-rupiah :nilai="$r['prive']" class="text-fg" /></span>
                </div>
            </div>

            @if ($this->bisa('modal.kelola'))
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" wire:click="bukaModal('modal')" class="btn btn-tint tint-hijau h-10">+ {{ __('Modal masuk') }}</button>
                    <button type="button" wire:click="bukaModal('prive')" class="btn btn-tint tint-oranye h-10">- {{ __('Prive owner') }}</button>
                </div>
            @endif
        </section>
    </div>

    {{-- ================= Form aset ================= --}}
    <x-sheet wire:model="formBuka" :judul="$editId ? __('Ubah aset') : __('Tambah aset / investasi baru')">
        <form id="form-aset" wire:submit="simpan" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm mb-1.5">{{ __('Kategori') }}</label>
                    <select wire:model.live="form.kategori" class="input">
                        @foreach (\App\Models\Aset::KATEGORI as $k => $n) <option value="{{ $k }}">{{ __($n) }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm mb-1.5">{{ __('Dipasang di unit') }} <span class="text-muted">({{ __('opsional') }})</span></label>
                    <select wire:model="form.unit_id" class="input">
                        <option value="">-</option>
                        @foreach ($this->units as $u) <option value="{{ $u->id }}">{{ $u->nama }}</option> @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm mb-1.5">{{ __('Nama aset') }}</label>
                <input type="text" wire:model="form.nama" class="input" maxlength="120" placeholder="PlayStation 5 Disc Edition">
                @error('form.nama') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm mb-1.5">{{ __('Merek') }}</label>
                    <input type="text" wire:model="form.merek" class="input" maxlength="60">
                </div>
                <div>
                    <label class="block text-sm mb-1.5">{{ __('No. seri') }}</label>
                    <input type="text" wire:model="form.serial" class="input num" maxlength="80">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm mb-1.5">{{ __('Tanggal beli') }}</label>
                    <input type="date" wire:model="form.tanggal_beli" class="input">
                    @error('form.tanggal_beli') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm mb-1.5">{{ __('Garansi sampai') }}</label>
                    <input type="date" wire:model="form.garansi_sampai" class="input">
                </div>
            </div>
            <div>
                <label class="block text-sm mb-1.5">{{ __('Harga perolehan') }}</label>
                <x-input-uang wire:model="form.harga_perolehan" />
                @error('form.harga_perolehan') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm mb-1.5">{{ __('Umur (bulan)') }}</label>
                    <input type="number" wire:model="form.umur_bulan" class="input num" min="1" max="240">
                    @error('form.umur_bulan') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="col-span-2">
                    <label class="block text-sm mb-1.5">{{ __('Nilai sisa di akhir umur') }}</label>
                    <x-input-uang wire:model="form.nilai_sisa" />
                </div>
            </div>
            <div>
                <label class="block text-sm mb-1.5">{{ __('Servis berkala tiap (hari)') }} <span class="text-muted">({{ __('opsional') }})</span></label>
                <input type="number" wire:model="form.interval_servis_hari" class="input num" min="1" max="730" placeholder="{{ __('contoh 90 (bersihkan debu PS)') }}">
                @error('form.interval_servis_hari') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm mb-1.5">{{ __('Foto') }} <span class="text-muted">({{ __('opsional') }})</span></label>
                <x-input-foto wire:model="foto" :nilai="$foto" :label="__('Pilih / ambil foto aset')" />
            </div>
            <div>
                <label class="block text-sm mb-1.5">{{ __('Catatan') }}</label>
                <textarea wire:model="form.catatan" rows="2" class="input" maxlength="500"></textarea>
            </div>
        </form>
        <x-slot:footer>
            <div class="flex gap-2">
                @if ($editId)
                    <x-confirm-button action="hapus" :params="[$editId]" :title="__('Hapus aset?')" :text="__('Hanya untuk salah input. Aset yang dijual/dibuang gunakan Lepas.')" danger class="h-11 px-4">{{ __('Hapus') }}</x-confirm-button>
                @endif
                <button type="submit" form="form-aset" class="btn btn-primary flex-1 h-11" wire:loading.attr="disabled" wire:target="simpan,foto">{{ __('Simpan') }}</button>
            </div>
        </x-slot:footer>
    </x-sheet>

    {{-- ================= Lepas aset ================= --}}
    <x-sheet wire:model="lepasBuka" :judul="__('Lepas aset (dijual / dibuang)')">
        <form id="form-lepas" wire:submit="simpanLepas" class="space-y-4">
            <div>
                <label class="block text-sm mb-1.5">{{ __('Tanggal') }}</label>
                <input type="date" wire:model="lepasTanggal" class="input">
                @error('lepasTanggal') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm mb-1.5">{{ __('Hasil penjualan') }} <span class="text-muted">({{ __('kosongkan jika dibuang') }})</span></label>
                <x-input-uang wire:model="lepasNilai" />
            </div>
            <div>
                <label class="block text-sm mb-1.5">{{ __('Catatan') }}</label>
                <input type="text" wire:model="lepasCatatan" class="input" maxlength="200">
            </div>
            <p class="text-xs text-muted">{{ __('Aset berhenti disusutkan dan tidak dihitung dalam total investasi.') }}</p>
        </form>
        <x-slot:footer>
            <button type="submit" form="form-lepas" class="btn btn-danger w-full h-11">{{ __('Lepas aset') }}</button>
        </x-slot:footer>
    </x-sheet>

    {{-- ================= Modal / prive ================= --}}
    <x-sheet wire:model="modalBuka" :judul="__(\App\Models\ModalMutasi::JENIS[$modalJenis])">
        <form id="form-modal" wire:submit="simpanModal" class="space-y-4">
            <div>
                <label class="block text-sm mb-1.5">{{ __('Nominal') }}</label>
                <x-input-uang wire:model="modalJumlah" class="text-lg" />
                @error('modalJumlah') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-1">
                @foreach (\App\Models\ModalMutasi::SUMBER as $k => $n)
                    <button type="button" wire:click="$set('modalSumber', '{{ $k }}')" @class(['btn h-9 text-sm', 'btn-primary' => $modalSumber === $k])>{{ __($n) }}</button>
                @endforeach
            </div>
            @if ($modalSumber === 'rekening')
                <div>
                    <label class="block text-sm mb-1.5">{{ __('Tanggal') }}</label>
                    <input type="date" wire:model="modalTanggal" class="input">
                </div>
            @else
                <p class="text-xs text-muted">{{ __('Tercatat di kas laci shift yang sedang buka (hari ini).') }}</p>
            @endif
            <div>
                <label class="block text-sm mb-1.5">{{ __('Keterangan') }}</label>
                <input type="text" wire:model="modalKeterangan" class="input" maxlength="255"
                       placeholder="{{ $modalJenis === 'modal' ? __('2 unit PS5 Slim baru') : __('Penarikan laba bulan ini') }}">
                @error('modalKeterangan') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>
        </form>
        <x-slot:footer>
            <button type="submit" form="form-modal" class="btn btn-primary w-full h-11">{{ __('Simpan') }}</button>
        </x-slot:footer>
    </x-sheet>
</div>
