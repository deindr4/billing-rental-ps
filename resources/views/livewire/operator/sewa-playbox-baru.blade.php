<div class="max-w-2xl mx-auto">
    <div class="mb-4 flex items-end justify-between gap-3">
        <div>
            <div class="label">Sewa bawa pulang</div>
            <h1 class="text-xl font-semibold tracking-tight">Sewa Playbox Baru</h1>
        </div>
        <a href="{{ route('playbox') }}" wire:navigate class="btn h-9 px-3 text-sm">Batal</a>
    </div>

    {{-- Langkah --}}
    <div class="grid grid-cols-4 gap-1.5 mb-4">
        @foreach ([1 => 'Penyewa', 2 => 'Unit & durasi', 3 => 'Jaminan & cek', 4 => 'Tanda tangan'] as $n => $nama)
            <div @class(['rounded-md border px-2 py-1.5 text-center text-xs', 'border-accent text-accent font-medium' => $langkah === $n, 'border-line text-muted' => $langkah !== $n])>
                <span class="num">{{ $n }}</span> · {{ $nama }}
            </div>
        @endforeach
    </div>

    <div class="surface p-4 space-y-4">
        {{-- ======================= 1. PENYEWA ======================= --}}
        @if ($langkah === 1)
            <div class="flex gap-2">
                <input type="tel" inputmode="numeric" maxlength="15" wire:model="cariHp" wire:keydown.enter="cari" class="input num flex-1"
                       x-data x-on:input="$el.value = $el.value.replace(/\D/g, '')" placeholder="Nomor HP penyewa (cari data lama)">
                <button type="button" wire:click="cari" class="btn btn-tint tint-biru">Cari</button>
            </div>

            @if ($p = $this->penyewaLama)
                @php $h = $p->riwayat(); @endphp
                <div @class(['rounded-md border px-3 py-2 text-sm', 'border-danger' => $p->daftar_hitam, 'border-line' => ! $p->daftar_hitam])>
                    <div class="font-medium">Penyewa lama · {{ $h['sewa'] }} sewa · {{ $h['telat'] }}× telat · kerusakan Rp{{ number_format($h['kerusakan'], 0, ',', '.') }}</div>
                    @if ($p->daftar_hitam)
                        <div class="text-danger mt-1">DAFTAR HITAM: {{ $p->alasan_daftar_hitam }}</div>
                        @can('shift.bantu')
                            <label class="flex items-center gap-2 mt-2"><input type="checkbox" wire:model="setujuDaftarHitam"> Saya (supervisor/owner) tetap menyewakan</label>
                        @endcan
                        @error('setujuDaftarHitam') <p class="text-danger mt-1">{{ $message }}</p> @enderror
                    @endif
                </div>
            @endif

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-sm mb-1.5">Nama lengkap</label>
                    <input type="text" wire:model="penyewa.nama" class="input" maxlength="100">
                    @error('penyewa.nama') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm mb-1.5">Nomor HP / WhatsApp</label>
                    <input type="tel" inputmode="numeric" wire:model="penyewa.telepon" class="input num" maxlength="15" placeholder="08xxxxxxxxxx"
                           x-data x-on:input="$el.value = $el.value.replace(/\D/g, '')">
                    @error('penyewa.telepon') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm mb-1.5">NIK (KTP) <span class="text-muted">{{ $this->penyewaLama ? '· kosongkan bila sama' : '' }}</span></label>
                    <input type="text" inputmode="numeric" wire:model="penyewa.nik" class="input num" maxlength="16" placeholder="16 digit"
                           x-data x-on:input="$el.value = $el.value.replace(/\D/g, '').slice(0, 16)">
                    @error('penyewa.nik') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm mb-1.5">Tinggal di</label>
                    <div class="grid grid-cols-2 gap-1.5">
                        @foreach (\App\Models\Penyewa::JENIS_TEMPAT as $k => $l)
                            <button type="button" wire:click="$set('penyewa.jenis_tempat', '{{ $k }}')"
                                    @class(['btn h-10', 'btn-primary' => $penyewa['jenis_tempat'] === $k])>{{ $l }}</button>
                        @endforeach
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-sm mb-1.5">Alamat lengkap {{ $penyewa['jenis_tempat'] === 'kost' ? '(nama kost, nomor kamar)' : '' }}</label>
                <textarea wire:model="penyewa.alamat" rows="2" class="input h-auto py-2"></textarea>
                @error('penyewa.alamat') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm mb-1.5">Lokasi (link share Google Maps / koordinat)</label>
                <div class="flex gap-2">
                    <input type="text" wire:model.live.debounce.500ms="penyewa.koordinat" class="input flex-1" placeholder="https://maps.app.goo.gl/… atau -8.6705, 115.2126">
                    {{-- GPS browser hanya diizinkan di HTTPS (domain / tunnel); di LAN http tempel link dari WA penyewa --}}
                    <button type="button" x-data x-show="window.isSecureContext && navigator.geolocation" x-cloak class="btn btn-tint tint-hijau"
                            @click="navigator.geolocation.getCurrentPosition(p => $wire.set('penyewa.koordinat', p.coords.latitude.toFixed(6) + ', ' + p.coords.longitude.toFixed(6)), () => alert('Lokasi tidak diizinkan'))">
                        Lokasi saya
                    </button>
                </div>
                @if ($url = $this->urlMaps())
                    <a href="{{ $url }}" target="_blank" rel="noopener" class="text-sm text-accent underline mt-1 inline-block">Cek di Google Maps ↗</a>
                @endif
                @error('penyewa.koordinat') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                <p class="text-xs text-muted mt-1">Minta penyewa kirim "Bagikan lokasi" dari Google Maps lewat WA, lalu tempel link-nya di sini.</p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach (['fotoPenyewa' => ['Foto penyewa (wajah)', 'user', $this->penyewaLama?->foto], 'fotoKtp' => ['Foto KTP', 'environment', $this->penyewaLama?->foto_ktp]] as $model => [$label, $kamera, $lama])
                    <div>
                        <label class="block text-sm mb-1.5">{{ $label }} <span class="text-muted">{{ $lama ? '· sudah ada, ambil ulang bila perlu' : '' }}</span></label>
                        <x-input-foto wire:model="{{ $model }}" :nilai="$this->{$model}" :kamera="$kamera" :lama="$lama"
                                      :label="$model === 'fotoKtp' ? 'Foto KTP' : 'Foto wajah penyewa'" />
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-muted">Foto & KTP dikompres otomatis dan disimpan privat (tidak bisa dibuka lewat link publik).</p>

        {{-- ======================= 2. UNIT & DURASI ======================= --}}
        @elseif ($langkah === 2)
            @if ($this->unitTersedia->isEmpty())
                <p class="text-sm text-danger">Tidak ada Playbox tersedia. Tambahkan di Admin → Sewa Playbox → Playbox.</p>
            @endif
            {{-- Daftar ringkas + cari (unit banyak tetap pendek) --}}
            <div x-data="{ q: '' }">
                @if ($this->unitTersedia->count() > 6)
                    <input type="search" x-model="q" class="input mb-2" placeholder="Cari kode / nama unit ({{ $this->unitTersedia->count() }} tersedia)">
                @endif
                <div class="rounded-md border border-line divide-y divide-line max-h-80 overflow-y-auto">
                    @foreach ($this->unitTersedia as $u)
                        <button type="button" wire:key="u-{{ $u->id }}" wire:click="pilihUnit('{{ $u->id }}')"
                                x-show="! q || @js(mb_strtolower($u->kode.' '.$u->nama)).includes(q.toLowerCase())"
                                @class(['w-full flex items-center gap-3 px-3 py-2 text-left', 'bg-surface-2' => $playboxId === $u->id])>
                            <span @class(['h-4 w-4 shrink-0 rounded-full border-2', 'border-accent bg-accent' => $playboxId === $u->id, 'border-line' => $playboxId !== $u->id])></span>
                            <span class="flex-1 min-w-0">
                                <span class="font-semibold">{{ $u->kode }}</span> <span class="text-muted">· {{ $u->nama }}</span>
                                <span class="block text-xs text-muted truncate">
                                    {{ collect($u->tarif())->map(fn ($h, $s) => 'Rp'.number_format($h, 0, ',', '.').'/'.$s)->implode(' · ') }}
                                </span>
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>
            @error('playboxId') <p class="text-sm text-danger">{{ $message }}</p> @enderror

            @if ($u = $this->unit)
                <div>
                    <div class="text-sm mb-1.5">Sewa per</div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($u->tarif() as $s => $h)
                            <button type="button" wire:click="$set('satuan', '{{ $s }}')" @class(['btn h-10 px-3', 'btn-primary' => $satuan === $s])>
                                {{ \App\Models\Playbox::SATUAN[$s] }} · <x-rupiah :nilai="$h" />
                            </button>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <label class="text-sm">Lama</label>
                    <input type="number" min="1" max="365" wire:model.live.debounce.300ms="jumlah" class="input num w-24">
                    <span class="text-sm text-muted">{{ \App\Models\Playbox::SATUAN[$satuan] ?? '' }}</span>
                </div>
                <div class="rounded-md border border-line px-3 py-2 text-sm flex justify-between">
                    <span>Jatuh tempo <b>{{ $this->jatuhTempo() }}</b></span>
                    <x-rupiah :nilai="$this->harga()" class="font-semibold text-accent" />
                </div>
            @endif

        {{-- ======================= 3. JAMINAN & CHECKLIST ======================= --}}
        @elseif ($langkah === 3)
            <div>
                <div class="text-sm font-medium mb-2">Jaminan</div>
                <div class="space-y-2">
                    <label class="flex items-start gap-3 rounded-md border border-line px-3 py-2">
                        <input type="checkbox" wire:model.live="jaminan.identitas" class="mt-1">
                        <span class="flex-1 space-y-2">
                            <span class="block text-sm">KTP / identitas asli ditahan</span>
                            @if ($jaminan['identitas'])
                                <span class="grid grid-cols-2 gap-2">
                                    <input type="text" wire:model="identitasKet" class="input" placeholder="KTP / SIM / kartu pelajar">
                                    <input type="text" wire:model="identitasNomor" class="input num" placeholder="Nomor dokumen">
                                </span>
                            @endif
                        </span>
                    </label>
                    <label class="flex items-start gap-3 rounded-md border border-line px-3 py-2">
                        <input type="checkbox" wire:model.live="jaminan.deposit" class="mt-1">
                        <span class="flex-1 space-y-2">
                            <span class="block text-sm">Uang deposit <span class="text-muted">(masuk laci sebagai titipan, kembali saat unit kembali)</span></span>
                            @if ($jaminan['deposit'])
                                <x-input-uang wire:model="deposit" />
                            @endif
                        </span>
                    </label>
                    <label class="flex items-start gap-3 rounded-md border border-line px-3 py-2">
                        <input type="checkbox" wire:model.live="jaminan.barang" class="mt-1">
                        <span class="flex-1 space-y-2">
                            <span class="block text-sm">Barang lain (STNK, HP, …)</span>
                            @if ($jaminan['barang'])
                                <input type="text" wire:model="barangKet" class="input" placeholder="Mis. STNK motor DK 1234 AB">
                                <x-input-foto wire:model="fotoBarang" :nilai="$fotoBarang" kamera="environment" label="Foto barang jaminan" />
                            @endif
                        </span>
                    </label>
                </div>
                @error('jaminan') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <div class="text-sm font-medium mb-2">Checklist kondisi & kelengkapan (saat keluar)</div>
                <div class="space-y-1.5">
                    @foreach ($checklist as $i => $c)
                        <div wire:key="ck-{{ $i }}" class="flex items-center gap-2">
                            <span class="flex-1 text-sm truncate">{{ $c['nama'] }}</span>
                            <input type="number" min="0" wire:model="checklist.{{ $i }}.jumlah" class="input num w-16 h-9">
                            <select wire:model="checklist.{{ $i }}.kondisi" class="input w-28 h-9">
                                @foreach (\App\Models\SewaPlaybox::KONDISI as $k => $l)
                                    @if ($k !== 'hilang')<option value="{{ $k }}">{{ $l }}</option>@endif
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
            </div>

            <div>
                <label class="block text-sm mb-1.5">Foto kondisi unit (boleh beberapa)</label>
                <x-input-foto wire:model="fotoKondisi" :nilai="$fotoKondisi" kamera="environment" multiple label="Foto kondisi unit" />
            </div>

        {{-- ======================= 4. SYARAT & TANDA TANGAN ======================= --}}
        @else
            <div class="rounded-md border border-line px-3 py-2 text-sm space-y-1">
                <div><b>{{ $penyewa['nama'] }}</b> · {{ $penyewa['telepon'] }}</div>
                <div>{{ $this->unit?->kode }} {{ $this->unit?->nama }} · {{ $jumlah }} {{ \App\Models\Playbox::SATUAN[$satuan] ?? '' }} · jatuh tempo {{ $this->jatuhTempo() }}</div>
                <div class="flex justify-between font-semibold"><span>Bayar di muka</span><x-rupiah :nilai="$this->harga()" /></div>
                @if ($jaminan['deposit'] && $deposit)<div class="flex justify-between text-muted"><span>+ Deposit (titipan)</span><x-rupiah :nilai="(int) $deposit" /></div>@endif
            </div>

            <div class="rounded-md bg-bg border border-line px-3 py-2 text-xs whitespace-pre-line max-h-40 overflow-y-auto">{{ $syarat }}</div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="setujuSyarat"> Penyewa membaca & menyetujui syarat sewa</label>

            <div x-data="{
                    ctx: null, coret: false,
                    init() {
                        const c = this.$refs.kanvas, r = c.getBoundingClientRect(), d = window.devicePixelRatio || 1;
                        c.width = r.width * d; c.height = r.height * d;
                        this.ctx = c.getContext('2d'); this.ctx.scale(d, d);
                        this.ctx.lineWidth = 2.4; this.ctx.lineCap = 'round'; this.ctx.lineJoin = 'round'; this.ctx.strokeStyle = '#111';
                    },
                    titik(e) { const r = this.$refs.kanvas.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; },
                    mulai(e) { this.coret = true; const [x, y] = this.titik(e); this.ctx.beginPath(); this.ctx.moveTo(x, y); },
                    gerak(e) { if (! this.coret) return; const [x, y] = this.titik(e); this.ctx.lineTo(x, y); this.ctx.stroke(); },
                    selesai() { if (! this.coret) return; this.coret = false; $wire.set('tandaTangan', this.$refs.kanvas.toDataURL('image/png'), false); },
                    hapus() { const c = this.$refs.kanvas; this.ctx.clearRect(0, 0, c.width, c.height); $wire.set('tandaTangan', '', false); },
                 }">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-sm">Tanda tangan penyewa</span>
                    <button type="button" @click="hapus()" class="btn h-8 px-3 text-xs">Hapus</button>
                </div>
                <canvas x-ref="kanvas" class="w-full h-40 rounded-md border border-line bg-white" style="touch-action: none"
                        @pointerdown="mulai($event)" @pointermove="gerak($event)" @pointerup="selesai()" @pointerleave="selesai()"></canvas>
                @error('tandaTangan') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm mb-1.5">Catatan <span class="text-muted">(opsional)</span></label>
                <input type="text" wire:model="catatan" class="input" maxlength="300" placeholder="Mis. diantar ke kost, game disc 3 keping">
            </div>
        @endif

        <div class="flex gap-2 pt-2">
            @if ($langkah > 1)
                <button type="button" wire:click="kembali" class="btn flex-1 h-11">Kembali</button>
            @endif
            @if ($langkah < 4)
                <button type="button" wire:click="lanjut" class="btn btn-primary flex-1 h-11" wire:loading.attr="disabled">Lanjut</button>
            @else
                <button type="button" wire:click="simpan" class="btn btn-primary flex-1 h-11" wire:loading.attr="disabled" wire:target="simpan">
                    <span wire:loading.remove wire:target="simpan">Simpan & bayar</span>
                    <span wire:loading wire:target="simpan">Menyimpan…</span>
                </button>
            @endif
        </div>
    </div>
</div>
