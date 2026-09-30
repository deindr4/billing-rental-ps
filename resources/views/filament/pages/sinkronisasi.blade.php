<x-filament-panels::page>
    @php
        $baris = 'display:flex; justify-content:space-between; gap:12px; padding:6px 0; border-top:1px solid rgba(127,127,127,.18); font-size:14px;';
        $redup = 'opacity:.65;';
        $tgl = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y H:i:s') : '-';
    @endphp

    @if (! $this->diCloud())
        {{-- ======================== SERVER LOKAL ======================== --}}
        @php $s = $this->status; @endphp

        <x-filament::section>
            <x-slot name="heading">Status</x-slot>
            <x-slot name="description">Server ini: lokal (LAN rental). Data dikirim ke cloud & diambil dari cloud tiap menit selama scheduler berjalan.</x-slot>
            <div>
                <div style="{{ $baris }} border-top:none"><span style="{{ $redup }}">Status</span>
                    <b style="color: {{ $s['error'] ? '#ef4444' : ($aktif ? '#22c55e' : '#94a3b8') }}">{{ $s['error'] ? 'Gagal' : ($aktif ? 'Aktif' : 'Nonaktif') }}</b></div>
                @if ($s['error'])
                    <div style="{{ $baris }}"><span style="{{ $redup }}">Kesalahan terakhir</span><span style="color:#ef4444; text-align:right">{{ $s['error'] }}</span></div>
                @endif
                <div style="{{ $baris }}"><span style="{{ $redup }}">Perubahan menunggu dikirim</span><b>{{ number_format($s['antrean'], 0, ',', '.') }}</b></div>
                <div style="{{ $baris }}"><span style="{{ $redup }}">Sinkron berhasil terakhir</span><span>{{ $tgl($s['terakhir_ok']) }}</span></div>
                <div style="{{ $baris }}"><span style="{{ $redup }}">Kirim terakhir · ambil terakhir</span><span>{{ $tgl($s['terakhir_dorong']) }} · {{ $tgl($s['terakhir_tarik']) }}</span></div>
            </div>
            <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:12px;">
                <x-filament::button icon="heroicon-o-arrow-path" wire:click="jalankan" wire:loading.attr="disabled">Sync sekarang</x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-arrow-up-tray" wire:click="kirimUlang"
                                    wire:confirm="Semua data server ini dikirim ulang ke cloud (data cloud yang lebih baru tidak ditimpa). Lanjutkan?">Kirim ulang semua</x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" wire:click="tarikUlang"
                                    wire:confirm="Ambil ulang semua perubahan dari cloud (data lokal yang lebih baru tidak ditimpa). Lanjutkan?">Ambil ulang semua</x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Koneksi ke cloud</x-slot>
            <x-slot name="description">
                Token dibuat di server cloud (login super admin → Pengaturan → Sinkronisasi → Buat token), lalu tempel di sini.
                @if ($url)
                    <a href="{{ rtrim($url, '/') }}/admin/sinkronisasi" target="_blank" rel="noopener" style="text-decoration:underline">Buka halaman token di cloud ↗</a>
                @endif
            </x-slot>
            <form wire:submit="simpan" style="display:grid; gap:12px; max-width:560px;">
                <label>
                    <span style="display:block; font-size:14px; margin-bottom:4px;">Alamat server cloud</span>
                    <x-filament::input.wrapper><x-filament::input type="url" wire:model="url" placeholder="https://rental.domainku.id" /></x-filament::input.wrapper>
                    @error('url') <span style="color:#ef4444; font-size:12px">{{ $message }}</span> @enderror
                </label>
                <div>
                    <span style="display:block; font-size:14px; margin-bottom:4px;">Token sinkron {{ $s['punya_token'] ? '(tersimpan — kosongkan jika tidak diganti)' : '' }}</span>
                    <div style="display:flex; gap:8px; align-items:center;" x-data="{ disalin: false }">
                        <x-filament::input.wrapper style="flex:1">
                            <x-filament::input :type="$tokenTerlihat ? 'text' : 'password'" wire:model="token" placeholder="sk_..." autocomplete="off" x-ref="token" />
                        </x-filament::input.wrapper>
                        @if ($tokenTerlihat)
                            <x-filament::button color="gray" type="button" icon="heroicon-o-clipboard"
                                                x-on:click="navigator.clipboard.writeText($refs.token.value); disalin = true; setTimeout(() => disalin = false, 2000)">
                                <span x-text="disalin ? 'Disalin' : 'Salin'">Salin</span>
                            </x-filament::button>
                        @endif
                        @if ($s['punya_token'])
                            <x-filament::button color="gray" type="button" icon="heroicon-o-sparkles" wire:click="buatTokenAcak"
                                                wire:confirm="Buat token baru? Token lama tetap dipakai sampai Anda menekan Simpan.">Buat token acak</x-filament::button>
                        @else
                            <x-filament::button color="gray" type="button" icon="heroicon-o-sparkles" wire:click="buatTokenAcak">Buat token acak</x-filament::button>
                        @endif
                    </div>
                    @if ($tokenTerlihat)
                        <div style="font-size:12px; margin-top:6px; padding:8px; border:1px solid #f59e0b; border-radius:6px;">
                            Token baru belum berlaku. Langkah berikutnya:
                            <b>1.</b> Salin token ·
                            <b>2.</b> Di server cloud (super admin → Pengaturan → Sinkronisasi) isi nama server, tempel token di kolom <i>Token dari server lokal</i>, klik <b>Daftarkan</b> ·
                            <b>3.</b> Kembali ke sini, klik <b>Simpan</b> lalu <b>Tes koneksi</b>.
                        </div>
                    @endif
                </div>
                <label style="display:flex; align-items:center; gap:8px; font-size:14px;">
                    <x-filament::input.checkbox wire:model="aktif" /> Aktifkan sinkron otomatis
                </label>
                <div style="display:flex; gap:8px;">
                    <x-filament::button type="submit">Simpan</x-filament::button>
                    <x-filament::button color="gray" type="button" wire:click="tes" wire:loading.attr="disabled">Tes koneksi</x-filament::button>
                </div>
            </form>
        </x-filament::section>
    @else
        {{-- ======================== SERVER CLOUD ======================== --}}
        <x-filament::section>
            <x-slot name="heading">Server lokal rental</x-slot>
            <x-slot name="description">Dua cara: tempel token yang dibuat di server lokal (tombol "Buat token acak" di sana), atau kosongkan agar cloud membuatkan token lalu salin ke server lokal. Token hanya tampil sekali.</x-slot>

            @if ($tokenBaru)
                <div style="border:1px solid #22c55e; border-radius:8px; padding:12px; margin-bottom:12px;">
                    <div style="font-size:13px; {{ $redup }}">Token baru — salin sekarang, tidak akan ditampilkan lagi:</div>
                    <code style="font-size:14px; user-select:all; word-break:break-all;">{{ $tokenBaru }}</code>
                </div>
            @endif

            <form wire:submit="buatServer" style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin-bottom:16px;">
                <label style="flex:1; min-width:200px;">
                    <span style="display:block; font-size:14px; margin-bottom:4px;">Nama server</span>
                    <x-filament::input.wrapper><x-filament::input wire:model="namaServer" placeholder="Rental Cabang Utama" /></x-filament::input.wrapper>
                </label>
                <label style="min-width:200px;">
                    <span style="display:block; font-size:14px; margin-bottom:4px;">Tenant</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="tenantServer">
                            <option value="">Baru (dari server lokal)</option>
                            @foreach ($this->tenantPilihan as $id => $nama) <option value="{{ $id }}">{{ $nama }}</option> @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label style="flex:1; min-width:260px;">
                    <span style="display:block; font-size:14px; margin-bottom:4px;">Token dari server lokal <span style="{{ $redup }}">(opsional)</span></span>
                    <x-filament::input.wrapper><x-filament::input wire:model="tokenServer" placeholder="Kosongkan = cloud membuatkan token" autocomplete="off" /></x-filament::input.wrapper>
                </label>
                <x-filament::button type="submit" icon="heroicon-o-key">Daftarkan</x-filament::button>
            </form>
            @error('namaServer') <p style="color:#ef4444; font-size:12px; margin-top:-8px;">{{ $message }}</p> @enderror
            @error('tokenServer') <p style="color:#ef4444; font-size:12px; margin-top:-8px;">{{ $message }}</p> @enderror

            @forelse ($this->server as $sv)
                <div wire:key="sv-{{ $sv->id }}" style="{{ $baris }} align-items:center;">
                    <span>
                        <b>{{ $sv->nama }}</b> <span style="{{ $redup }}">· {{ $sv->tenant?->nama ?? 'belum terikat tenant' }}</span><br>
                        <span style="font-size:12px; {{ $redup }}">Kontak terakhir: {{ $sv->terakhir_kontak?->diffForHumans() ?? 'belum pernah' }} {{ $sv->terakhir_ip ? '· '.$sv->terakhir_ip : '' }}</span>
                    </span>
                    <span style="display:flex; gap:8px;">
                        <x-filament::button size="xs" color="gray" wire:click="aktifkanServer('{{ $sv->id }}', {{ $sv->is_active ? 'false' : 'true' }})">{{ $sv->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</x-filament::button>
                        <x-filament::button size="xs" color="danger" wire:click="hapusServer('{{ $sv->id }}')" wire:confirm="Hapus {{ $sv->nama }}? Tokennya tidak berlaku lagi.">Hapus</x-filament::button>
                    </span>
                </div>
            @empty
                <p style="font-size:14px; {{ $redup }}">Belum ada server lokal.</p>
            @endforelse
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Riwayat sinkron</x-slot>
        @forelse ($this->log as $l)
            <div style="{{ $baris }}">
                <span><b style="color: {{ $l->status === 'ok' ? '#22c55e' : '#ef4444' }}">{{ ucfirst($l->arah) }}</b>
                    <span style="{{ $redup }}">· {{ $l->jumlah }} data · {{ $l->durasi_ms }} ms {{ $l->pesan ? '· '.$l->pesan : '' }}</span></span>
                <span style="{{ $redup }} white-space:nowrap;">{{ $tgl($l->created_at) }}</span>
            </div>
        @empty
            <p style="font-size:14px; {{ $redup }}">Belum ada riwayat.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
