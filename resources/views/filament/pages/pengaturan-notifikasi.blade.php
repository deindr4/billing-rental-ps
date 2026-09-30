<x-filament-panels::page>
    <form wire:submit="simpan" class="space-y-6">
        {{ $this->form }}

        <div style="display: flex; flex-wrap: wrap; gap: .5rem;">
            <x-filament::button type="submit">Simpan</x-filament::button>
            <x-filament::button type="button" color="gray" wire:click="tesKirim('telegram')">Tes Telegram</x-filament::button>
            <x-filament::button type="button" color="gray" wire:click="tesKirim('whatsapp')">Tes WhatsApp</x-filament::button>
        </div>
    </form>

    {{-- ================= Koneksi WhatsApp ================= --}}
    <div @if ($wa['status'] === 'menunggu_scan') wire:poll.5s @else wire:poll.30s @endif>
    <x-filament::section heading="Koneksi WhatsApp">
        @switch($wa['status'])
            @case('terhubung')
                <p>Status: <strong style="color: #16a34a;">Terhubung</strong> {{ $wa['nomor'] ? '· +'.$wa['nomor'] : '' }}</p>
                <div style="display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem;">
                    @if ($tampilGrup)
                        <x-filament::button size="sm" color="gray" wire:click="sembunyikanGrup">Sembunyikan daftar grup</x-filament::button>
                    @else
                        <x-filament::button size="sm" color="gray" wire:click="muatGrupWa">Tampilkan daftar grup</x-filament::button>
                    @endif
                    <x-filament::button size="sm" color="danger" wire:click="logoutWa" wire:confirm="Logout WhatsApp dari billing?">Logout</x-filament::button>
                </div>

                @if ($tampilGrup)
                    <div style="margin-top: 1rem;">
                        <x-filament::input.wrapper>
                            <x-filament::input type="search" wire:model.live.debounce.300ms="cariGrup" placeholder="Cari nama grup..." />
                        </x-filament::input.wrapper>

                        @php $hasil = $this->grupTersaring(); @endphp
                        <p style="opacity: .7; font-size: .8em; margin: .5rem 0;">{{ count($hasil) }} dari {{ count($grupWa) }} grup</p>

                        <div style="max-height: 280px; overflow-y: auto;">
                            @forelse ($hasil as $g)
                                <div wire:key="grup-{{ $g['id'] }}" style="display: flex; justify-content: space-between; gap: 1rem; padding: .4rem 0; border-bottom: 1px solid rgba(127,127,127,.2);">
                                    <span>{{ $g['nama'] }} <span style="opacity: .5; font-size: .75em;">{{ $g['id'] }}</span></span>
                                    <x-filament::link tag="button" wire:click="pilihGrupWa('{{ $g['id'] }}')">Pilih</x-filament::link>
                                </div>
                            @empty
                                <p style="opacity: .7;">Grup tidak ditemukan.</p>
                            @endforelse
                        </div>
                    </div>
                @endif
                @break

            @case('menunggu_scan')
                <p>Buka WhatsApp di HP → <strong>Perangkat tertaut</strong> → <strong>Tautkan perangkat</strong>, lalu scan QR ini:</p>
                @if ($wa['qr'])
                    <img src="{{ $wa['qr'] }}" alt="QR WhatsApp" style="width: 240px; height: 240px; margin-top: .75rem; background: #fff; padding: 8px; border-radius: 8px;">
                @endif
                <p style="opacity: .7; font-size: .85em; margin-top: .5rem;">QR diperbarui otomatis.</p>
                @break

            @case('mati')
                <p>Service WhatsApp <strong>belum berjalan</strong>. Jalankan di folder <code>whatsapp-service</code>:</p>
                <pre style="margin-top: .5rem; padding: .75rem; border-radius: 6px; background: rgba(127,127,127,.12);">npm install
node --env-file=.env index.js</pre>
                @break

            @default
                <p>Status: {{ $wa['status'] }}. Menghubungkan...</p>
        @endswitch
    </x-filament::section>
    </div>

    {{-- ================= Riwayat ================= --}}
    <x-filament::section heading="Riwayat pengiriman">
        @forelse ($riwayat as $log)
            <div style="display: flex; justify-content: space-between; gap: 1rem; padding: .45rem 0; border-bottom: 1px solid rgba(127,127,127,.2); font-size: .9em;">
                <span>
                    {{ $log->created_at->format('d/m H:i') }} ·
                    {{ ucfirst($log->saluran) }} ·
                    {{ $log->jenis === 'tutup_kas' ? 'Tutup kas' : ucfirst($log->jenis) }}
                    {{ $log->cabang?->kode ? '· '.$log->cabang->kode : '' }}
                    @if ($log->pesan_error)
                        <br><span style="color: #dc2626;">{{ $log->pesan_error }}</span>
                    @endif
                </span>
                <strong style="color: {{ $log->status === 'terkirim' ? '#16a34a' : ($log->status === 'gagal' ? '#dc2626' : '#d97706') }};">
                    {{ ucfirst($log->status) }}{{ $log->percobaan > 1 ? ' ('.$log->percobaan.'x)' : '' }}
                </strong>
            </div>
        @empty
            <p style="opacity: .7;">Belum ada pengiriman.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
